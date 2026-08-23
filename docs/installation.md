---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and what starts happening to your pages immediately.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No build step, no runtime dependencies beyond Craft's own, no database tables, and no outbound HTTP
requests. Speculatr never talks to anything.

## Install

```sh
composer require justinholtweb/craft-speculatr
php craft plugin/install speculatr
```

Or find **Speculatr** in the Plugin Store and install it there.

## What happens immediately

From the moment it is installed, every front-end HTML response carries a small
`<script type="speculationrules">` block, and signed-out visitors get **prerendering at moderate
eagerness** — the browser starts loading the next page after about 200 ms of hovering a link, which
is roughly the point at which somebody has decided to click it.

Nothing else changes. In particular:

- **Signed-in visitors get nothing** until you turn them on. A prerender runs a page's JavaScript,
  and a page rendered for one specific person is the page most likely to be expensive.
- **Nothing you would regret opening early is in scope.** The control panel, action requests, every
  sign-in and sign-out path, and any URL carrying a preview, site or CSRF token are excluded before
  you have to think about it — read out of *your* Craft configuration, so a renamed control panel or
  a custom logout path is covered without anybody remembering.

## Check it worked

Open any front-end page and view source. Just before `</body>` you should find something like:

```html
<script type="speculationrules">
{"prerender":[{"source":"document","where":{"and":[
  {"href_matches":"/*","relative_to":"document"},
  {"not":{"href_matches":"/admin","relative_to":"document"}},
  …
]},"eagerness":"moderate"}]}
</script>
```

Or from the command line:

```sh
php craft speculatr/rules/show
```

In the control panel, **Speculatr → Rules** shows the same document, every exclusion with the reason
it exists, and a box you can paste a URL into to find out what would happen to a link pointing at it.

## Editions

None. Speculatr is free — no editions, no licence key, and no licensing code in the plugin.

## Where it works

Chromium browsers — Chrome, Edge, Opera and Android's browsers — from version 121.

Safari and Firefox ignore the rules entirely. That costs their visitors nothing but the couple of
kilobytes of JSON, and there is nothing to fall back to: a browser that does not speculate simply
loads the page when the reader clicks, exactly as it does today.

Chrome also declines to speculate under Data Saver, on a low battery, when memory is short, or when
the reader has turned preloading off in their settings. This is a feature that quietly does nothing
when doing something would be rude, which is the right shape for it.

## Removing it

```sh
php craft plugin/uninstall speculatr
```

Your pages go back to exactly what they were. Speculatr stores nothing — no tables, no rows — so
there is nothing left behind and nothing to clean up.
