# BlockIP

一款 Typecho 访客地址屏蔽插件，支持 Typecho 1.2 及以上版本。

## 简介

当前语言： [英文](/README.md) | **简体中文**

### 插件亮点

- 遵循 Typecho 1.2 开发规范，兼容性更好；
- 支持 IP 地址屏蔽规则，满足不同需求；
- 轻量级设计，性能更优；

### 使用方法

如之前克隆过旧版本插件，需要在插件目录执行下面命令来切换分支：

```bash
git branch -m master main
git fetch origin
git branch -u origin/main main
git remote set-head origin -a
```

全新安装则下载源码压缩包或者克隆仓库到插件目录 `usr/plugins/` ，插件目录名必须为 `BlockIP`，然后在 Typecho 后台启用插件即可。

### 规则语法

仅支持 IPv4，一行一条：

| 写法 | 含义 |
| --- | --- |
| `192.168.1.1` | 单个地址 |
| `210.10.2.1-20` | 闭区间，任意一段均可 |
| `222.34.4.*` | 通配，等价于 `0-255` |
| `192.168.1.0/24` | CIDR |

无法识别的规则会在保存时报错，不会静默失效。

### 反向代理 / CDN 后面的站点必读

插件默认只按 TCP 连接地址（`REMOTE_ADDR`）判定，**任何代理头都不采信**，因此无法伪造绕过。

如果站点在 Cloudflare、nginx 反代等设施后面，`REMOTE_ADDR` 会是反代自身的地址，此时需要在「可信代理网段」中填入反代的地址或网段（语法同上），插件才会去解析「代理头名称」指定的请求头，并取其中**最右侧的非可信地址**作为访客 IP。

> 切勿在「可信代理网段」中填入访客网段——那等同于允许任意访客伪造自己的 IP。

## 开发者

<a href="https://github.com/vndroid/BlockIP/graphs/contributors">
<img src="https://contrib.rocks/image?repo=vndroid/BlockIP" />
</a>

和插件原作者

[@kokororin](https://github.com/kokororin)