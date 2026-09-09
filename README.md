# BlockIP

A Guest IP address Blocker plug-in for Typecho.

## Introduction

Current language: **English** | [简体中文](/README_CN.md)

## HighLight

* When the plugin updates, please disable the plugin before updating.
* DON'T HAVE A BLANK LINE!!!

## Usage

Download the source code or git clone to `usr/plugins/`, plug-in diractory name MUST be `BlockIP`, then activate the plug-in in admin panel.

## Rule syntax

IPv4 only, one rule per line:

| Syntax | Meaning |
| --- | --- |
| `192.168.1.1` | A single address |
| `210.10.2.1-20` | Inclusive range, allowed in any octet |
| `222.34.4.*` | Wildcard, same as `0-255` |
| `192.168.1.0/24` | CIDR |

Unparsable rules are rejected when you save the settings, instead of silently doing nothing.

## Behind a reverse proxy / CDN

By default the plugin matches against the TCP peer address (`REMOTE_ADDR`) only and trusts **no** proxy header, so the block cannot be bypassed by spoofing one.

If your site sits behind Cloudflare, an nginx reverse proxy, etc., `REMOTE_ADDR` is the proxy itself. Put the proxy's address or network into the "trusted proxies" field (same syntax as above); only then will the plugin parse the configured proxy header and take the **right-most untrusted** address in it as the visitor IP.

> Never put visitor networks into the trusted proxies field — that is equivalent to letting anyone spoof their own IP.

## Author

<a href="https://github.com/vndroid/BlockIP/graphs/contributors">
<img src="https://contrib.rocks/image?repo=vndroid/BlockIP" />
</a>

And origin authors

[@kokororin](https://github.com/kokororin)