<?php
/**
 * 禁止指定 IP 访问站点
 *
 * @package BlockIP
 * @author Kokororin
 * @version 1.0.1
 * @update: 2019.04.03
 * @link https://github.com/Vndroid/BlockIP
 */
class BlockIP_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array('BlockIP_Plugin', 'BlockIP');
        return "插件已启用";
    }
    public static function deactivate()
    {
        return "插件已禁用";
    }
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $ips = new Typecho_Widget_Helper_Form_Element_Textarea('ips', null, null, _t('IP 黑名单列表'), _t('一行一个，支持规则<br>以下是例子<br>192.168.1.1<br>210.10.2.1-20<br>222.34.4.*<br>218.192.104.*'));
        $form->addInput($ips);
    }
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {}
    public static function blockIP()
    {
        //debug
        //print_r(BlockIP_Plugin::getAllBlockIP());
        if (BlockIP_Plugin::checkIP()) {
            $user = Typecho_Widget::widget('Widget_User');
            throw new Typecho_Widget_Exception('抱歉，当前 IP 段无法访问，如有问题，请<a href="mailto:' . $user->mail . '">联系站长</a>。');
        }
    }
    private static function checkIP()
    {
        $flag = false;
        $request = new Typecho_Request;
        $ip = trim($request->getIp());
        $iptable = BlockIP_Plugin::getAllBlockIP();
        if ($iptable) {
            foreach ($iptable as $value) {
                if (preg_match("{$value}", $ip)) {
                    $flag = true;
                    break;
                }
            }
        }
        return $flag;
    }
    private static function makePregIP($str)
    {
        if (strpos($str, "-") !== false) {
            $aIP = explode(".", $str);
            foreach ($aIP as $key => $value) {
                if (strpos($value, "-") === false) {
                    if ($key == 0) {
                        $preg_limit .= BlockIP_Plugin::makePregIP($value);
                    } else {
                        $preg_limit .= '.' . BlockIP_Plugin::makePregIP($value);
                    }
                } else {
                    $aipNum = explode("-", $value);
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
    private static function getAllBlockIP()
    {
        $config = Typecho_Widget::widget('Widget_Options')->plugin('BlockIP');
        $ips = $config->ips;
        if ($ips) {
            $ip_array = explode("\n", $ips);
            foreach ($ip_array as $value) {
                $ipaddress = BlockIP_Plugin::makePregIP($value);
                $ip = str_ireplace(".", "\.", $ipaddress);
                $ip = str_replace("*", "[0-9]{1,3}", $ip);
                $ipaddress = "/" . trim($ip) . "/";
                $ip_list[] = $ipaddress;
            }
        }
        return $ip_list;
    }
}