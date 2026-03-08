<?php

namespace TypechoPlugin\BlockIP;

use Typecho\Request;
use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Widget\Archive;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 禁止指定 IP 访问站点
 *
 * @package BlockIP
 * @author Kokororin
 * @version 1.1.0
 * @link https://github.com/vndroid/BlockIP
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate(): string
    {
        \Typecho\Plugin::factory(Archive::class)->beforeRender = [self::class, 'blockIP'];

        return _t("插件已启用");
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
        $ips = new Textarea('ips', null, null, _t('IP 黑名单列表'), _t('一行一个，支持以下规则：<br>192.168.1.1<br>210.10.2.1-20<br>222.34.4.*<br>注意：列表中请勿存在空行！！'));
        $ips->addRule([self::class, 'checkNoEmptyLines'], _t('IP 黑名单列表中不能包含空行'));
        $form->addInput($ips);
    }

    /**
     * 检查是否有空行
     *
     * @param string|null $text
     * @return bool
     */
    public static function checkNoEmptyLines(?string $text): bool
    {
        if (empty($text)) {
            return true;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        foreach ($lines as $line) {
            if (trim($line) === '') {
                return false;
            }
        }
        return true;
    }
    public static function personalConfig(Form $form)
    {}

    /**
     * 屏蔽 IP 访问
     *
     * @throws Exception
     */
    public static function blockIP(): void
    {
        //debug
        //print_r(self::getAllBlockIP());
        if (self::checkIP()) {
            $user = User::alloc();
            throw new Exception('抱歉，当前 IP 段无法访问，如有问题，请<a href="mailto:' . $user->mail . '">联系站长</a>。');
        }
    }

    /**
     * IP 检查函数
     *
     * @throws Exception
     */
    private static function checkIP(): bool
    {
        $flag = false;
        $request = Request::getInstance();
        $ip = trim($request->getIp());
        $ipTable = self::getAllBlockIP();

        if ($ipTable) {
            foreach ($ipTable as $value) {
                if (preg_match("{$value}", $ip)) {
                    $flag = true;
                    break;
                }
            }
        }

        return $flag;
    }

    private static function makePregIP($str): string
    {
        $preg_limit = '';

        if (str_contains($str, "-")) {
            $aIP = explode(".", $str);

            foreach ($aIP as $key => $value) {
                if (!str_contains($value, "-")) {
                    if ($key == 0) {
                        $preg_limit .= self::makePregIP($value);
                    } else {
                        $preg_limit .= '.' . self::makePregIP($value);
                    }
                } else {
                    $aipNum = explode("-", $value);
                    $preg = '';

                    for ($i = $aipNum[0]; $i <= $aipNum[1]; $i++) {
                        $preg .= $preg ? "|" . $i : "[" . $i;
                    }

                    $preg_limit .= strrpos($preg_limit, ".", 1) == (strlen($preg_limit) - 1) ? $preg . "]" : "." . $preg . "]";
                }
            }
        } else {
            $preg_limit .= $str;
        }

        return $preg_limit;
    }

    /**
     * 获取所有屏蔽地址段
     *
     * @throws Exception
     */
    private static function getAllBlockIP(): array
    {
        $config = Options::alloc()->plugin('BlockIP');
        $ips = $config->ips;
        $ip_list = [];

        if ($ips) {
            $ip_array = explode("\n", $ips);

            foreach ($ip_array as $value) {
                $value = trim($value);

                if (empty($value)) {
                    continue;
                }

                $ipaddress = self::makePregIP($value);
                $ip = str_ireplace(".", "\.", $ipaddress);
                $ip = str_replace("*", "[0-9]{1,3}", $ip);
                $ipaddress = "/" . trim($ip) . "/";
                $ip_list[] = $ipaddress;
            }
        }

        return $ip_list;
    }
}
