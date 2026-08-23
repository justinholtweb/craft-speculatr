# Release Notes for Speculatr

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
