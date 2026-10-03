<?php

namespace justinholtweb\speculatr\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\UrlHelper;
use justinholtweb\speculatr\models\Exclusion;
use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\models\Verdict;
use justinholtweb\speculatr\Plugin;

/**
 * The speculation rules document itself.
 *
 * One document rule per action, whose `where` clause is "every same-origin link, except all of
 * these" — plus a list rule for any URLs somebody named explicitly.
 *
 * The exclusions are emitted as individual `not` predicates rather than one `not` over an array of
 * patterns. The array form is in the specification and would be shorter on the wire, but the
 * individual form is what Chrome documents and what is deployed at scale elsewhere, and a rules
 * document a browser silently declines to parse fails in exactly the way nobody notices: the page
 * still works, and the feature simply never happens.
 */
class Rules extends Component
{
    /** @var array<int, array{action: string, urls: string[], eagerness: string}> Added by templates. */
    private array $runtimeUrls = [];

    /** @var bool Whether a template has switched this page's rules off. */
    private bool $suppressed = false;

    // ------------------------------------------------------------------ audience

    /**
     * Whether this visitor is served rules at all.
     *
     * An admin is also signed in, so either switch is enough for them. That reads oddly written
     * down and is what everyone expects in practice: turning on "administrators" while leaving
     * "signed-in users" off is how you try the thing out on yourself.
     */
    public function appliesTo(?User $user): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($user === null) {
            return $settings->forGuests;
        }

        if ($user->admin && $settings->forAdmins) {
            return true;
        }

        return $settings->forLoggedIn;
    }

    /**
     * The mode actually used for this visitor.
     *
     * The downgrade is the compromise that makes signed-in speculation defensible: a prefetch
     * downloads HTML and stops, so nothing on the page runs, nothing fires, and the navigation is
     * still most of the way to instant.
     */
    public function effectiveMode(?User $user): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($user === null || !$settings->downgradeForLoggedIn) {
            return $settings->mode;
        }

        return match ($settings->mode) {
            Settings::MODE_PRERENDER, Settings::MODE_BOTH => Settings::MODE_PREFETCH,
            default => $settings->mode,
        };
    }

    // ------------------------------------------------------------------ building

    /**
     * The whole document, ready for `json_encode`.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function document(?User $user = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled || $this->suppressed || !$this->appliesTo($user)) {
            return [];
        }

        $mode = $this->effectiveMode($user);
        $document = [];

        $where = $this->where($user);
        $noVarySearch = $settings->noVarySearch();

        // `both` prefetches at the configured eagerness and prerenders one step behind it. The two
        // layer rather than compete: the browser prefetches on hover and upgrades the same URL to
        // a prerender once the reader commits, so the expensive half is never spent on a link the
        // pointer merely crossed.
        if ($mode === Settings::MODE_PREFETCH || $mode === Settings::MODE_BOTH) {
            $document['prefetch'][] = $this->documentRule($where, $settings->eagerness, $noVarySearch);
        }

        if ($mode === Settings::MODE_PRERENDER || $mode === Settings::MODE_BOTH) {
            $eagerness = $mode === Settings::MODE_BOTH
                ? Settings::stepDown($settings->eagerness)
                : $settings->eagerness;

            $document['prerender'][] = $this->documentRule($where, $eagerness, $noVarySearch);
        }

        // Named URLs are prefetched and never prerendered. An unconditional prerender of a handful
        // of pages, on every page view, is a lot of page loads to spend on a guess about where
        // somebody is going next.
        //
        // A URL is emitted once per action however many places asked for it. The settings list and
        // a template's `prefetch()` very often name the same page, and two list rules for one URL
        // is bytes on every page view buying nothing.
        $seen = [];

        foreach ([...$this->settingsListRules($settings), ...$this->runtimeUrls] as $rule) {
            $action = $rule['action'];
            $urls = array_values(array_diff($rule['urls'], $seen[$action] ?? []));

            // A list rule has no `where`, so the exclusions would not apply to it on their own.
            // Naming `/logout` in the settings, or a template prefetching a URL it was handed,
            // must not be a way around the one thing this plugin exists to guarantee.
            $urls = array_values(array_filter($urls, fn(string $url) => !$this->isExcludedUrl($url, $user === null)));

            if ($urls === []) {
                continue;
            }

            $seen[$action] = [...($seen[$action] ?? []), ...$urls];

            $document[$action][] = [
                'source' => 'list',
                'urls' => $urls,
                'eagerness' => $rule['eagerness'],
            ];
        }

        return $document;
    }

    /** Whether an exclusion covers a named URL; logs it when one does, so the drop is findable. */
    private function isExcludedUrl(string $url, bool $guest): bool
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);

        $exclusion = Plugin::getInstance()->exclusions->firstMatch($path, $params, $guest);

        if ($exclusion === null) {
            return false;
        }

        Craft::warning("Not speculating $url, which is excluded: $exclusion->reason", 'speculatr');

        return true;
    }

    /**
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function documentRule(array $where, string $eagerness, ?string $noVarySearch): array
    {
        $rule = [
            'source' => 'document',
            'where' => $where,
            'eagerness' => $eagerness,
        ];

        // Without this the browser starts a *second* fetch when the reader arrives at the tracked
        // URL, and the prefetch it already has sits unused. The matching `No-Vary-Search` response
        // header is what makes the entry match at all; this only stops the race.
        if ($noVarySearch !== null) {
            $rule['expects_no_vary_search'] = $noVarySearch;
        }

        return $rule;
    }

    /**
     * The `where` clause: every same-origin link, minus everything excluded.
     *
     * `href_matches: "/*"` is what confines this to the current origin — a relative pattern
     * inherits the document's protocol, host and port, so a link to somebody else's site never
     * matches in the first place.
     *
     * @return array<string, mixed>
     */
    public function where(?User $user = null): array
    {
        $exclusions = Plugin::getInstance()->exclusions;

        $predicates = [
            ['href_matches' => '/*', 'relative_to' => 'document'],
        ];

        foreach ($exclusions->urlExclusions($user === null) as $exclusion) {
            foreach ($exclusion->patterns() as $pattern) {
                $predicates[] = ['not' => ['href_matches' => $pattern, 'relative_to' => 'document']];
            }
        }

        foreach ($exclusions->selectorExclusions() as $exclusion) {
            $predicates[] = ['not' => ['selector_matches' => $exclusion->selectorPattern()]];
        }

        return ['and' => $predicates];
    }

    /**
     * The settings' named URLs, in the same shape a template's addition arrives in.
     *
     * @return array<int, array{action: string, urls: string[], eagerness: string}>
     */
    private function settingsListRules(Settings $settings): array
    {
        $urls = $this->listUrls($settings);

        return $urls === [] ? [] : [['action' => 'prefetch', 'urls' => $urls, 'eagerness' => 'immediate']];
    }

    /**
     * The named URLs, resolved and kept to this origin.
     *
     * A cross-origin entry here would be dropped by the browser anyway; dropping it in PHP means
     * the control panel can show what is actually being sent.
     *
     * @return string[]
     */
    private function listUrls(Settings $settings): array
    {
        $out = [];

        foreach ($settings->prefetchUrls as $url) {
            $url = trim((string)$url);

            if ($url === '') {
                continue;
            }

            if (!UrlHelper::isAbsoluteUrl($url) && !str_starts_with($url, '/')) {
                $url = '/' . $url;
            }

            if (UrlHelper::isAbsoluteUrl($url)) {
                $trimmed = null;

                foreach ($this->origins() as $origin) {
                    if (str_starts_with($url, $origin . '/') || $url === $origin) {
                        $trimmed = substr($url, strlen($origin));
                        break;
                    }
                }

                // A cross-origin list entry would be dropped by the browser anyway. Dropping it
                // here means the control panel shows what is actually being sent.
                if ($trimmed === null) {
                    continue;
                }

                $url = $trimmed === '' ? '/' : $trimmed;
            }

            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------------ serialising

    /** The document as JSON, or an empty string when there is nothing to say. */
    public function json(?User $user = null, bool $pretty = false): string
    {
        $document = $this->document($user);

        if ($document === []) {
            return '';
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (string)json_encode($document, $flags);
    }

    /**
     * The script element.
     *
     * The JSON goes in raw. A `<script>` element holds raw text under the HTML parsing spec, so
     * entities in it are never decoded — escaping it would put a literal `&quot;` into the rules
     * and the browser would decline the lot. `JSON_HEX_TAG` handles the only sequence that could
     * end the element early.
     */
    public function tag(?User $user = null): string
    {
        $document = $this->document($user);

        if ($document === []) {
            return '';
        }

        $json = (string)json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
        );

        return '<script type="speculationrules">' . $json . '</script>';
    }

    // ------------------------------------------------------------------ explaining

    /**
     * What would happen to a link to this URL, and why.
     *
     * The control panel's answer to "so is it prerendering my checkout page or not". Every URL
     * exclusion is evaluated in PHP against the same value its emitted pattern was built from;
     * selector exclusions cannot be answered from a URL alone and are reported as such rather
     * than quietly ignored.
     */
    public function explain(string $url, ?User $user = null): Verdict
    {
        $settings = Plugin::getInstance()->getSettings();
        $verdict = new Verdict(['url' => $url]);

        if (!$settings->enabled) {
            $verdict->reason = Craft::t('speculatr', 'Speculatr is turned off.');
            return $verdict;
        }

        if (!$this->appliesTo($user)) {
            $verdict->reason = $user === null
                ? Craft::t('speculatr', 'Signed-out visitors are not served rules.')
                : Craft::t('speculatr', 'Signed-in users are not served rules.');
            return $verdict;
        }

        $parsed = parse_url($url);

        if ($parsed === false) {
            $verdict->reason = Craft::t('speculatr', 'That is not a URL.');
            return $verdict;
        }

        if (isset($parsed['host']) && !$this->isSameOrigin($parsed)) {
            $verdict->reason = Craft::t(
                'speculatr',
                'Another origin. `href_matches: "/*"` only matches links on the site’s own origin — {origins}.',
                ['origins' => implode(', ', $this->origins())],
            );
            return $verdict;
        }

        $path = $parsed['path'] ?? '/';
        $params = [];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
        }

        $blocked = Plugin::getInstance()->exclusions->firstMatch($path, $params, $user === null);

        if ($blocked !== null) {
            $verdict->blockedBy = $blocked;
            $verdict->reason = $blocked->reason;
            return $verdict;
        }

        $mode = $this->effectiveMode($user);

        $verdict->speculated = true;
        $verdict->prefetch = $mode === Settings::MODE_PREFETCH || $mode === Settings::MODE_BOTH;
        $verdict->prerender = $mode === Settings::MODE_PRERENDER || $mode === Settings::MODE_BOTH;
        $verdict->eagerness = $settings->eagerness;
        $verdict->prerenderEagerness = $mode === Settings::MODE_BOTH
            ? Settings::stepDown($settings->eagerness)
            : $settings->eagerness;

        $selectors = array_map(
            static fn(Exclusion $e) => (string)$e->selectorPattern(),
            Plugin::getInstance()->exclusions->selectorExclusions(),
        );

        if ($selectors !== []) {
            $verdict->caveats[] = Craft::t(
                'speculatr',
                'Unless the link itself matches one of: {selectors}',
                ['selectors' => implode(', ', $selectors)],
            );
        }

        return $verdict;
    }

    // ------------------------------------------------------------------ runtime additions

    /**
     * @param string|array<mixed> $urls A template can hand over anything; non-strings are dropped.
     */
    public function addUrls(string|array $urls, string $action = 'prefetch', string $eagerness = 'immediate'): void
    {
        $urls = array_values(array_filter(array_map(
            static fn($url) => is_string($url) ? trim($url) : '',
            is_array($urls) ? $urls : [$urls],
        )));

        if ($urls === []) {
            return;
        }

        $this->runtimeUrls[] = [
            'action' => $action === 'prerender' ? 'prerender' : 'prefetch',
            'urls' => $urls,
            'eagerness' => in_array($eagerness, Settings::EAGERNESS, true) ? $eagerness : 'immediate',
        ];
    }

    /** Switches rules off for this page. */
    public function suppress(): void
    {
        $this->suppressed = true;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    /** Whether anything was added by a template, which header delivery cannot carry on its own. */
    public function hasRuntimeAdditions(): bool
    {
        return $this->runtimeUrls !== [];
    }

    /**
     * Just the template's own additions, for the inline companion tag in header delivery.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function runtimeDocument(): array
    {
        $document = [];

        foreach ($this->runtimeUrls as $rule) {
            $document[$rule['action']][] = [
                'source' => 'list',
                'urls' => $rule['urls'],
                'eagerness' => $rule['eagerness'],
            ];
        }

        return $document;
    }

    /** Forgets everything a template did. The checks reuse one application. */
    public function reset(): void
    {
        $this->runtimeUrls = [];
        $this->suppressed = false;
    }

    /**
     * Every origin this Craft install serves pages from.
     *
     * Plural, and it matters. A multi-site install answers on several hostnames, and a link is
     * same-origin relative to *the page it is on* — so checking only the primary site's base URL
     * reports every link on the second site as cross-origin, which is exactly backwards.
     *
     * @return string[]
     */
    public function origins(): array
    {
        $out = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $origin = $this->normalizeOrigin((string)$site->getBaseUrl());

            if ($origin !== null) {
                $out[$origin] = true;
            }
        }

        if ($out === []) {
            $origin = $this->normalizeOrigin(UrlHelper::baseSiteUrl());

            if ($origin !== null) {
                $out[$origin] = true;
            }
        }

        return array_keys($out);
    }

    /** @param array<string, mixed> $parsed A `parse_url` result. */
    private function isSameOrigin(array $parsed): bool
    {
        $scheme = strtolower((string)($parsed['scheme'] ?? 'https'));
        $candidate = $this->normalizeOrigin(
            $scheme . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : ''),
        );

        if ($candidate === null) {
            return false;
        }

        foreach ($this->origins() as $origin) {
            if ($origin === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * `scheme://host:port`, with the scheme's default port dropped.
     *
     * So `https://example.com` and `https://example.com:443` are the one origin they actually are,
     * rather than two that never match.
     */
    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url($url);

        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;

        if ($port !== null && $port === $default) {
            $port = null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '');
    }
}
