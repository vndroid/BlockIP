<?php

namespace TypechoPlugin\BlockIP;

use Typecho\Config;
use Typecho\Db;
use Typecho\Request;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Exception as WidgetException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 禁止指定 IP 访问站点
 *
 * @package BlockIP
 * @author Vex
 * @version 1.4.0
 * @link https://github.com/vndroid/BlockIP
 */
class Plugin implements PluginInterface
{
    /**
     * 最低支持的 PHP 版本：8.2.0
     */
    private const MIN_PHP_VERSION_ID = 80200;

    /**
     * 默认采信的代理头（仅在配置了可信代理网段后生效）
     */
    private const DEFAULT_PROXY_HEADER = 'X-Forwarded-For';

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate(): string
    {
        if (!self::supportsPhpVersion(PHP_VERSION_ID)) {
            throw new PluginException(_t('BlockIP 要求 PHP 8.2.0 或更高版本，当前版本为 %s', PHP_VERSION));
        }

        // 挂在 index.php 的 begin 上: 位于 Router::dispatch() 之前, 覆盖前台全部路由
        // (页面、feed、/action/* 的评论与引用提交、xmlrpc、附件等)。
        // 后台 admin/*.php 走独立入口, 不经过此钩子, 因此天然不受影响。
        \Typecho\Plugin::factory('index.php')->begin = [self::class, 'blockIP'];

        return _t("插件已启用");
    }

    /**
     * 判断 PHP 运行时是否满足最低版本要求
     */
    private static function supportsPhpVersion(int $versionId): bool
    {
        return $versionId >= self::MIN_PHP_VERSION_ID;
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate(): string
    {
        return _t("插件已禁用");
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     */
    public static function config(Form $form): void
    {
        $ips = new Textarea('ips', null, null, _t('IP 黑名单列表'), _t(
            '一行一个，支持以下规则：<br>'
            . '192.168.1.1　　　单个地址<br>'
            . '210.10.2.1-20　　区间（任意一段均可，闭区间）<br>'
            . '222.34.4.*　　　 通配（等价于 0-255）<br>'
            . '192.168.1.0/24　 CIDR<br>'
            . '2001:db8::1　　　 IPv6 单个地址<br>'
            . '2001:db8::/32　　IPv6 前缀<br>'
            . '区间与通配只适用于 IPv4；IPv6 请用单个地址或前缀。空行会被忽略。'
        ));
        $ips->addRule([self::class, 'checkRules'], _t('IP 黑名单列表中存在无法识别的规则'));
        $form->addInput($ips);

        $contactMail = new Text('contactMail', null, null, _t('联系邮箱'), _t(
            '显示在拦截页面上，供被误封的访客申诉。留空则自动取 uid 为 1 的用户邮箱。'
        ));
        $contactMail->addRule([self::class, 'checkMail'], _t('联系邮箱格式不正确'));
        $form->addInput($contactMail);

        $trustedProxies = new Textarea('trustedProxies', null, null, _t('可信代理网段'), _t(
            '默认留空：只按 TCP 连接地址（REMOTE_ADDR）判定，代理头一律不采信，无法伪造。<br>'
            . '站点若在 CDN / nginx 反代后面，在此填入反代自身的地址或网段（语法同上，一行一个）；'
            . '只有当 REMOTE_ADDR 命中此列表时，才会去解析下方的代理头，并取其中最右侧的非可信地址作为访客 IP。<br>'
            . '<strong>不要在此填入访客网段</strong>，否则等同于允许任意伪造。'
        ));
        $trustedProxies->addRule([self::class, 'checkRules'], _t('可信代理网段中存在无法识别的规则'));
        $form->addInput($trustedProxies);

        $proxyHeader = new Text('proxyHeader', null, self::DEFAULT_PROXY_HEADER, _t('代理头名称'), _t(
            '仅在上方「可信代理网段」非空时生效。常见取值：X-Forwarded-For、X-Real-IP、CF-Connecting-IP。留空则使用 X-Forwarded-For。'
        ));
        $form->addInput($proxyHeader);
    }

    /**
     * 检查每一行是否都是可识别的规则
     *
     * @param string|null $text
     * @return bool
     */
    public static function checkRules(?string $text): bool
    {
        if (empty($text)) {
            return true;
        }

        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (self::parseRule($line) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查联系邮箱格式
     *
     * @param string|null $text
     * @return bool
     */
    public static function checkMail(?string $text): bool
    {
        $text = trim((string) $text);

        return $text === '' || filter_var($text, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function personalConfig(Form $form)
    {}

    /**
     * 屏蔽 IP 访问
     *
     * @throws WidgetException
     */
    public static function blockIP(): void
    {
        // 放行已登录的管理员与编辑, 使后台发起的请求(评论审核、附件上传、xmlrpc 等,
        // 这些都经由 index.php 而非 admin 入口)与后台页面本身的行为保持一致,
        // 同时避免误封自己的 IP 之后把自己锁在门外。
        try {
            if (User::alloc()->pass('editor', true)) {
                return;
            }
        } catch (\Throwable $e) {
            // 登录态判定失败时按未登录处理
        }

        if (!self::checkIP()) {
            return;
        }

        // 必须是 Widget\Exception: Typecho\Common::error() 只对该类型透传 message,
        // 其余类型一律覆盖成 "Server Error"; code 决定 HTTP 状态码, 0 会退化成 500。
        throw new WidgetException(self::buildMessage(), 403);
    }

    /**
     * 组装拦截页面上的提示语
     *
     * Common::error() 会对 message 做 nl2br() 后直接输出, 不做转义, 故此处自行转义
     */
    private static function buildMessage(): string
    {
        $message = '抱歉，当前 IP 段无法访问，如有问题，请';
        $mail = self::getContactMail();

        if ($mail === null) {
            return $message . '联系管理员。';
        }

        return $message . '<a href="mailto:' . htmlspecialchars($mail, ENT_QUOTES) . '">联系管理员</a>。';
    }

    /**
     * 取得联系邮箱: 优先用配置项, 留空则回落到 uid 为 1 的用户
     *
     * 只在确定要拦截时才会走到这里, 每个请求至多一次, 无需缓存
     */
    private static function getContactMail(): ?string
    {
        $configured = trim((string) (self::getConfig()->contactMail ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('mail')->from('table.users')->where('uid = ?', 1)->limit(1)
            );
            $mail = isset($row['mail']) ? trim((string) $row['mail']) : '';
        } catch (\Throwable $e) {
            // 取不到就不显示链接, 不能因此把整个拦截流程搞成 500
            $mail = '';
        }

        return $mail === '' ? null : $mail;
    }

    /**
     * IP 检查函数
     */
    private static function checkIP(): bool
    {
        $ip = self::getClientIp();

        if ($ip === null) {
            return false;
        }

        return self::matchIp($ip, self::parseRules(self::getConfig()->ips));
    }

    /**
     * 取得访客 IP
     *
     * 默认只信任 REMOTE_ADDR。只有当 REMOTE_ADDR 命中「可信代理网段」时，
     * 才解析代理头，并从右往左取第一个非可信地址——右端是可信代理写入的，
     * 越往左越可能是客户端自己伪造的内容。
     */
    private static function getClientIp(): ?string
    {
        $request = Request::getInstance();
        $remote = self::sanitizeIp($request->getServer('REMOTE_ADDR'));

        if ($remote === null) {
            return null;
        }

        $config = self::getConfig();
        $trusted = self::parseRules($config->trustedProxies);

        if (empty($trusted) || !self::matchIp($remote, $trusted)) {
            return $remote;
        }

        $header = trim((string) ($config->proxyHeader ?? ''));
        $header = $header === '' ? self::DEFAULT_PROXY_HEADER : $header;
        $raw = trim((string) $request->getHeader($header, ''));

        if ($raw === '') {
            return $remote;
        }

        $chain = explode(',', $raw);

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = self::sanitizeIp($chain[$i]);

            if ($candidate === null) {
                // 链上出现无法解析的内容，不再继续向左采信
                return $remote;
            }

            if (self::matchIp($candidate, $trusted)) {
                continue;
            }

            return $candidate;
        }

        return $remote;
    }

    /**
     * 读取插件配置，未保存过配置时返回空配置而不是抛异常
     */
    private static function getConfig(): Config
    {
        try {
            return Options::alloc()->plugin('BlockIP');
        } catch (\Throwable $e) {
            return new Config([]);
        }
    }

    /**
     * 校验并规范化一个 IP 字符串
     *
     * IPv4-mapped 地址(::ffff:192.0.2.1)会被还原成 IPv4 写法: 双栈监听的机器上
     * REMOTE_ADDR 常常是这种形式, 不归一化的话现有的 IPv4 规则会静默失效。
     */
    private static function sanitizeIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);

        if ($ip === false) {
            return null;
        }

        return self::unmapIpv4($ip);
    }

    /**
     * 把 IPv4-mapped / IPv4-compatible 的 IPv6 地址还原为 IPv4 写法, 其余原样返回
     */
    private static function unmapIpv4(string $ip): string
    {
        if (!str_contains($ip, ':')) {
            return $ip;
        }

        $packed = @inet_pton($ip);

        if ($packed !== false && self::isIpv4Mapped($packed)) {
            $unmapped = @inet_ntop(substr($packed, 12));

            if ($unmapped !== false) {
                return $unmapped;
            }
        }

        return $ip;
    }

    /**
     * 判断 16 字节地址是否落在 ::ffff:0:0/96（IPv4-mapped）内
     */
    private static function isIpv4Mapped(string $packed): bool
    {
        return strlen($packed) === 16
            && substr($packed, 0, 10) === str_repeat("\x00", 10)
            && substr($packed, 10, 2) === "\xff\xff";
    }

    /**
     * 把多行规则文本解析为规则表，无法识别的行直接跳过
     *
     * @return array<int, array>
     */
    private static function parseRules(?string $text): array
    {
        $rules = [];

        if (empty($text)) {
            return $rules;
        }

        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $rule = self::parseRule(trim($line));

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * 把单条规则解析为可比较的结构，非法规则返回 null
     *
     * IPv4 CIDR   → ['range', 起始整数, 结束整数]
     * IPv4 点分式 → ['octets', [[下限, 上限] x4]]
     * IPv6        → ['prefix', 网络地址二进制, 前缀位数]  单个地址即 /128
     */
    private static function parseRule(string $rule): ?array
    {
        if ($rule === '') {
            return null;
        }

        // 含冒号即按 IPv6 处理: 区间与通配语法只适用于 IPv4
        if (str_contains($rule, ':')) {
            return self::parseIpv6Rule($rule);
        }

        if (str_contains($rule, '/')) {
            return self::parseCidr($rule);
        }

        $parts = explode('.', $rule);

        if (count($parts) !== 4) {
            return null;
        }

        $octets = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '*') {
                $octets[] = [0, 255];
                continue;
            }

            if (str_contains($part, '-')) {
                $bounds = explode('-', $part);

                if (count($bounds) !== 2) {
                    return null;
                }

                $low = self::parseOctet($bounds[0]);
                $high = self::parseOctet($bounds[1]);

                if ($low === null || $high === null || $low > $high) {
                    return null;
                }

                $octets[] = [$low, $high];
                continue;
            }

            $value = self::parseOctet($part);

            if ($value === null) {
                return null;
            }

            $octets[] = [$value, $value];
        }

        return ['octets', $octets];
    }

    /**
     * 解析 IPv6 规则：单个地址或 CIDR 前缀
     *
     * 统一转成 inet_pton 的 16 字节二进制, 因此 2001:db8::1 与 2001:0db8:0000::0001
     * 这类等价写法自动视为同一地址, 不需要额外做文本归一化。
     */
    private static function parseIpv6Rule(string $rule): ?array
    {
        $bits = 128;

        if (str_contains($rule, '/')) {
            $segments = explode('/', $rule);

            if (count($segments) !== 2) {
                return null;
            }

            [$rule, $prefix] = $segments;
            $prefix = trim($prefix);

            if (!preg_match('/^\d{1,3}$/', $prefix)) {
                return null;
            }

            $bits = (int) $prefix;

            if ($bits > 128) {
                return null;
            }
        }

        $rule = trim($rule);

        // 走 filter_var 而不是直接 inet_pton: 后者会接受一些畸形写法
        if (filter_var($rule, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = @inet_pton($rule);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        // ::ffff:a.b.c.d 这一段等价于 IPv4, 必须折回 IPv4 规则:
        // 访客地址在 sanitizeIp() 里已被还原成 IPv4, 留成 IPv6 前缀就永远匹配不上。
        if (self::isIpv4Mapped($packed)) {
            // /96 以下的前缀跨出了映射区间, 语义上说不通, 直接判为非法
            if ($bits < 96) {
                return null;
            }

            $dotted = @inet_ntop(substr($packed, 12));

            if ($dotted === false) {
                return null;
            }

            return self::parseCidr($dotted . '/' . ($bits - 96));
        }

        return ['prefix', $packed, $bits];
    }

    /**
     * 解析 CIDR 规则
     */
    private static function parseCidr(string $rule): ?array
    {
        $segments = explode('/', $rule);

        if (count($segments) !== 2) {
            return null;
        }

        [$network, $bits] = $segments;
        $bits = trim($bits);

        if (!preg_match('/^\d{1,2}$/', $bits)) {
            return null;
        }

        $bits = (int) $bits;

        if ($bits > 32) {
            return null;
        }

        $base = self::ipToLong(trim($network));

        if ($base === null) {
            return null;
        }

        $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
        $start = $base & $mask;

        return ['range', $start, $start | (~$mask & 0xFFFFFFFF)];
    }

    /**
     * 解析单个八位段，非法返回 null
     */
    private static function parseOctet(string $value): ?int
    {
        $value = trim($value);

        if (!preg_match('/^\d{1,3}$/', $value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 255 ? null : $value;
    }

    /**
     * 点分四段字符串转 32 位整数，非法返回 null
     *
     * 不使用 ip2long()：其在 32 位平台上会返回负数，且对 "1.2.3" 之类的写法过于宽容
     */
    private static function ipToLong(string $ip): ?int
    {
        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return null;
        }

        $long = 0;

        foreach ($parts as $part) {
            $octet = self::parseOctet($part);

            if ($octet === null) {
                return null;
            }

            $long = ($long << 8) | $octet;
        }

        return $long;
    }

    /**
     * 判断 IP 是否命中规则表
     *
     * IPv4 与 IPv6 规则互不串味: IPv4 地址只与 range/octets 比, IPv6 地址只与 prefix 比。
     * (::ffff:1.2.3.4 已在 sanitizeIp() 里还原成 IPv4, 到这里就是普通 IPv4 地址。)
     *
     * @param array<int, array> $rules
     */
    private static function matchIp(string $ip, array $rules): bool
    {
        if (empty($rules)) {
            return false;
        }

        if (str_contains($ip, ':')) {
            return self::matchIpv6($ip, $rules);
        }

        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return false;
        }

        $octets = [];
        $long = 0;

        foreach ($parts as $part) {
            $octet = self::parseOctet($part);

            if ($octet === null) {
                return false;
            }

            $octets[] = $octet;
            $long = ($long << 8) | $octet;
        }

        foreach ($rules as $rule) {
            if ($rule[0] === 'prefix') {
                continue;
            }

            if ($rule[0] === 'range') {
                if ($long >= $rule[1] && $long <= $rule[2]) {
                    return true;
                }

                continue;
            }

            $hit = true;

            foreach ($rule[1] as $index => [$low, $high]) {
                if ($octets[$index] < $low || $octets[$index] > $high) {
                    $hit = false;
                    break;
                }
            }

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 IPv6 地址是否命中规则表中的前缀规则
     *
     * @param array<int, array> $rules
     */
    private static function matchIpv6(string $ip, array $rules): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($rule[0] !== 'prefix') {
                continue;
            }

            if (self::prefixMatches($packed, $rule[1], $rule[2])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 比较两个 16 字节地址的前 $bits 位是否相同
     */
    private static function prefixMatches(string $address, string $network, int $bits): bool
    {
        $wholeBytes = $bits >> 3;

        if ($wholeBytes > 0 && strncmp($address, $network, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits & 7;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
