# Speculatr — design notes

## The problem worth solving

The Speculation Rules API is a dozen lines of JSON. Anybody can paste one into a layout template.
What they cannot easily do is work out which links must never be in it — and getting that wrong is
not a performance regression, it is a reader being signed out by hovering over a menu.

So the plugin is organised around the exclusions, not around the rules.

## Why Craft's configuration is the source

The WordPress plugin hard-codes `/wp-login.php` and `/wp-admin/*` because in WordPress those are
constants. Craft's equivalents are all configurable: `cpTrigger`, `actionTrigger`, `loginPath`,
`logoutPath`, `tokenParam`, `csrfTokenName`, `resourceBaseUrl`. A plugin that hard-coded `/admin`
would be wrong on exactly the sites that took security seriously enough to move it.

Reading them out of `GeneralConfig` means the protection tracks the site.

## Two things that are easy to conflate

**Where links point** (the exclusions) and **which pages carry rules** (`excludePages`). A checkout
page should not be *linked to* speculatively; an account page might be fine to link to but should
not itself hand out rules. Separate settings, separate screens.

## Decisions

- **String-form URL patterns, not the object form.** The spec allows both. The object form cannot be
  combined with a base URL in a real `URLPattern` constructor, and the string form is what Chrome
  documents and what is deployed at scale in WordPress. Verified both before choosing.
- **Individual `not` predicates, not one `not` over an array.** The array form is in the spec and
  would be shorter, but a rules document a browser silently declines to parse fails in exactly the
  way nobody notices — the page still works and the feature simply never happens.
- **Extensions collapse into one alternation group.** The exception to the rule above, because the
  saving was almost half the document and the alternation is plain regex inside a group, not a
  spec extension.
- **Named URLs are prefetched, never prerendered.** An unconditional prerender of a handful of pages
  on every page view is a lot of page loads to spend on a guess. `craft.speculatr.prerender()`
  exists for anyone who has decided otherwise for one page.
- **`both` prerenders one step behind the prefetch.** The two layer rather than compete: prefetch on
  hover, upgrade to prerender on commitment.
- **Signed-in users default to nothing, and degrade to prefetch when enabled.** The WordPress plugin
  warns about object caching; degrading is a better answer than a warning.
- **No database tables.** Nothing here is worth persisting.

## Not built, deliberately

- **Per-section or per-entry-type rules.** Expressible today with `craft.speculatr.exclude()` in the
  template that knows, and a control panel matrix of sections against modes would be a lot of
  surface for a rule most sites express as one path pattern.
- **Measuring whether speculation helped.** It needs a field-data loop of its own, which is a
  different plugin. `Sec-Purpose` is exposed so a site's existing analytics can do it.
- **Cross-origin prefetch.** Requires `anonymous-client-ip-when-cross-origin` and only works for
  readers with no cookies for the destination. Almost no Craft site wants it.
