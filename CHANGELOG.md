# Release Notes for Speculatr

## 5.0.1 - 2026-10-03

### Security
- Exact path exclusions now also match the trailing-slash form, which Craft routes identically.
  `/logout/` was previously speculated for signed-in visitors when speculation was on for them.
- Craft's routes are now excluded under every site's subfolder (`/fr/logout`, `/fr/actions/…`),
  and through `index.php/…` and `?p=…` on installs that keep the script name in their URLs.
- The header-delivered rules file has a separate URL per audience, and only the guest copy at the
  current fingerprint is sent `public`, so a CDN that ignores `Vary: Cookie` cannot serve one
  audience's rules to the other, or be seeded by a guest under the signed-in URL.
- Named URLs — from the settings or a template's `prefetch()` / `prerender()` — that an exclusion
  covers are no longer emitted.
- The control panel exclusion is sent only in signed-in visitors' rules, so a renamed `cpTrigger`
  is not published on every public page.
- The control panel's Rules screen now requires a control panel request.

### Fixed
- URL-pattern syntax in a typed path (`(`, `:`, `{`, `+` …) is escaped, so one entry can no longer
  get the whole ruleset rejected by the browser.
- The `Speculation-Rules` header now carries a root-relative URL, so it always points at the
  origin the page was served from.

### Changed
- Plugin Store icon and control panel icon mask redrawn to the family format.
- `composer.json` now lists the homepage and the docs, source and issues links.

## 5.0.0

Initial release.

- Emits Speculation Rules on every front-end HTML response: prefetch, prerender, or both layered so
  the expensive half is held a step behind the cheap one.
- Assembles its exclusions from Craft's own configuration — `cpTrigger`, `actionTrigger`, every
  account path, `tokenParam`, `siteToken`, `csrfTokenName` and `resourceBaseUrl` — so a site that
  renamed any of them is covered without being asked.
- Excludes `.no-speculation`, `rel="nofollow"`, `[download]` and `target="_blank"` links, and
  anything that looks like a file rather than a page, case-insensitively.
- Per-audience rules for guests, signed-in users and admins, with prerendering degrading to
  prefetching for anyone signed in.
- `No-Vary-Search` and a matching `expects_no_vary_search`, so a prefetch survives the tracking
  parameters a reader arrives with.
- `Vary: Sec-Purpose` whenever a speculated request is served something different, so a shared
  cache cannot hand the rules-free copy to a real visitor.
- Inline or `Speculation-Rules` header delivery, the latter fingerprinted, for sites whose CSP will
  not allow inline speculation rules.
- A control panel screen showing the exact JSON, every exclusion with its reason, and a checker that
  says what would happen to one URL and which exclusion stopped it.
- `craft.speculatr` for per-page prefetching, exclusions, suppression and speculative-request
  detection.
- Console commands for the rules, the exclusions and the checker.
