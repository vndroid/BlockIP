<?php

/**
 * BlockIP 回归测试
 *
 * 零依赖，直接运行：
 *
 *     php tests/run.php
 *
 * 退出码 0 表示全部通过。测试通过反射调用插件的私有方法，
 * 因此不需要为了可测性把内部实现暴露成 public。
 */

require __DIR__ . '/stubs.php';
require dirname(__DIR__) . '/Plugin.php';

use TypechoPlugin\BlockIP\Plugin;

final class Runner
{
    private int $passed = 0;
    private int $failed = 0;

    public function group(string $title): void
    {
        echo "\n\033[1m" . $title . "\033[0m\n";
    }

    /**
     * @param mixed $actual
     * @param mixed $expected
     */
    public function assert(string $label, $actual, $expected): void
    {
        if ($actual === $expected) {
            $this->passed++;
            printf("  \033[32m✓\033[0m %s\n", $label);
            return;
        }

        $this->failed++;
        printf(
            "  \033[31m✗ %s\033[0m\n      实际: %s\n      期望: %s\n",
            $label,
            var_export($actual, true),
            var_export($expected, true)
        );
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('─', 56) . "\n";

        if ($this->failed === 0) {
            printf("\033[32m全部通过\033[0m：%d 项\n", $this->passed);
            return 0;
        }

        printf("\033[31m失败 %d 项\033[0m，通过 %d 项\n", $this->failed, $this->passed);
        return 1;
    }
}

/**
 * 调用插件的私有静态方法
 *
 * @param mixed ...$args
 * @return mixed
 */
function invoke(string $method, ...$args)
{
    $reflection = new ReflectionMethod(Plugin::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(null, ...$args);
}

/**
 * 设置插件配置
 *
 * @param array<string, string|null> $config
 */
function config(array $config): void
{
    Widget\Options::$pluginConfig = new Typecho\Config($config);
}

/**
 * 伪造一个请求
 *
 * @param array<string, string> $headers
 */
function request(string $remoteAddr, array $headers = []): void
{
    Typecho\Request::fake($remoteAddr, $headers);
}

/**
 * 走完整的 blockIP() 流程，返回是否被拦截
 */
function blocked(): bool
{
    try {
        Plugin::blockIP();
    } catch (Typecho\Widget\Exception $e) {
        return $e->getCode() === 403;
    }

    return false;
}

/**
 * 走完整的 blockIP() 流程，返回抛出的异常
 */
function blockException(): ?Throwable
{
    try {
        Plugin::blockIP();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

$t = new Runner();

// ---------------------------------------------------------------------------
$t->group('PHP 最低版本');

$t->assert('PHP 8.1 不受支持', invoke('supportsPhpVersion', 80199), false);
$t->assert('PHP 8.2 受支持', invoke('supportsPhpVersion', 80200), true);
$t->assert('PHP 8.4 受支持', invoke('supportsPhpVersion', 80400), true);

// 默认状态：未登录访客，站长邮箱可查
Widget\User::$group = null;
Typecho\Db::$users = [1 => ['mail' => 'admin@example.com']];
Typecho\Db::$shouldFail = false;

// ---------------------------------------------------------------------------
$t->group('规则匹配必须是精确的，不能退化成子串匹配');
// 旧实现把规则拼成未锚定的正则，/1\.2\.3\.4/ 会命中 1.2.3.40 与 11.2.3.4

config(['ips' => "1.2.3.4\n192.168.1.1"]);

foreach ([
    ['1.2.3.4', true],
    ['1.2.3.40', false],
    ['1.2.3.41', false],
    ['11.2.3.4', false],
    ['192.168.1.1', true],
    ['192.168.1.10', false],
    ['192.168.1.11', false],
] as [$ip, $expected]) {
    request($ip);
    $t->assert(sprintf('%-14s → %s', $ip, $expected ? '拦截' : '放行'), blocked(), $expected);
}

// ---------------------------------------------------------------------------
$t->group('区间规则只能命中区间内的地址');
// 旧实现生成的是字符类 [1|2|...|20]，| 在 [] 内是字面量，实际封掉了整个 /24

config(['ips' => '210.10.2.1-20']);

foreach ([
    ['210.10.2.0', false],
    ['210.10.2.1', true],
    ['210.10.2.20', true],
    ['210.10.2.21', false],
    ['210.10.2.99', false],
    ['210.10.2.200', false],
    ['210.10.2.255', false],
] as [$ip, $expected]) {
    request($ip);
    $t->assert(sprintf('%-14s → %s', $ip, $expected ? '拦截' : '放行'), blocked(), $expected);
}

// ---------------------------------------------------------------------------
$t->group('通配、多段区间与 CIDR');

config(['ips' => "222.34.4.*\n10.0.*.*\n172.16.5-6.10\n192.168.1.0/24\n203.0.113.128/25"]);

foreach ([
    ['222.34.4.0', true],
    ['222.34.4.255', true],
    ['222.34.5.1', false],
    ['10.0.7.9', true],
    ['10.1.7.9', false],
    ['172.16.5.10', true],
    ['172.16.6.10', true],
    ['172.16.7.10', false],
    ['172.16.5.11', false],
    ['192.168.1.0', true],
    ['192.168.1.255', true],
    ['192.168.2.1', false],
    ['203.0.113.127', false],
    ['203.0.113.128', true],
    ['203.0.113.255', true],
] as [$ip, $expected]) {
    request($ip);
    $t->assert(sprintf('%-14s → %s', $ip, $expected ? '拦截' : '放行'), blocked(), $expected);
}

// ---------------------------------------------------------------------------
$t->group('IPv6 单个地址与前缀');

config(['ips' => "2001:db8::1\n2001:db8:dead::/48\nfe80::/10"]);

foreach ([
    ['2001:db8::1', true],
    ['2001:db8::2', false],
    ['2001:db8:dead::1', true],
    ['2001:db8:dead:beef::1', true],
    ['2001:db8:deae::1', false],
    ['fe80::1', true],
    ['febf:ffff::1', true],
    ['fec0::1', false],
    ['2001:db9::1', false],
] as [$ip, $expected]) {
    request($ip);
    $t->assert(sprintf('%-22s → %s', $ip, $expected ? '拦截' : '放行'), blocked(), $expected);
}

// ---------------------------------------------------------------------------
$t->group('IPv6 等价写法自动归一');
// 规则与访客地址都过 inet_pton，缩写、前导零、大小写都不影响判定

config(['ips' => '2001:0db8:0000:0000:0000:0000:0000:0001']);
request('2001:db8::1');
$t->assert('完整写法的规则命中缩写地址', blocked(), true);

config(['ips' => '2001:db8::1']);
request('2001:0DB8:0000:0000:0000:0000:0000:0001');
$t->assert('缩写规则命中完整写法的地址', blocked(), true);

config(['ips' => '2001:DB8::/32']);
request('2001:db8:1::1');
$t->assert('规则大小写不敏感', blocked(), true);

config(['ips' => '::1']);
request('0:0:0:0:0:0:0:1');
$t->assert('回环地址两种写法等价', blocked(), true);

config(['ips' => '::/0']);
request('2001:db8::1');
$t->assert('::/0 匹配全部 IPv6', blocked(), true);
request('8.8.8.8');
$t->assert('::/0 不波及 IPv4', blocked(), false);

config(['ips' => '2001:db8::1/128']);
request('2001:db8::1');
$t->assert('显式 /128 等同单个地址', blocked(), true);
request('2001:db8::2');
$t->assert('/128 不多封一个', blocked(), false);

// ---------------------------------------------------------------------------
$t->group('IPv4 与 IPv6 规则互不串味');

config(['ips' => '2001:db8::/32']);
request('1.2.3.4');
$t->assert('IPv6 规则不封 IPv4 访客', blocked(), false);

config(['ips' => "1.2.3.4\n10.0.0.0/8\n192.168.*.*"]);
request('2001:db8::1');
$t->assert('IPv4 规则不封 IPv6 访客', blocked(), false);

config(['ips' => "1.2.3.4\n2001:db8::/32"]);
request('1.2.3.4');
$t->assert('混合列表：IPv4 照常命中', blocked(), true);
request('2001:db8::1');
$t->assert('混合列表：IPv6 照常命中', blocked(), true);
request('5.6.7.8');
$t->assert('混合列表：不相干的 IPv4 放行', blocked(), false);
request('2001:db9::1');
$t->assert('混合列表：不相干的 IPv6 放行', blocked(), false);

// ---------------------------------------------------------------------------
$t->group('IPv4-mapped 地址归一成 IPv4');
// 双栈监听的机器上 REMOTE_ADDR 常常是 ::ffff:192.0.2.1 这种形式

config(['ips' => '192.0.2.0/24']);
request('::ffff:192.0.2.1');
$t->assert('v4-mapped 访客命中 IPv4 网段规则', blocked(), true);

config(['ips' => '192.0.2.1']);
request('::ffff:192.0.2.1');
$t->assert('v4-mapped 访客命中 IPv4 单点规则', blocked(), true);

config(['ips' => '192.0.2.5']);
request('::ffff:192.0.2.1');
$t->assert('v4-mapped 访客不会被误封', blocked(), false);

config(['ips' => '::ffff:192.0.2.1']);
request('192.0.2.1');
$t->assert('写成 v4-mapped 的规则命中 IPv4 访客', blocked(), true);
request('::ffff:192.0.2.1');
$t->assert('写成 v4-mapped 的规则命中 v4-mapped 访客', blocked(), true);
request('192.0.2.2');
$t->assert('写成 v4-mapped 的规则不会多封', blocked(), false);

config(['ips' => '::ffff:192.0.2.0/120']);
request('192.0.2.77');
$t->assert('v4-mapped 前缀等价于 /24', blocked(), true);
request('192.0.3.1');
$t->assert('v4-mapped 前缀不越界', blocked(), false);

// ---------------------------------------------------------------------------
$t->group('IPv6 也可以作为可信代理');

config(['ips' => '1.2.3.4', 'trustedProxies' => '::1']);
request('::1', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
$t->assert('IPv6 可信代理转发的客户端被封', blocked(), true);

request('2001:db8::1', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
$t->assert('不在可信代理内的 IPv6 忽略代理头', blocked(), false);

config(['ips' => '2001:db8::1', 'trustedProxies' => '10.0.0.0/8']);
request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '2001:db8::1']);
$t->assert('代理头里的 IPv6 客户端被封', blocked(), true);

// ---------------------------------------------------------------------------
$t->group('非法规则在保存时被拒绝，而不是静默失效');

foreach ([
    '192.168.',
    '192.168.1',
    '10.0.0.20-1',
    '10.0.0.1-',
    '1.2.3.999',
    '1.2.3.4/33',
    '1.2.3.4/2a',
    '192.168.1.0/',
    '.*',
    'abc',
    '2001:db8::/129',
    '2001:db8::/xx',
    '2001:db8::/',
    'gggg::1',
    '2001:db8:::1',
    '2001:db8::1-5',
    '2001:db8::*',
    '::ffff:192.0.2.0/64',
] as $rule) {
    $t->assert(sprintf('拒绝 %-14s', $rule), Plugin::checkRules($rule), false);
}

foreach ([
    '1.2.3.4',
    "1.2.3.4\n10.0.0.0/8\n172.16.*.*",
    '10.0.0.1-20',
    '2001:db8::1',
    '2001:db8::/32',
    '::1',
    '::/0',
    "1.2.3.4\n2001:db8::/32\nfe80::/10",
] as $rules) {
    $t->assert('接受合法规则 ' . str_replace("\n", ' / ', $rules), Plugin::checkRules($rules), true);
}

config(['ips' => '10.0.0.20-1']);
request('10.0.0.5');
$t->assert('逆序区间不会误伤任何地址', blocked(), false);

// ---------------------------------------------------------------------------
$t->group('空行与空白一律忽略，不构成保存失败');
// 旧版本有一条 checkNoEmptyLines 规则，textarea 末尾多一个换行就存不进去

$t->assert('接受末尾换行', Plugin::checkRules("1.2.3.4\n"), true);
$t->assert('接受空行夹在中间', Plugin::checkRules("1.2.3.4\n\n5.6.7.8"), true);
$t->assert('接受 CRLF', Plugin::checkRules("1.2.3.4\r\n5.6.7.8\r\n"), true);
$t->assert('接受纯空白行', Plugin::checkRules("1.2.3.4\n   \n"), true);
$t->assert('接受空配置', Plugin::checkRules(''), true);
$t->assert('接受 null', Plugin::checkRules(null), true);
$t->assert('checkNoEmptyLines 已移除', method_exists(Plugin::class, 'checkNoEmptyLines'), false);

config(['ips' => "1.2.3.4\n\n  \n5.6.7.8\n"]);
request('5.6.7.8');
$t->assert('夹杂空行的列表照常生效', blocked(), true);
request('9.9.9.9');
$t->assert('夹杂空行的列表不会误封', blocked(), false);

// ---------------------------------------------------------------------------
$t->group('代理头不可伪造');
// Typecho 的 Request::getIp() 默认优先读 X-Forwarded-For，且不校验来源，插件不能直接用

config(['ips' => '1.2.3.4']);
request('9.9.9.9', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
$t->assert('未配可信代理时，伪造 XFF 不会导致误封', blocked(), false);

config(['ips' => '9.9.9.9']);
request('9.9.9.9', ['HTTP_X_FORWARDED_FOR' => '8.8.8.8']);
$t->assert('未配可信代理时，伪造 XFF 不能绕过封禁', blocked(), true);

request('9.9.9.9', ['HTTP_CLIENT_IP' => '8.8.8.8']);
$t->assert('Client-Ip 同样无效', blocked(), true);

// ---------------------------------------------------------------------------
$t->group('配置了可信代理之后，取链上最右侧的非可信地址');

config(['ips' => '1.2.3.4', 'trustedProxies' => '10.0.0.0/8']);
request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
$t->assert('经可信代理转发的真实客户端被封', blocked(), true);

request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.9']);
$t->assert('链尾是可信代理时继续向左取', blocked(), true);

request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 8.8.8.8']);
$t->assert('左侧伪造项打不过右侧真实项', blocked(), false);

config(['ips' => '8.8.8.8', 'trustedProxies' => '10.0.0.0/8']);
request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 8.8.8.8']);
$t->assert('被封者在左侧塞地址也绕不过', blocked(), true);

request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => 'garbage, 8.8.8.8']);
$t->assert('垃圾项在右侧真实项之左，不影响判定', blocked(), true);

config(['ips' => '1.2.3.4', 'trustedProxies' => '10.0.0.0/8']);
request('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, garbage']);
$t->assert('链尾是垃圾则回退 REMOTE_ADDR，不向左采信', blocked(), false);

request('7.7.7.7', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
$t->assert('REMOTE_ADDR 不在可信代理内则忽略代理头', blocked(), false);

config(['ips' => '1.2.3.4', 'trustedProxies' => '10.0.0.0/8', 'proxyHeader' => 'CF-Connecting-IP']);
request('10.0.0.5', ['HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9']);
$t->assert('自定义代理头生效，XFF 被忽略', blocked(), true);

// ---------------------------------------------------------------------------
$t->group('异常类型与状态码');
// Typecho\Common::error() 只对 Widget\Exception 透传 message，
// 其余类型一律覆盖成 Server Error；code 为 0 会退化成 500

config(['ips' => '1.2.3.4']);
request('1.2.3.4');
$exception = blockException();

$t->assert('抛出 Typecho\Widget\Exception', $exception instanceof Typecho\Widget\Exception, true);
$t->assert('不是 Typecho\Plugin\Exception', $exception instanceof Typecho\Plugin\Exception, false);
$t->assert('状态码为 403', $exception ? $exception->getCode() : null, 403);
$t->assert('文案为「联系管理员」', $exception && str_contains($exception->getMessage(), '联系管理员'), true);

// ---------------------------------------------------------------------------
$t->group('联系邮箱');

$t->assert(
    '留空时回落到 uid=1 的邮箱',
    $exception && str_contains($exception->getMessage(), 'mailto:admin@example.com'),
    true
);

config(['ips' => '1.2.3.4', 'contactMail' => 'abuse@example.org']);
request('1.2.3.4');
$exception = blockException();
$t->assert(
    '配置项优先于 uid=1',
    $exception && str_contains($exception->getMessage(), 'mailto:abuse@example.org'),
    true
);

config(['ips' => '1.2.3.4', 'contactMail' => 'a"onmouseover=alert(1) x@example.org']);
request('1.2.3.4');
$exception = blockException();
$t->assert(
    '邮箱做了 HTML 属性转义',
    $exception && !str_contains($exception->getMessage(), '"onmouseover'),
    true
);

Typecho\Db::$users = [];
config(['ips' => '1.2.3.4']);
request('1.2.3.4');
$exception = blockException();
$t->assert('查不到邮箱时不输出空 mailto', $exception && !str_contains($exception->getMessage(), 'mailto:'), true);
$t->assert('查不到邮箱时仍给出提示语', $exception && str_contains($exception->getMessage(), '联系管理员'), true);

Typecho\Db::$shouldFail = true;
request('1.2.3.4');
$exception = blockException();
$t->assert('数据库故障不会放大成 500', $exception instanceof Typecho\Widget\Exception, true);

Typecho\Db::$shouldFail = false;
Typecho\Db::$users = [1 => ['mail' => 'admin@example.com']];

$t->assert('checkMail 接受空值', Plugin::checkMail(''), true);
$t->assert('checkMail 接受合法邮箱', Plugin::checkMail('a@b.com'), true);
$t->assert('checkMail 拒绝非法邮箱', Plugin::checkMail('not-a-mail'), false);

// ---------------------------------------------------------------------------
$t->group('钩子挂载点');
// index.php:begin 位于 Router::dispatch() 之前，覆盖前台全部路由；
// 后台 admin/*.php 是独立入口，天然不受影响

Typecho\Plugin::reset();
Plugin::activate();

$t->assert('挂在 index.php:begin', isset(Typecho\Plugin::$hooks['index.php:begin']), true);
$t->assert('不再挂 Archive::beforeRender', isset(Typecho\Plugin::$hooks['Widget\Archive:beforeRender']), false);
$t->assert('只注册一个钩子', count(Typecho\Plugin::$hooks), 1);

// ---------------------------------------------------------------------------
$t->group('管理员与编辑豁免');
// 后台发起的请求（评论审核、附件上传、xmlrpc）走的也是 index.php，
// 不豁免就会出现「仪表盘能开、审评论 403」

config(['ips' => '1.2.3.4']);

foreach ([
    ['administrator', false],
    ['editor', false],
    ['contributor', true],
    ['subscriber', true],
    [null, true],
] as [$group, $expected]) {
    Widget\User::$group = $group;
    request('1.2.3.4');
    $t->assert(
        sprintf('被封 IP + %-13s → %s', $group ?? '未登录', $expected ? '拦截' : '放行'),
        blocked(),
        $expected
    );
}

Widget\User::$group = 'administrator';
request('9.9.9.9');
$t->assert('未被封 IP + 管理员 → 放行', blocked(), false);

Widget\User::$group = null;
request('9.9.9.9');
$t->assert('未被封 IP + 未登录 → 放行', blocked(), false);

// ---------------------------------------------------------------------------
$t->group('边界情况');

config(['ips' => '1.2.3.4']);

request('::1');
$t->assert('IPv6 访客不命中 IPv4 规则', blocked(), false);

request('fe80::1%eth0');
$t->assert('带 zone id 的地址不被采信', blocked(), false);

request('');
$t->assert('REMOTE_ADDR 缺失则放行', blocked(), false);

request('not-an-ip');
$t->assert('REMOTE_ADDR 非法则放行', blocked(), false);

config(['ips' => null]);
request('1.2.3.4');
$t->assert('空黑名单放行', blocked(), false);

Widget\Options::$pluginConfig = null;
request('1.2.3.4');
$t->assert('插件配置未保存过时不抛异常', blocked(), false);

config(['ips' => '0.0.0.0/0']);
request('8.8.8.8');
$t->assert('/0 匹配全部地址', blocked(), true);

config(['ips' => '255.255.255.255']);
request('255.255.255.255');
$t->assert('255.255.255.255 没有 32 位溢出问题', blocked(), true);

exit($t->summary());
