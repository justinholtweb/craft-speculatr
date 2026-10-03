# Speculatr

**The next page, loaded before the click.**

Chromium browsers will happily fetch — or fully render — the page a reader is about to open, if
you tell them which links are safe to open early. The telling is a small JSON document. The safety
is the entire problem.

Prerendering does not *fetch* a page, it **runs** one: scripts, analytics, everything, in a hidden
tab, before anybody has clicked. Point that at the wrong link and a reader gets signed out by
hovering over a menu, or a preview token is spent on a page nobody opened.

So the interesting part of this plugin is not the rules it writes. It is the exclusions it
assembles first — out of Craft's own configuration, so that a site with a renamed control panel, a
custom logout path or a token in a URL is protected without anybody having to remember.

Free. Craft 5.3+, PHP 8.2+. No build step, no runtime dependencies, no outbound requests.

Reference point: the WordPress *Speculative Loading* plugin, which does the same job for a CMS
whose paths it can hard-code. Speculatr has to ask Craft, which turns out to be the better answer.

## Install

```sh
composer require justinholtweb/craft-speculatr
php craft plugin/install speculatr
```

That is the whole setup. Every front-end HTML response carries rules from then on, guests get
prerendering on a 200 ms hover, and nothing that would be a bad idea to open early is in scope.

## What it emits

```html
<script type="speculationrules">
{"prerender":[{"source":"document","where":{"and":[
  {"href_matches":"/*","relative_to":"document"},
  {"not":{"href_matches":"/admin","relative_to":"document"}},
  {"not":{"href_matches":"/admin/*","relative_to":"document"}},
  {"not":{"href_matches":"/actions/*","relative_to":"document"}},
  {"not":{"href_matches":"/*\\?*(^|&)action=*","relative_to":"document"}},
  {"not":{"href_matches":"/logout","relative_to":"document"}},
  {"not":{"selector_matches":"[rel~=\"nofollow\"]"}}
]},"eagerness":"moderate","expects_no_vary_search":"params=(\"utm_source\" …)"}]}
</script>
```

`href_matches: "/*"` is what keeps this to your own origin: a relative pattern inherits the
document's protocol, host and port, so a link to somebody else's site never matches at all.

## What it never speculates

Read from **Craft's own configuration**, under whatever names this site gives them:

| | |
|---|---|
| `cpTrigger` | the control panel, at `/admin` or wherever you moved it — in signed-in visitors' rules only, so a renamed one is not published to everybody |
| `actionTrigger` | action requests — and `?action=` too, since Craft routes those from any path |
| `loginPath`, `logoutPath` | prerendering a logout is how a reader gets signed out by hovering |
| `setPasswordPath` and friends | every account path Craft knows about |
| `tokenParam`, `siteToken` | preview and share tokens have durations and usage limits |
| `csrfTokenName` | a URL carrying a credential is not a page |
| `resourceBaseUrl` | published resources are not pages either |

Plus, out of the box: links wearing `.no-speculation`, `rel="nofollow"`, `[download]`,
`target="_blank"` (a prerender cannot be handed to a new tab, so it is pure cost), and anything
that looks like a file rather than a page — case-insensitively, because `/brochure.PDF` is a
download too.

Then whatever you add: paths, query parameters, CSS selectors, extensions.

The **Rules** screen in the control panel lists every one of them with its reason, shows the exact
JSON being sent, and answers the only question anybody actually asks:

> is it going to prerender the thing that signs people out?

Type a URL in and it tells you, and names the exclusion that stopped it.

## Modes

| Mode | What happens | Cost |
|---|---|---|
| **Prefetch** | the HTML is downloaded and kept | a few kilobytes; nothing runs |
| **Prerender** | the page is loaded and run in a hidden tab | an entire page load |
| **Both** | prefetch at your eagerness, prerender one step behind it | the two layer |

**Both** is the interesting one. The browser prefetches on hover and upgrades the same URL to a
prerender once the reader commits, so the expensive half is never spent on a link the pointer
merely crossed. It roughly doubles the size of the rules document, since each action needs its own
copy of the `where` clause.

Eagerness runs `conservative` (pointer down) → `moderate` (~200 ms hover) → `eager` (~10 ms hover)
→ `immediate` (no interaction at all). Chrome holds only two speculations at a time at anything
other than `immediate`, so a keener setting does not mean *more* of them — it means they start
earlier and are thrown away more often.

## Signed-in visitors

Off by default, and there is a reason. A prerender runs a page's JavaScript, and a page rendered
for one specific person is both the most expensive page you serve and the one most likely to have
something on it that should not happen twice.

If you turn it on, **Prefetch instead of prerender when signed in** stays on: signed-in visitors
still get their next page out of the prefetch cache, without a hidden tab running their scripts.

In templates, guard anything that should happen when a *person* arrives:

```twig
{% if not craft.speculatr.isPrerender %}
    {% do entry.recordView() %}
{% endif %}
```

`Sec-Purpose` is `prefetch` for a prefetch and `prefetch;prerender` for a prerender — the prerender
value *contains* the prefetch token, so `isPrefetch` is false during a prerender, which is what you
want.

## Tracking parameters

On by default. Speculatr sends `No-Vary-Search` on front-end responses and mirrors it into the
rules as `expects_no_vary_search`, so a prefetch of `/pricing` is still a hit when the reader
actually arrives at `/pricing?utm_source=newsletter`.

Both halves are needed and they do different jobs: the header is what makes the cached entry match
at all, and the rule is what stops the browser racing its own in-flight prefetch. Turn it off if
any parameter in the list genuinely changes what you serve.

## Delivery

Inline by default. If your Content-Security-Policy will not allow `'inline-speculation-rules'`,
switch to the **header**: Speculatr sends `Speculation-Rules` pointing at a JSON document at
`/speculatr/rules.json`, fingerprinted so a change to your exclusions reaches everybody on their
next page view. Guests and signed-in visitors are sent separate URLs, and only the guest copy is
marked `public` — so a CDN that ignores `Vary: Cookie` still cannot hand one audience's rules to the
other.

Per-page additions from templates still go inline in that mode — a rules *file* is one document for
the whole site by definition, so it cannot carry them.

## Caching

Speculatr sends `Vary: Sec-Purpose` whenever a speculated request is served something different
from a real one, which is the default. Without it, a shared cache stores the rules-free copy a
prefetch received and hands it to the next real visitor — a bug that only appears behind a CDN and
only intermittently.

## Templates

```twig
{# is this a browser guessing, or a person? #}
{{ craft.speculatr.isSpeculative }}   {# either kind #}
{{ craft.speculatr.isPrefetch }}      {# body only, nothing running #}
{{ craft.speculatr.isPrerender }}     {# the whole page, in a hidden tab #}

{# this listing's first result is where nearly everybody goes next #}
{% do craft.speculatr.prefetch(entries|first) %}
{% do craft.speculatr.prerender(entry, 'moderate') %}

{# this page's links carry a filter parameter #}
{% do craft.speculatr.exclude('search/*') %}

{# not this page #}
{% do craft.speculatr.disable() %}

{# what would happen to a link to this? #}
{% set verdict = craft.speculatr.explain('/checkout') %}
{{ verdict.speculated ? verdict.summary() : verdict.reason }}
```

`prefetch()` and `prerender()` take URLs, elements, or a mix.

## Console

```sh
php craft speculatr/rules/show            # the rules document
php craft speculatr/rules/exclusions      # everything excluded, and why
php craft speculatr/rules/check /checkout # what happens to one URL
```

All three take `--logged-in` to answer as a signed-in user instead of a guest.

## Where this works

Chromium browsers — Chrome, Edge, Opera, Android — from version 121. Safari and Firefox ignore the
rules entirely, which costs their visitors nothing but the couple of kilobytes of JSON. Chrome also
declines to speculate under Data Saver, on a low battery, when memory is short, or when the reader
has turned preloading off.

Nothing about this changes what a browser that ignores it sees. It is not a fallback; there is
simply nothing to fall back to.

## Config file

Every setting can live in `config/speculatr.php`:

```php
<?php
return [
    'mode' => 'both',
    'eagerness' => 'moderate',
    'forLoggedIn' => true,
    'excludePaths' => ['checkout/*', 'account/*'],
    'excludeParams' => ['add-to-cart'],
    'prefetchUrls' => ['/contact'],
];
```

## Documentation

[justinholt.com/plugins/craft-speculatr](https://justinholt.com/plugins/craft-speculatr) — plus
[installation](https://justinholt.com/plugins/craft-speculatr/docs/installation),
[configuration](https://justinholt.com/plugins/craft-speculatr/docs/configuration),
[usage](https://justinholt.com/plugins/craft-speculatr/docs/usage),
[troubleshooting](https://justinholt.com/plugins/craft-speculatr/docs/troubleshooting) and the
[FAQ](https://justinholt.com/plugins/craft-speculatr/docs/faq).

## Licence

The Craft License. See `LICENSE.md`. Speculatr is free: no editions, no licence key, and no
licensing code in the plugin.
