# BlockIP

A guest IP address blocker plugin for Typecho 1.2 or newer. PHP 8.2.0 or newer is required.

## Introduction

Current language: **English** | [简体中文](/README_CN.md)

## HighLight

* When the plugin updates, please disable and then re-enable the plugin — the hook registration is refreshed on activation.
* Blank lines in the rule lists are ignored.

## Usage

Download the source code or clone the repository into `usr/plugins/`. The plugin directory name MUST be `BlockIP`; then activate the plugin in the admin panel. PHP 8.2.0 or newer is required. On an older runtime, the plugin refuses activation and reports the current PHP version.

## Rule syntax

One rule per line; IPv4 and IPv6 rules can be mixed freely:

| Syntax | Meaning |
| --- | --- |
| `192.168.1.1` | A single IPv4 address |
| `210.10.2.1-20` | Inclusive range, allowed in any octet |
| `222.34.4.*` | Wildcard, same as `0-255` |
| `192.168.1.0/24` | IPv4 CIDR |
| `2001:db8::1` | A single IPv6 address |
| `2001:db8::/32` | IPv6 prefix |

Ranges and wildcards are IPv4-only. For IPv6 use a single address or a prefix — a single host usually owns an entire `/64`, so prefixes are what you actually want.

IPv6 rules and visitor addresses are both normalised to their binary form, so `2001:db8::1`, `2001:0db8:0000:0000:0000:0000:0000:0001` and `2001:DB8::1` are the same address. IPv4 rules are only ever matched against IPv4 visitors and IPv6 rules against IPv6 visitors; the two families never bleed into each other.

On a dual-stack listener `REMOTE_ADDR` may arrive as an IPv4-mapped address such as `::ffff:192.0.2.1`. It is unmapped to `192.0.2.1` before matching, so existing IPv4 rules keep working and need no rewriting.

Unparsable rules are rejected when you save the settings, instead of silently doing nothing.

## Scope

The plugin hooks `begin` in `index.php` (after Typecho initialisation and before `Router::dispatch()`), so it covers **every** front-end route: pages, feeds, comment and trackback submission under `/action/*`, xmlrpc / pingback and attachments.

The admin area has its own entry point and is never routed through this hook, so it is unaffected. Requests *originating* from the admin area (comment moderation, uploads, xmlrpc) do go through `index.php`, so logged-in **administrators and editors** are always allowed through, keeping both consistent and making it impossible to lock yourself out by blocking your own IP. Contributors, subscribers and anonymous visitors are matched by IP as usual.

Blocked requests get **HTTP 403** and a page pointing at the administrator's mailbox, taken from the "contact mail" setting and falling back to the mail address of user uid 1. If neither is available the text is shown without an empty link.

> After upgrading to this version, disable and re-enable the plugin once so the new hook registration takes effect.

## Behind a reverse proxy / CDN

By default the plugin matches against the TCP peer address (`REMOTE_ADDR`) only and trusts **no** proxy header, so the block cannot be bypassed by spoofing one.

If your site sits behind Cloudflare, an nginx reverse proxy, etc., `REMOTE_ADDR` is the proxy itself. Put the proxy's address or network into the "trusted proxies" field (same syntax as above); only then will the plugin parse the configured proxy header and take the **right-most untrusted** address in it as the visitor IP.

> Never put visitor networks into the trusted proxies field — that is equivalent to letting anyone spoof their own IP.

## Tests

The repository ships a dependency-free regression suite. `tests/stubs.php` replaces the Typecho runtime the plugin depends on with minimal stand-ins, so neither Typecho nor PHPUnit is required:

```bash
php tests/run.php
```

It covers rule matching, proxy-header spoofing, exception type and status code, contact-mail fallback, hook registration and role exemptions. Exit code 0 means everything passed. Please run it after changing `Plugin.php`.

## Author

<a href="https://github.com/vndroid/BlockIP/graphs/contributors">
<img src="https://contrib.rocks/image?repo=vndroid/BlockIP" />
</a>

And origin authors

[@kokororin](https://github.com/kokororin)
