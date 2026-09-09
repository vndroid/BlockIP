<?php

/**
 * BlockIP 测试桩
 *
 * 用最小实现顶掉 Plugin.php 依赖的 Typecho 运行时，使插件可以脱离站点单独测试。
 * 每个桩的行为都对齐 Typecho 1.2 的真实实现，差异会直接导致测试结果失真，
 * 改动前请对照 var/Typecho/ 与 var/Widget/ 下的同名类。
 */

namespace {
    if (!defined('__TYPECHO_ROOT_DIR__')) {
        define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
    }

    if (!function_exists('_t')) {
        function _t($string, ...$args)
        {
            return $args ? vsprintf($string, $args) : $string;
        }
    }
}

namespace Typecho {

    /**
     * 对齐 var/Typecho/Exception.php：构造函数第二参数是 code
     */
    class Exception extends \Exception
    {
        public function __construct($message, $code = 0)
        {
            $this->message = $message;
            $this->code = $code;
        }
    }

    /**
     * 对齐 var/Typecho/Config.php：取不存在的键返回 null，不报错
     */
    class Config
    {
        private array $config;

        public function __construct(array $config = [])
        {
            $this->config = $config;
        }

        public function __get(string $name)
        {
            return $this->config[$name] ?? null;
        }
    }

    class Request
    {
        private static ?Request $instance = null;

        public array $server = [];
        public array $headers = [];

        public static function getInstance(): Request
        {
            return self::$instance ??= new self();
        }

        /**
         * 重置为一个新请求
         *
         * @param array<string, string> $headers 键为 HTTP_ 前缀的 $_SERVER 形式
         */
        public static function fake(string $remoteAddr, array $headers = []): Request
        {
            self::$instance = new self();
            self::$instance->server['REMOTE_ADDR'] = $remoteAddr;
            self::$instance->headers = $headers;

            return self::$instance;
        }

        public function getServer(string $name, ?string $default = null): ?string
        {
            return $this->server[$name] ?? $default;
        }

        /**
         * 对齐 var/Typecho/Request.php：内部查的是 $_SERVER['HTTP_' . KEY]
         *
         * 桩若漏掉 HTTP_ 前缀，所有可信代理用例都会假性失败
         */
        public function getHeader(string $key, ?string $default = null): ?string
        {
            $key = strtoupper(str_replace('-', '_', $key));

            return $this->headers['HTTP_' . $key] ?? $default;
        }
    }

    /**
     * 只实现 BlockIP 用到的 select('mail')->from('table.users')->where('uid = ?', N)->limit(1)
     */
    class Db
    {
        /** @var array<int, array<string, string>> uid => 行 */
        public static array $users = [];

        /** 置真则 fetchRow 抛异常，用于验证插件不会把数据库故障放大成 500 */
        public static bool $shouldFail = false;

        public ?int $uid = null;

        public static function get(): Db
        {
            return new self();
        }

        public function select(...$args): Db
        {
            return $this;
        }

        public function from(string $table): Db
        {
            return $this;
        }

        public function where(string $condition, ...$values): Db
        {
            $this->uid = $values[0] ?? null;

            return $this;
        }

        public function limit(int $limit): Db
        {
            return $this;
        }

        public function fetchRow($query): ?array
        {
            if (self::$shouldFail) {
                throw new \Exception('database is down');
            }

            return self::$users[$query->uid] ?? null;
        }
    }

    /**
     * 记录 activate() 注册了哪些钩子，形如 'index.php:begin'
     */
    class Plugin
    {
        /** @var array<string, callable> */
        public static array $hooks = [];

        private string $handle;

        public function __construct(string $handle)
        {
            $this->handle = $handle;
        }

        public static function factory(string $handle): Plugin
        {
            return new self($handle);
        }

        public static function reset(): void
        {
            self::$hooks = [];
        }

        public function __set(string $name, $value): void
        {
            self::$hooks[$this->handle . ':' . $name] = $value;
        }
    }
}

namespace Typecho\Widget {

    /**
     * 关键类型：Typecho\Common::error() 只对它透传 message，其余一律覆盖成 Server Error
     */
    class Exception extends \Typecho\Exception
    {
    }
}

namespace Typecho\Plugin {

    class Exception extends \Typecho\Exception
    {
    }

    interface PluginInterface
    {
        public static function activate();

        public static function deactivate();

        public static function config(\Typecho\Widget\Helper\Form $form);

        public static function personalConfig(\Typecho\Widget\Helper\Form $form);
    }
}

namespace Typecho\Widget\Helper {

    class Form
    {
        /** @var array<int, object> */
        public array $inputs = [];

        public function addInput($input): void
        {
            $this->inputs[] = $input;
        }
    }
}

namespace Typecho\Widget\Helper\Form\Element {

    class Text
    {
        public ?string $inputName;

        /** @var array<int, array> */
        public array $rules = [];

        public function __construct(?string $name = null, ...$args)
        {
            $this->inputName = $name;
        }

        public function addRule(...$args): void
        {
            $this->rules[] = $args;
        }
    }

    class Textarea extends Text
    {
    }
}

namespace Widget {

    class Options
    {
        public static ?\Typecho\Config $pluginConfig = null;

        public static function alloc(): Options
        {
            return new self();
        }

        /**
         * 对齐 var/Widget/Options.php：配置行不存在时抛异常，而不是返回空
         */
        public function plugin(string $name): \Typecho\Config
        {
            if (self::$pluginConfig === null) {
                throw new \Typecho\Plugin\Exception('插件配置信息没有找到', 500);
            }

            return self::$pluginConfig;
        }
    }

    class User
    {
        /** 当前登录用户组，null 表示未登录 */
        public static ?string $group = null;

        /** 对齐 var/Widget/User.php 的组权重 */
        private array $groups = [
            'administrator' => 0,
            'editor' => 1,
            'contributor' => 2,
            'subscriber' => 3,
            'visitor' => 4,
        ];

        public static function alloc(): User
        {
            return new self();
        }

        public function hasLogin(): bool
        {
            return self::$group !== null;
        }

        /**
         * 对齐 var/Widget/User.php::pass()：$return 为真时不重定向、不抛异常
         */
        public function pass(string $group, bool $return = false): bool
        {
            if (
                $this->hasLogin()
                && array_key_exists($group, $this->groups)
                && $this->groups[self::$group] <= $this->groups[$group]
            ) {
                return true;
            }

            if ($return) {
                return false;
            }

            throw new \Typecho\Widget\Exception('禁止访问', 403);
        }
    }
}
