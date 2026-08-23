---
title: FAQ
slug: faq
order: 50
summary: Cost, server load, privacy, analytics, browser support, Commerce, and what happens to your pages if you remove it.
---

## Is Speculatr really free?

Entirely, permanently, with no editions and no licence key. Every feature in this documentation is in
the plugin you install.

## Will this hammer my server?

It will increase requests, and how much depends entirely on the mode and eagerness you choose.

The honest framing: every speculation is a real request to your server for a page the reader has not
asked for yet. At `moderate` eagerness that request happens after ~200 ms of hovering a link, so most
of them are for pages somebody was about to open anyway. At `eager` or `immediate`, many more will be
wasted.

Three things keep it bounded:

- Chrome holds only **two** speculations at a time at anything other than `immediate`.
- A prefetch is just the HTML — no images, no scripts, no subresources.
- `Sec-Purpose` is on every speculative request, so you can measure the real number before deciding
  it is a problem, and rate-limit or short-circuit at the edge if it is.

Start at `prefetch` + `moderate` if you are unsure. Move to `prerender` once you have looked at the
traffic.

## What is the difference between prefetch and prerender, really?

A **prefetch** downloads the page's HTML and puts it in a memory cache. Nothing on the page runs. It
costs a few kilobytes and makes the eventual navigation noticeably faster.

A **prerender** loads and *runs* the entire page in a hidden tab — subresources, JavaScript, data
fetches, everything — so that activating it is close to instant. It costs a full page load that may
never be used, and it means your page's code executes before anybody clicked.

That second sentence is the whole reason this plugin is more than a snippet.

## Does it track anybody?

No. Speculatr stores nothing, has no database tables, sets no cookies, and makes no outbound
requests. It reads your Craft configuration and writes a JSON block into your pages.

## Is speculative loading a privacy problem for my visitors?

It is worth being straight about. A prefetch or prerender is a real request, so your server logs a
visit to a page the reader never opened, and any third-party script on a prerendered page may fire.

Two things mitigate it, and one is yours to do. Chrome sends `Sec-Purpose` on every such request so
both you and your third parties can tell the difference. And a prerendered page is hidden, so anything
correctly gated on the Page Visibility API — including Google Analytics 4 — holds off until the reader
actually arrives. Your own counters need the `craft.speculatr.isPrerender` guard.

If your site is one where "which page did this person load" is itself sensitive, `prefetch` mode is
the conservative choice: it never runs the page.

## Does this affect GDPR or cookie consent?

It does not change what you collect, but it can change *when* scripts run. If you use a consent
manager, check that it does not fire on a prerendered page before the reader has seen the banner — the
same Page Visibility gating that fixes analytics applies here.

Speculatr itself has no consent implications: nothing to store, nothing to disclose.

## Will it prerender my checkout, or sign people out?

Not by default, and that is the part of this plugin worth caring about.

Speculatr reads *your* Craft configuration and excludes the control panel, action requests (both by
path and by `?action=`), every login, logout and password path, and any URL carrying a preview, site
or CSRF token — under whatever names your site gives them. A site that moved its control panel is
covered without anybody remembering to say so.

The exception it cannot solve for you: a **cart or checkout page with a URI of its own**. Speculatr has
no way to know you called it `/basket`. Add it to `excludePaths`. The Rules screen reminds you when
Commerce is installed.

## Does it work with full-page caching?

Yes, with one thing to get right. When a page fetched by a speculation is served without rules — the
default — the response varies on the `Sec-Purpose` request header, and Speculatr sends
`Vary: Sec-Purpose` to say so. If your CDN strips that, a rules-free copy can be cached and served to
real visitors. See Troubleshooting.

## Why do my prefetches miss when people arrive from a campaign link?

Because `/pricing` and `/pricing?utm_source=newsletter` are different URLs as far as the cache is
concerned. `ignoreTrackingParams` fixes it by declaring — in a `No-Vary-Search` header and in the
rules — that those parameters do not change the page. It is on by default.

## Does it support Safari and Firefox?

No, because they do not support the Speculation Rules API. They ignore the block entirely, which costs
their visitors a couple of kilobytes of JSON and nothing else. There is no fallback and none is
wanted: a browser that does not speculate just loads the page when the reader clicks it.

Roughly two thirds of the web is Chromium, so this is a real improvement for most of your traffic and
a no-op for the rest.

## How is this different from a link-prefetch script like instant.page?

Those scripts listen for hover and inject `<link rel="prefetch">`. That works everywhere, and it is
JavaScript you now ship and maintain.

Speculation Rules are declarative: the browser does the hover detection, applies its own limits and
heuristics, and — crucially — can *prerender*, which no script can do. Speculatr also has the whole
exclusion apparatus, which is the part a three-line script does not have and the part that eventually
matters.

## Does it work on multi-site installs?

Yes. Rules match the origin of the page they are on, so each site speculates its own links. The
control panel's URL checker knows every origin your install serves.

## Can I see what it is actually sending?

**Speculatr → Rules** in the control panel, or `php craft speculatr/rules/show`. Chrome's DevTools has
an **Application → Speculative loads** panel that shows every rule, what it matched, and why anything
was rejected.

## What happens if I remove it?

Your pages go back to exactly what they were. There are no tables, no rows, and no residue — the
plugin's entire output is a script element and two response headers, and both stop the moment it is
uninstalled.
