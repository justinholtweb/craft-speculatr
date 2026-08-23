<?php

namespace justinholtweb\speculatr\models;

use craft\base\Model;

/**
 * Speculatr settings.
 *
 * The defaults are the plugin doing something useful and hard to regret: guests get prerendering
 * at `moderate` eagerness, signed-in visitors get nothing, and every page Craft would be unhappy
 * to have opened behind the reader's back is already excluded.
 *
 * Nothing in here is `required`. A required plugin setting blocks a fresh install, because Craft
 * validates the settings model before the plugin has ever been configured.
 */
class Settings extends Model
{
    /** Download the response body only. Cheap, safe, and does not run the page. */
    public const MODE_PREFETCH = 'prefetch';

    /** Load and render the whole page in a hidden tab. Expensive, and the navigation is instant. */
    public const MODE_PRERENDER = 'prerender';

    /** Prefetch broadly and prerender at one step more conservative, so the two layer. */
    public const MODE_BOTH = 'both';

    /**
     * Eagerness, least to most keen. The order is load-bearing: `stepDown()` walks it.
     *
     * - `conservative` — on pointer or touch down. The reader has committed.
     * - `moderate` — ~200 ms of hover, or pointerdown. Chrome also uses viewport heuristics on mobile.
     * - `eager` — ~10 ms of hover. Nearly anything the pointer crosses.
     * - `immediate` — as soon as the rules are seen, without any interaction at all.
     */
    public const EAGERNESS = ['conservative', 'moderate', 'eager', 'immediate'];

    /** A `<script type="speculationrules">` in the page. */
    public const DELIVERY_INLINE = 'inline';

    /** A `Speculation-Rules` header pointing at a JSON document. Survives a strict CSP. */
    public const DELIVERY_HEADER = 'header';

    /**
     * File extensions never worth speculating on, because the browser is not going to render them
     * and a prerender of a 40 MB download is a 40 MB download nobody asked for.
     */
    public const DEFAULT_EXTENSIONS = [
        'pdf', 'zip', 'gz', 'tgz', 'rar', '7z', 'dmg', 'exe', 'pkg', 'msi',
        'csv', 'xls', 'xlsx', 'doc', 'docx', 'ppt', 'pptx', 'odt', 'ods',
        'mp3', 'mp4', 'mov', 'avi', 'mkv', 'wav', 'webm',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico',
    ];

    /**
     * Query parameters that name where a visitor came from and never change what they are shown.
     *
     * These are the ones worth telling the browser to ignore, so a prefetch of `/pricing` is still
     * a hit when the reader arrives at `/pricing?utm_source=newsletter`.
     */
    public const DEFAULT_TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'igshid',
        'mc_cid', 'mc_eid', 'ref', 'referrer',
    ];

    // ------------------------------------------------------------------ the master switch

    /**
     * @var bool Off means no rules anywhere, no headers, and `craft.speculatr.tag()` returns
     *           nothing. Everything else is left exactly as configured.
     */
    public bool $enabled = true;

    // ------------------------------------------------------------------ what to do

    /**
     * @var string Whether matched links are prefetched, prerendered, or both.
     *
     * A prefetch downloads the HTML and nothing else — a few kilobytes, no scripts run, no
     * analytics fire. A prerender loads the entire page in a hidden tab, subresources and
     * JavaScript included, so the navigation is genuinely instant and the cost is a whole page
     * load that may never be used.
     */
    public string $mode = self::MODE_PRERENDER;

    /**
     * @var string How keen the browser should be. `moderate` waits for about 200 ms of hover,
     *             which is roughly the point at which somebody has decided to click.
     */
    public string $eagerness = 'moderate';

    // ------------------------------------------------------------------ who gets it

    /** @var bool Whether signed-out visitors get speculation rules. */
    public bool $forGuests = true;

    /**
     * @var bool Whether signed-in users get them. Off by default: a prerender runs the page's
     *           JavaScript, and a page rendered for a specific person is the page most likely to
     *           be expensive, uncacheable, or carrying something one-shot.
     */
    public bool $forLoggedIn = false;

    /** @var bool Whether admins get them. Useful for trying the thing out on yourself. */
    public bool $forAdmins = false;

    /**
     * @var bool Whether prerendering degrades to prefetching for anyone signed in.
     *
     * The compromise setting. Signed-in visitors still get instant-ish navigation from the
     * prefetch cache, without a hidden tab running their personalised page's scripts.
     */
    public bool $downgradeForLoggedIn = true;

    // ------------------------------------------------------------------ what never to speculate

    /**
     * @var string A class on an anchor that opts it out. Anything wearing it is left alone in
     *             every mode.
     */
    public string $optOutClass = 'no-speculation';

    /** @var bool Whether `rel="nofollow"` links are excluded. */
    public bool $excludeNofollow = true;

    /** @var bool Whether `download` links, and links to files, are excluded. */
    public bool $excludeDownloads = true;

    /**
     * @var bool Whether links that open in a new tab are excluded. Without a target hint a
     *           prerender for `target="_blank"` is thrown away on activation, so it is pure cost.
     */
    public bool $excludeNewWindow = true;

    /**
     * @var bool Whether *any* URL carrying a query string is excluded. Blunt, and the right
     *           answer for a site whose query strings mean "do something".
     */
    public bool $excludeQueryStrings = false;

    /** @var string[] Extra URI patterns, `*` wildcards, leading slash optional. */
    public array $excludePaths = [];

    /** @var string[] Extra query parameter names. A URL carrying one is never speculated. */
    public array $excludeParams = [];

    /** @var string[] Extra CSS selectors. An anchor matching one is never speculated. */
    public array $excludeSelectors = [];

    /** @var string[] File extensions treated as downloads rather than pages. */
    public array $excludeExtensions = self::DEFAULT_EXTENSIONS;

    // ------------------------------------------------------------------ named URLs

    /**
     * @var string[] URLs prefetched immediately on every page, whether or not anything links to
     *               them. For the two or three pages everybody ends up on.
     *
     * Always prefetched and never prerendered: an unconditional prerender of a handful of pages
     * on every single page view is a lot of page loads to spend on a guess.
     */
    public array $prefetchUrls = [];

    // ------------------------------------------------------------------ tracking parameters

    /**
     * @var bool Whether the tracking parameters below are declared not to change the page.
     *
     * Sends `No-Vary-Search` on front-end responses and mirrors it into the rules as
     * `expects_no_vary_search`, so a prefetch of `/pricing` is reused when the reader actually
     * arrives at `/pricing?utm_source=newsletter`. Both halves are needed: the header is what
     * makes the cache entry match, and the rule is what stops the browser racing it.
     */
    public bool $ignoreTrackingParams = true;

    /** @var string[] The parameters that do not change the response. */
    public array $trackingParams = self::DEFAULT_TRACKING_PARAMS;

    // ------------------------------------------------------------------ delivery

    /**
     * @var string How the rules reach the browser. Inline is a script element in the page; header
     *             sends `Speculation-Rules` pointing at a JSON document, which is the answer for a
     *             site whose Content-Security-Policy will not allow inline speculation rules.
     */
    public string $delivery = self::DELIVERY_INLINE;

    /**
     * @var bool Whether a page fetched *by* a speculation is served rules of its own. Off would
     *           let a prerender start prerendering, and the second hop is a guess about a guess.
     */
    public bool $skipOnSpeculative = true;

    /**
     * @var bool Whether `Vary: Sec-Purpose` is sent when the response depends on it.
     *
     * It does depend on it whenever the setting above is on, and a shared cache that does not
     * know that will hand a rules-free prefetched copy to a real visitor.
     */
    public bool $varyOnSecPurpose = true;

    /** @var string[] URI patterns whose *own* pages carry no rules. Leading slash optional. */
    public array $excludePages = [];

    public function rules(): array
    {
        return [
            [['mode'], 'in', 'range' => [self::MODE_PREFETCH, self::MODE_PRERENDER, self::MODE_BOTH]],
            [['eagerness'], 'in', 'range' => self::EAGERNESS],
            [['delivery'], 'in', 'range' => [self::DELIVERY_INLINE, self::DELIVERY_HEADER]],
            [['optOutClass'], 'match', 'pattern' => '/^[A-Za-z_-][A-Za-z0-9_-]*$/', 'skipOnEmpty' => true],
            [
                ['excludePaths', 'excludeParams', 'excludeSelectors', 'excludeExtensions',
                    'prefetchUrls', 'trackingParams', 'excludePages'],
                'validateList',
                'skipOnEmpty' => false,
            ],
            [['excludeParams', 'trackingParams'], 'validateParamNames', 'skipOnEmpty' => false],
            [['excludeExtensions'], 'validateExtensions', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Craft's editable tables post rows, not strings.
     *
     * The value arriving from the settings form is `[['value' => 'utm_source'], …]` and the value
     * arriving from a config file is `['utm_source']`. Both have to end up as the second, and
     * `skipOnEmpty => false` matters because clearing a table is the one case a normaliser on a
     * non-empty value never sees.
     */
    public function validateList(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_array($value)) {
            $this->$attribute = [];
            return;
        }

        $out = [];

        foreach ($value as $row) {
            $item = is_array($row) ? ($row['value'] ?? reset($row)) : $row;
            $item = is_string($item) ? trim($item) : '';

            if ($item !== '') {
                $out[] = $item;
            }
        }

        $this->$attribute = array_values(array_unique($out));
    }

    /**
     * A parameter name goes into a URL pattern verbatim, so anything that is not a parameter name
     * is dropped rather than escaped into something that matches by accident.
     */
    public function validateParamNames(string $attribute): void
    {
        $this->$attribute = array_values(array_filter(
            $this->$attribute,
            static fn($name) => is_string($name) && preg_match('/^[A-Za-z0-9_.\[\]-]+$/', $name) === 1,
        ));
    }

    public function validateExtensions(string $attribute): void
    {
        $out = [];

        foreach ($this->$attribute as $extension) {
            $extension = strtolower(ltrim(trim((string)$extension), '.'));

            if (preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1) {
                $out[] = $extension;
            }
        }

        $this->$attribute = array_values(array_unique($out));
    }

    /**
     * One step less keen, for the prerender half of `both`.
     *
     * Prerendering everything a prefetch would cover is how a site turns a performance feature
     * into a load test of itself, so the expensive half is always held back a notch.
     */
    public static function stepDown(string $eagerness): string
    {
        $index = array_search($eagerness, self::EAGERNESS, true);

        if ($index === false || $index === 0) {
            return self::EAGERNESS[0];
        }

        return self::EAGERNESS[$index - 1];
    }

    /** The `No-Vary-Search` value these settings describe, or null if there is nothing to say. */
    public function noVarySearch(): ?string
    {
        if (!$this->ignoreTrackingParams || $this->trackingParams === []) {
            return null;
        }

        $quoted = array_map(static fn(string $param) => '"' . $param . '"', $this->trackingParams);

        return 'params=(' . implode(' ', $quoted) . ')';
    }

    /** Whether a page at this URI is served rules at all. */
    public function excludesPage(string $uri): bool
    {
        $uri = '/' . ltrim($uri, '/');

        foreach ($this->excludePages as $pattern) {
            $pattern = '/' . ltrim(trim((string)$pattern), '/');

            if ($pattern !== '/' && fnmatch($pattern, $uri, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
