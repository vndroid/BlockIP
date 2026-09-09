# BlockIP

A Guest IP address Blocker plug-in for Typecho.

## Introduction

Current language: **English** | [简体中文](/README_CN.md)

## HighLight

* When the plugin updates, please disable and then re-enable the plugin — the hook registration is refreshed on activation.
* Blank lines in the rule lists are ignored.

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

## Scope

The plugin hooks `begin` in `index.php` (before `Router::dispatch()`), so it covers **every** front-end route: pages, feeds, comment and trackback submission under `/action/*`, xmlrpc / pingback, attachments — and it blocks before any database query runs.

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