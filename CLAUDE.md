# Speculatr — Craft CMS 5 Plugin

## Project Overview

Speculatr puts Speculation Rules on a Craft site: the browser prefetches or prerenders the page a
reader is about to open, so the navigation is instant.

Distributed as `justinholtweb/craft-speculatr`. **Free — no editions, no licensing code.**

Reference point: the WordPress *Speculative Loading* plugin (`speculation-rules`), which does the
same job for a CMS whose paths it can hard-code.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No database tables, no migrations, no build step, no runtime dependencies, and no outbound HTTP.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\speculatr`
- Package: `justinholtweb/craft-speculatr`
- Handle: `speculatr`

### The load-bearing idea: the exclusions are the product

Writing the rules is twenty lines. The reason this is a plugin rather than a snippet is that
prerendering *runs* a page — scripts, analytics, everything, in a hidden tab, before anybody has
clicked. A rule that says "speculate every link" eventually opens the one that signs somebody out.

So the exclusions are assembled from **Craft's own configuration** rather than from a list of
guesses: `cpTrigger`, `actionTrigger`, every account path, `tokenParam`, `siteToken`,
`csrfTokenName`, `resourceBaseUrl`. A site that renamed its control panel is covered without
anybody remembering.

### Services

- `exclusions` — everything never speculated, and why. Memoised per request; `reset()` for tests.
- `rules` — the document itself, the audience gate, and the URL explainer.
- `injector` — eligibility, delivery, `Sec-Purpose` classification, the `Vary` and `No-Vary-Search`
  headers.

### One model, two answers

`models\Exclusion` holds each exclusion once and can express it two ways: `patterns()` gives the
`href_matches` strings sent to the browser, `matchesUrl()` answers the same question in PHP for the
control panel's checker. They are written from the same value deliberately — a pattern nobody can
evaluate is a rule nobody can check, and a check that does not correspond to the emitted pattern is
worse than no check.

**Every URL pattern was verified against a real `URLPattern` before being written down**, because a
wrong one fails silently: the browser accepts the rules, matches nothing, and the exclusion simply
never happens. `node -e 'new URLPattern(pattern, base).test(url)'` — Node 24 has it globally.

## Traps found while building this

- **`/admin/*` does not match `/admin`.** A path exclusion is genuinely *two* patterns, and the one
  it misses is the one that matters most. `Exclusion::patterns()` returns both.
- **`/logout` does not match `/logout/`** — and Craft trims slashes before routing, so the second
  signs somebody out just the same (and is what `addTrailingSlashesToUrls` writes). An exact path
  is emitted as `/logout{/}?`, which matches both and not `/logoutx` or `/logout/x`.
- **Craft routes are relative to the site's base URL, not the origin.** On a site served from
  `/fr/`, logout is `/fr/logout`. `Exclusions::routePrefixes()` emits the Craft exclusions under
  every site's base path, plus `index.php/` and a `pathParam` value match (`?p=logout`) on installs
  that keep the script name in URLs.
- **A typed path is URL-pattern syntax unless escaped.** `/cart(` throws, and one throwing pattern
  gets the whole ruleset rejected. `Exclusion::literal()` backslash-escapes `\(){}+?` — but `\:`
  *also* throws in the string form (the parser takes it for a protocol separator); `{\:}` is the
  literal colon that works.
- **List rules have no `where`,** so the exclusions do not reach `prefetchUrls` or a template's
  `prefetch()` on their own. `Rules::document()` drops any named URL an exclusion covers.
- **The rules are public, so every exclusion in them is published.** A guest's copy naming a renamed
  `cpTrigger` gives it away. The control panel exclusions are `signedInOnly`; `where()`,
  `firstMatch()` and `explain()` take the audience so the guest document leaves them out.
- **The rules-file URL is per audience, and only the guest copy at the current `v` is `public`.**
  CDNs ignore `Vary: Cookie`; without `a=` in the URL a guest's prerender rules reach signed-in
  visitors, and without checking `a`/`v` a guest can seed the shared copy of the signed-in URL.
- **URL patterns have no case-insensitive flag.** `/*.pdf` does not match `/brochure.PDF`. A
  character class per letter (`[pP][dD][fF]`) is the only way, and all the extensions collapse into
  one alternation group — thirty-odd separate predicates were most of the bytes of a document sent
  on *every page*, which is no way for a performance plugin to behave. 3.9 KB → 2.0 KB.
- **The escaped `?` in `/*\?*(^|&)action=*` really does end the pathname and start the search
  component** — I expected it to stay a literal in the pathname and be dead. It does not: the
  parsed pattern is `path=/*  search=*(^|&)action=*`, and `?transaction=1` correctly does not match.
  Verified before relying on it.
- **`href_matches` accepts an object form per the spec, but `new URLPattern(init, baseURL)` throws**
  — you cannot pass a base string alongside an object. String form everywhere.
- **`Sec-Purpose: prefetch;prerender` contains the prefetch token.** "Is this a prefetch" cannot be
  `str_contains($value, 'prefetch')`, or every prerender classifies as a prefetch and whatever was
  guarded on it silently stops. Test prerender first.
- **`strripos` for `</body>`, never `stripos`.** The harness page contained a literal `</body>`
  inside another plugin's JavaScript comment, ~26 KB before the real one. Injecting at the first
  match would have landed inside that comment and broken the page.
- **`sendContentLengthHeader` stamps `content-length` during `prepare()`** — before
  `EVENT_AFTER_PREPARE`. Lengthening the body without restamping truncates the page at exactly the
  byte the script element started at, which presents as a broken template.
- **Craft does not render front-end templates as `FORMAT_HTML`** — it uses its own `template`
  format, whose MIME type comes from the template's file extension. Test the `Content-Type`, which
  also correctly leaves `feed.rss.twig` alone.
- **A multi-site install serves several origins.** Checking a URL against only `baseSiteUrl()`
  reports every link on the second site as cross-origin, which is exactly backwards. `origins()`
  reads every site, and normalises away default ports so `https://x` and `https://x:443` are the
  one origin they are.
- **Auth paths are `string|false`**, and an empty string is a real value meaning the site root —
  turning one into `/` would exclude every link there is. Skip anything that trims to empty.
- **`Vary: Sec-Purpose` is a correctness requirement, not a nicety.** The moment a speculated
  request is served something different, a shared cache that has not been told will store the
  rules-free copy and hand it to a real visitor.
- **`Request::getIsConsoleRequest()` reports on the *application*, not on the request** it is
  called on — so it is true for a perfectly good `craft\web\Request` whenever the app happens to be
  a console one. It made three response-pipeline checks fail against a fabricated request while the
  real HTTP path worked fine. `instanceof craft\web\Request` is the guard that means what it says.
- **`No-Vary-Search` has to be on the *prefetched* response.** That response is the one being
  cached, so gating the header on "this response carries rules" strips it from the only copy that
  needed it — and the whole tracking-parameter feature then fails silently in exactly the case it
  exists for. Hence `servesRules()` (headers) being a separate question from `shouldServe()` (the
  tag).
- **`JSON_HEX_TAG` on the inline tag.** A `<script>` holds raw text, so its content is never
  entity-decoded; escaping is not an option and not emitting the byte sequence is the only defence.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-speculatr/tests/integration/checks.php   # 118 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-speculatr/src -name "*.php" -print0 | xargs -0 -n1 php -l'
docker exec -w /var/www/craft-speculatr ddev-plugin-testing-web vendor/bin/phpstan analyse --memory-limit=1G   # level 4
docker exec -w /var/www/craft-speculatr ddev-plugin-testing-web vendor/bin/ecs check                          # src/ only
```

PHPStan and ECS need the plugin's *own* `vendor/` (gitignored), installed with
`docker exec -w /var/www/craft-speculatr ddev-plugin-testing-web composer install` — inside the plugin
folder, never the harness root. ECS skips `tests/`: the checks script lives inside one `try`, and the
fixer would re-indent all of it.

The checks touch no database and no project config: settings are changed on the in-memory model and
put back in a `finally`.

Live end-to-end: `tests/manual/speculatr-demo.twig` goes in the harness's `templates/`, then

```sh
curl -sk https://plugin-testing.ddev.site/speculatr-demo                          # rules present
curl -sk https://plugin-testing.ddev.site/speculatr-demo -H 'Sec-Purpose: prefetch'  # none
```

`tests/manual/dump-settings.php` prints the saved settings, for confirming a control panel save
round-tripped the editable tables.

The harness is shared with other sessions and gets restarted — and `composer` run from another
session will remove `vendor/craftcms` from under you mid-command. Wrap container commands in a
retry loop. `ddev exec` runs with `set -u`, so `docker exec ddev-plugin-testing-web …` is more
reliable for scripted work.

## Coding conventions

- `Craft::t('speculatr', '…')` for user-facing strings; `src/translations/en/speculatr.php`
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required` — it blocks fresh installs
- The response filter fails open. A page with no speculation rules is a page; a page that 500s
  because a performance feature threw is not.
