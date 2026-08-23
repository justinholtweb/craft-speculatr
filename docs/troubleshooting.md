---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: When no rules appear, when they appear but nothing speculates, and the caching and CSP problems that only show up in production.
---

## No rules appear in the page at all

Work down this list; it is roughly in order of likelihood.

1. **You are signed in.** Signed-in visitors get nothing by default. Open the page in a private
   window, or turn on **Admins** in the settings to try it on yourself.
2. **The page is not HTML.** Speculatr only touches responses whose `Content-Type` is `text/html`, so
   a `feed.rss.twig` or a `manifest.json.twig` is correctly left alone.
3. **The response is not a 200.** A 404 or a redirect carries no rules.
4. **The page has no `</body>`.** A fragment — an htmx swap, an Element API payload rendered as HTML —
   is left alone rather than having a script element stapled to the end of it.
5. **The URL carries a preview or share token.** A page you are looking at through Live Preview or a
   share link never carries rules; speculating from it would spend the token on links nobody clicked.
6. **The page matches `excludePages`.**
7. **A template called `craft.speculatr.disable()`.**
8. **You are looking at a prefetched copy.** If you fetched the page with a `Sec-Purpose` header, you
   correctly got no rules — see `skipOnSpeculative`.
9. **`delivery` is set to `header`.** The rules are in a `Speculation-Rules` response header pointing
   at `/speculatr/rules.json`, not in the page. Check the headers, not the source.

`php craft speculatr/rules/show` prints what a guest would get, which separates "the rules are wrong"
from "the rules never reached this page".

## The rules are there but nothing ever speculates

- **Check the browser.** This only works in Chromium — Chrome, Edge, Opera, Android — from version
  121. Safari and Firefox ignore it entirely and always will until they ship it.
- **Check `chrome://predictors` and the Application → Speculative loads panel** in Chrome DevTools.
  It lists every rule, every URL it matched, and — most usefully — the reason any speculation was
  rejected or discarded.
- **Chrome opts out on its own** under Data Saver, on a low battery, when memory is short, or when
  "Preload pages" is off in the reader's settings. Nothing is wrong; the browser has decided not to.
- **Only two at a time.** At any eagerness other than `immediate`, Chrome holds two speculations and
  discards the oldest. Hovering across a navigation bar will start and throw away a lot of them, and
  that is the intended behaviour.
- **Your Content-Security-Policy is blocking it.** See below.

## A Content-Security-Policy is blocking the inline rules

Inline speculation rules need an explicit allowance. Either add it:

```
Content-Security-Policy: script-src 'self' 'inline-speculation-rules'
```

…or set **Delivery** to `Speculation-Rules` header, which puts the rules in a JSON document at
`/speculatr/rules.json` and needs no inline allowance at all.

The browser gives no visible error for this beyond a CSP violation report, so it is worth checking the
console before concluding the rules are wrong.

## Something is being speculated that should not be

Paste the URL into **Speculatr → Rules**, or run
`php craft speculatr/rules/check <url>`. If it comes back speculated and you disagree, add it — as a
path, a query parameter, or a CSS selector on the link.

Remember that a selector exclusion cannot be evaluated from a URL alone. The checker says so rather
than pretending; a link wearing `.no-speculation` is excluded even though its URL is not.

Craft Commerce deserves a specific mention: its **action requests** are already excluded, but a cart
or checkout page with a URI of its own is not, because Speculatr has no way to know what you called
it. Add it to `excludePaths`.

## Visitors get no speculation behind a CDN, intermittently

This is the one that is genuinely hard to diagnose from the symptom, so it is worth understanding
before it happens.

When `skipOnSpeculative` is on, a page fetched *by* a prefetch is served without rules. That means the
response varies on the `Sec-Purpose` request header. A shared cache that has not been told will store
the rules-free copy a prefetch received and hand it to the next real visitor, who then gets no
speculation at all — and it will look random, because it depends which request populated the cache.

Speculatr sends `Vary: Sec-Purpose` to prevent exactly this. If you have stripped or overridden `Vary`
at the edge, put it back. If your CDN cannot vary on that header, turn `skipOnSpeculative` off instead
— serving rules to a speculated page is a much smaller problem than serving a stale rules-free page to
everybody.

## Prefetches are not being used when readers arrive from a campaign

A prefetch of `/pricing` only satisfies a navigation to `/pricing?utm_source=newsletter` if the
response says the parameter does not matter. That is what `ignoreTrackingParams` does, and it needs
**both** halves to work: the `No-Vary-Search` response header and the matching
`expects_no_vary_search` in the rules.

Check that the header is actually reaching the browser. A CDN or a reverse proxy that strips unknown
response headers will remove it, and the symptom is simply that the prefetch is never a hit.

## Analytics are counting views nobody made

A prerender runs your page. Google Analytics 4 handles this correctly on its own — it waits for
activation via the Page Visibility API — but your own counters and some third-party scripts will not.

```twig
{% if not craft.speculatr.isPrerender %}
    {% do entry.recordView() %}
{% endif %}
```

If the numbers went up rather than down, that is the direction to look.

## The page is truncated, or ends mid-tag

Speculatr restamps `Content-Length` after adding the script element, so this should not happen. If it
does, something else is also modifying the response after `Response::EVENT_AFTER_PREPARE` and is not
restamping. Turn Speculatr off to confirm, then look at the other plugin.

## Nothing at all works after an upgrade

```sh
php craft clear-caches/compiled-templates
php craft speculatr/rules/show
```

If `show` prints a sane document, the plugin is fine and the problem is in delivery — full-page
caching, a CDN, or a template that stopped rendering `</body>`.
