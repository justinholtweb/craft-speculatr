---
title: Usage
slug: usage
order: 30
summary: Opting individual links out, the Twig API, guarding work that should only happen for real visitors, the control panel and the console.
---

Most sites never write any Twig for Speculatr — the settings cover it. Everything on this page is
for the cases where they do not.

## Opting one link out

Give it the opt-out class:

```html
<a href="/raffle/enter" class="no-speculation">Enter the raffle</a>
```

`rel="nofollow"`, `download` and `target="_blank"` links are already excluded by default, as are
links to files rather than pages. If you need a different rule, `excludeSelectors` takes any CSS
selector and matches it against the link itself:

```php
'excludeSelectors' => ['[data-modal]', 'a[href$="/apply"]'],
```

## Guarding work that should only happen for real visitors

This is the important one. A prerender **runs your page** — templates, JavaScript, analytics — in a
hidden tab, before anybody has clicked anything. Anything that should happen when a *person* arrives
needs guarding:

```twig
{% if not craft.speculatr.isPrerender %}
    {% do entry.recordView() %}
{% endif %}
```

| | |
| --- | --- |
| `craft.speculatr.isSpeculative` | either kind — the browser is guessing |
| `craft.speculatr.isPrefetch` | the HTML is being downloaded; nothing on the page is running |
| `craft.speculatr.isPrerender` | the whole page is being loaded and run in a hidden tab |

These read the `Sec-Purpose` request header, which is `prefetch` for a prefetch and
`prefetch;prerender` for a prerender. Note the prerender value *contains* the prefetch token — so
`isPrefetch` is deliberately **false** during a prerender, which is what you want when you are asking
"is this a cheap body fetch or a full page run".

Browsers already defer a good deal on your behalf: a prerendered page is hidden, so anything gated on
the Page Visibility API holds off until activation, and Google Analytics 4 handles this correctly
without help. It is your own counters, one-shot side effects and third-party scripts that need the
guard.

## Speculating specific URLs

A page often knows something the settings cannot — that this listing's first result is where nearly
everybody goes next, for instance.

```twig
{% do craft.speculatr.prefetch(entries|first) %}
{% do craft.speculatr.prefetch(['/contact', '/pricing']) %}
{% do craft.speculatr.prerender(entry, 'moderate') %}
```

Both take a URL, an element, or an array of either. `prefetch()` defaults to `immediate` eagerness —
naming a URL explicitly is already the decision the eagerness levels exist to defer. `prerender()`
defaults to `moderate`, and should be used sparingly: each one is a whole page load spent before
anybody has clicked, and Chrome will only hold a couple at a time.

A URL named in more than one place is emitted once.

## Excluding something for one page only

```twig
{% do craft.speculatr.exclude('search/*') %}
{% do craft.speculatr.exclude('cart/*', 'This listing links straight into the cart') %}
```

For the exclusion that is true of the page you are on rather than of the whole site — a listing whose
links carry a filter parameter, say. The second argument is the reason, and it shows up in the
control panel.

## Turning it off for one page

```twig
{% do craft.speculatr.disable() %}
```

For a page whose links are all one-shot. `excludePages` in the settings does the same thing by URI
pattern if it is not a per-template decision.

## Placing the script yourself

The rules are injected automatically just before `</body>`. If you want them somewhere specific:

```twig
{{ craft.speculatr.tag() }}
```

And to look at what is being sent:

```twig
<pre>{{ craft.speculatr.json() }}</pre>
{% set document = craft.speculatr.document() %}
```

## Asking about a URL

```twig
{% set verdict = craft.speculatr.explain('/checkout') %}

{% if verdict.speculated %}
    {{ verdict.summary() }}          {# "prerender (moderate)" #}
{% else %}
    {{ verdict.reason }}             {# "Configured in Speculatr's settings" #}
{% endif %}
```

`verdict.blockedBy` is the exclusion that stopped it, when one did. `verdict.caveats` lists what a URL
on its own cannot settle — chiefly that a selector exclusion may still apply to the actual link.

## The control panel

**Speculatr → Rules** answers the only question anybody really asks about this plugin, which is not
"what are my rules" but *is it going to prerender the thing that signs people out*.

- the exact JSON being sent, for signed-out visitors or for you
- every exclusion, with its source — Craft's own configuration, Speculatr's defaults, your settings —
  and the reason it exists
- a box you can paste a URL into, which tells you what would happen and names the exclusion that
  stopped it

## The console

```sh
php craft speculatr/rules/show              # the rules document
php craft speculatr/rules/exclusions        # everything excluded, and why
php craft speculatr/rules/check /checkout   # what happens to one URL
```

All three take `--logged-in` to answer as a signed-in user rather than a guest, which is the quick way
to confirm your downgrade is doing what you think.

```
$ php craft speculatr/rules/check /logout

  /logout
  ───────
  never speculated
  The logout URL (`logoutPath`)
```
