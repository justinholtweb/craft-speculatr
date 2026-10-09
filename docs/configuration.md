---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it defaults to, and the reasoning behind the two or three worth arguing with.
---

Settings live at **Settings → Plugins → Speculatr**, and every one of them can be set in
`config/speculatr.php` instead. The defaults are chosen to be useful and hard to regret, so plenty
of sites never open this screen.

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

## What to do

### `mode`

`prefetch`, `prerender` or `both`. Default `prerender`.

| Mode | What the browser does | What it costs |
| --- | --- | --- |
| `prefetch` | downloads the page's HTML and stops | a few kilobytes; no scripts run, nothing on the page fires |
| `prerender` | loads **and runs** the whole page in a hidden tab | an entire page load that may never be used |
| `both` | prefetches at your eagerness, prerenders one step behind it | the two layer |

`both` is the interesting one. The browser prefetches on hover and upgrades the same URL to a
prerender once the reader commits, so the expensive half is never spent on a link the pointer merely
crossed. It roughly doubles the size of the rules block, because each action needs its own copy of
the exclusion list.

### `eagerness`

`conservative`, `moderate`, `eager` or `immediate`. Default `moderate`.

| Value | Trigger |
| --- | --- |
| `conservative` | pointer or touch down — the reader has committed |
| `moderate` | about 200 ms of hover, or pointerdown |
| `eager` | about 10 ms of hover — nearly anything the pointer crosses |
| `immediate` | as soon as the rules are seen, with no interaction at all |

Chrome holds only **two** speculations at a time at anything other than `immediate`, discarding the
oldest. So a keener setting does not mean *more* speculation — it means it starts earlier and is
thrown away more often. `moderate` is the default because 200 ms of hover is a good proxy for intent.

## Who gets it

| Setting | Default | |
| --- | --- | --- |
| `forGuests` | `true` | signed-out visitors |
| `forLoggedIn` | `false` | everybody signed in |
| `forAdmins` | `false` | admins, independent of the above, so you can try it on yourself first |
| `downgradeForLoggedIn` | `true` | prerendering becomes prefetching for anyone signed in |

Signed-in visitors are off by default and it is worth understanding why. A prerender runs the page's
JavaScript in a hidden tab. A page rendered for one specific person is both the most expensive page
you serve and the one most likely to have something on it that should not happen twice.

`downgradeForLoggedIn` is the compromise that makes signed-in speculation defensible: those visitors
still get their next page out of the prefetch cache, without a hidden tab running their scripts.

## Consent

| Setting | Default | |
| --- | --- | --- |
| `deferToToss` | `true` | hold prerendering for consent whenever Toss is installed with its cookie consent kit on |
| `prerenderConsent` | `['analytics', 'marketing']` | the Toss categories a visitor must have granted before links are prerendered for them |

A prerender runs the next page's scripts, analytics and advertising tags included, before anybody
has clicked. When Toss is the site's consent manager and `deferToToss` is on, the page carries
prefetch rules in place of prerender rules — same links, same eagerness — and an inline script adds
the prerender rules in the visitor's browser once Toss reports every category in `prerenderConsent`
as granted. A withdrawal removes them again, which cancels any prerender they started. Undecided
and refused both mean no.

Nothing about the visitor is read on the server: every visitor is sent the same rules and the same
script, so a page from a full-page cache or a CDN is right for whoever receives it. The rules-file
URL in header delivery changes when Toss starts or stops holding prerenders, so a cached file is
never stale.

`necessary` is always granted and is ignored here. An empty list prerenders without asking, as it
does without Toss. Toss not installed, or its consent kit off: these settings do nothing.

Under a strict Content-Security-Policy, the script and the rule set it inserts are inline. The rule
set carries the script's nonce when Craft provides one; otherwise allow
`'inline-speculation-rules'`. This applies in header delivery too, because the held rules cannot
travel in the site-wide rules file.

## What never to speculate

Craft's own configuration is read first, so all of this is already excluded under whatever names your
site gives them: the control panel (`cpTrigger`), action requests (`actionTrigger`, and `?action=`
too), every account path (`loginPath`, `logoutPath`, `setPasswordPath` and the rest), preview and
site and CSRF tokens (`tokenParam`, `siteToken`, `csrfTokenName`), and published resources
(`resourceBaseUrl`). Each is covered under every site's subfolder too, with or without a trailing
slash.

The control panel exclusion goes only into signed-in visitors' rules. The rules are readable by
anybody, so putting it in a guest's would publish the name of a control panel you renamed to keep
out of sight; guests are not shown links into it, and its login screen is harmless to prefetch.

**Speculatr → Rules** lists every one of them with its reason. On top of that:

| Setting | Default | |
| --- | --- | --- |
| `optOutClass` | `no-speculation` | a class on a link that opts it out; empty for none |
| `excludeNofollow` | `true` | skip `rel="nofollow"` links |
| `excludeDownloads` | `true` | skip `download` links, and links to files |
| `excludeNewWindow` | `true` | skip `target="_blank"` links |
| `excludeQueryStrings` | `false` | skip **every** URL carrying a query string |
| `excludePaths` | `[]` | URI patterns, `*` wildcards, leading slash optional |
| `excludeParams` | `[]` | a URL carrying one of these is never speculated |
| `excludeSelectors` | `[]` | CSS selectors matched against the link itself |
| `excludeExtensions` | a long list | treated as files rather than pages |

`excludeNewWindow` is on because a prerender cannot be handed to a new tab — one started for a
`target="_blank"` link is thrown away on activation, so it is all cost and no benefit.

`excludeQueryStrings` is blunt and is the right answer for a site whose query strings mean "do
something" rather than "show something".

**Craft Commerce**: its action requests are already covered, but a cart or checkout page with a URI
of its own is not — Speculatr cannot know what you called it. Add it to `excludePaths`. The Rules
screen says so too, when Commerce is installed.

## Named URLs

`prefetchUrls` (default `[]`) — URLs prefetched immediately on every page, whether or not anything
links to them. For the two or three pages everybody ends up on.

Always prefetched and never prerendered: an unconditional prerender of a handful of pages, on every
single page view, is a lot of page loads to spend on a guess about where somebody is going next. If
you have decided otherwise for one page, `craft.speculatr.prerender()` is there.

## Tracking parameters

| Setting | Default | |
| --- | --- | --- |
| `ignoreTrackingParams` | `true` | declare that the parameters below do not change the page |
| `trackingParams` | `utm_*`, `gclid`, `fbclid`, `msclkid`, `mc_cid`, … | the parameters in question |

With this on, Speculatr sends a `No-Vary-Search` header on front-end responses and mirrors it into
the rules as `expects_no_vary_search`, so a prefetch of `/pricing` is still a hit when the reader
actually arrives at `/pricing?utm_source=newsletter`.

Both halves are needed and they do different jobs. The **header** is what makes the cached entry
match at all; the **rule** is what stops the browser racing its own in-flight prefetch. Turn the
whole thing off if any parameter in your list genuinely changes what you serve.

## Delivery

| Setting | Default | |
| --- | --- | --- |
| `delivery` | `inline` | `inline` or `header` |
| `skipOnSpeculative` | `true` | a page fetched *by* a speculation gets no rules of its own |
| `varyOnSecPurpose` | `true` | send `Vary: Sec-Purpose` when the response depends on it |
| `excludePages` | `[]` | URI patterns whose own pages carry no rules |

`delivery: header` sends a `Speculation-Rules` header pointing at a JSON document at
`/speculatr/rules.json`, fingerprinted so a change to your exclusions reaches everybody on their next
page view. Guests and signed-in visitors are sent separate URLs, and only the guest copy is marked
`public`, so a CDN that ignores `Vary: Cookie` still cannot hand one audience's rules to the other.
It is the answer for a site whose Content-Security-Policy will not allow
`'inline-speculation-rules'`. Per-page additions from templates still go inline in that mode — a
rules *file* is one document for the whole site by definition, so it cannot carry them.

`skipOnSpeculative` stops a prerender from starting its own prerenders. The second hop is a guess
about a guess.

`varyOnSecPurpose` should stay on while `skipOnSpeculative` is on. Without it, a shared cache stores
the rules-free copy a prefetch received and hands it to the next real visitor — a bug that only
appears behind a CDN and only intermittently.

**`excludePages` is a different question from `excludePaths`**, and the two are easy to confuse.
`excludePaths` is about where links *point*; `excludePages` is about which pages *carry* rules. A
checkout page should not be linked to speculatively; an account page might be fine to link to but
should not itself hand out rules.
