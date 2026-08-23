<?php

namespace justinholtweb\speculatr\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\web\Response;
use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\Plugin;

/**
 * Getting the rules into the response, and knowing when not to.
 *
 * Two questions live here and they are easy to conflate. The first is whether *this* response
 * should carry rules — a control panel page, a feed, a 404 and a page fetched by a speculation
 * should not. The second is whether the response varies on something a shared cache needs to know
 * about, which it does the moment the first question's answer depends on a request header.
 */
class Injector extends Component
{
    /** The header a browser sends on a request it made on spec. */
    public const SEC_PURPOSE = 'Sec-Purpose';

    /** Rules are appended immediately before this. */
    private const BODY_CLOSE = '</body>';

    // ------------------------------------------------------------------ speculative requests

    /** No speculation: somebody is navigating. */
    public const PURPOSE_NONE = '';

    /** The body is being downloaded, but nothing on the page is running. */
    public const PURPOSE_PREFETCH = 'prefetch';

    /** The whole page is being loaded and run in a hidden tab. */
    public const PURPOSE_PRERENDER = 'prerender';

    /**
     * What a `Sec-Purpose` header value means.
     *
     * A prerender's value is `prefetch;prerender` — it *includes* the prefetch token, because a
     * prerender is a prefetch that then went further. So "is this a prefetch" cannot be written as
     * "does the value contain prefetch", which is the mistake that makes every prerender look like
     * a plain prefetch and silently disables whatever was guarded on it.
     */
    public function classify(string $secPurpose): string
    {
        $value = strtolower(trim($secPurpose));

        if ($value === '') {
            return self::PURPOSE_NONE;
        }

        if (str_contains($value, 'prerender')) {
            return self::PURPOSE_PRERENDER;
        }

        if (str_contains($value, 'prefetch')) {
            return self::PURPOSE_PREFETCH;
        }

        return self::PURPOSE_NONE;
    }

    /** Whether this request was made by a browser guessing, rather than by somebody navigating. */
    public function isSpeculative(?Request $request = null): bool
    {
        return $this->classify($this->secPurpose($request)) !== self::PURPOSE_NONE;
    }

    /** Whether this request is a prefetch — the body only, with nothing on the page running. */
    public function isPrefetch(?Request $request = null): bool
    {
        return $this->classify($this->secPurpose($request)) === self::PURPOSE_PREFETCH;
    }

    /** Whether this request is a prerender — the whole page, in a hidden tab. */
    public function isPrerender(?Request $request = null): bool
    {
        return $this->classify($this->secPurpose($request)) === self::PURPOSE_PRERENDER;
    }

    /** The raw header, or an empty string outside a web request. */
    private function secPurpose(?Request $request = null): string
    {
        $request ??= Craft::$app->getRequest();

        if (!$request instanceof Request) {
            return '';
        }

        return (string)$request->getHeaders()->get(self::SEC_PURPOSE, '');
    }

    // ------------------------------------------------------------------ eligibility

    /**
     * Whether this response is a front-end page at all.
     *
     * The content *type* answers "is this a page"; the response format does not. Craft renders
     * front-end templates through its own `template` format and takes the MIME type from the
     * template's file extension, so `feed.rss.twig` and `manifest.json.twig` are template
     * responses that must be left alone.
     */
    public function isFrontEndPage(Response $response, Request $request): bool
    {
        if (!Plugin::getInstance()->getSettings()->enabled) {
            return false;
        }

        // Not `getIsConsoleRequest()` as well: that one reports on the *application*, not on the
        // request it is called on, so it is true for a perfectly good `craft\web\Request` whenever
        // the app happens to be a console one. The caller's `instanceof` is the real guard.
        if ($request->getIsCpRequest() || $request->getIsActionRequest()) {
            return false;
        }

        // A page reached with a preview or share token is somebody looking at unpublished content.
        // Speculating from it would spend the token on links nobody clicked.
        if ($request->getToken() !== null) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return $this->isHtml((string)$response->getHeaders()->get('content-type'));
    }

    /**
     * Whether this page participates in speculation at all — which is a different question from
     * whether *this particular response* carries the rules.
     *
     * This is the one that gates the headers. `No-Vary-Search` has to be on the **prefetched**
     * response, because that response is the one being cached: without it the entry never matches
     * `/pricing?utm_source=…`, which is precisely the case the whole feature exists for. Gating it
     * on "this response carries rules" would strip it from the only copy that needed it.
     *
     * `Vary: Sec-Purpose` likewise has to be on both variants, or a shared cache cannot know there
     * are two.
     */
    public function servesRules(Response $response, Request $request): bool
    {
        if (!$this->isFrontEndPage($response, $request)) {
            return false;
        }

        return !Plugin::getInstance()->getSettings()->excludesPage($request->getPathInfo());
    }

    /** Whether *this* response is the one that carries the rules. */
    public function shouldServe(Response $response, Request $request): bool
    {
        if (!$this->servesRules($response, $request)) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        return !($settings->skipOnSpeculative && $this->isSpeculative($request));
    }

    public function isHtml(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'text/html');
    }

    // ------------------------------------------------------------------ delivery

    /**
     * Puts the rules on a response, whichever way the site has asked for.
     *
     * Returns whether anything was actually added, which is what the caller needs in order to
     * decide about `content-length` — and nothing else in here is allowed to care.
     */
    public function serve(Response $response, Request $request): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $rules = Plugin::getInstance()->rules;
        $user = Craft::$app->getUser()->getIdentity();

        if ($settings->delivery === Settings::DELIVERY_HEADER) {
            return $this->serveByHeader($response, $rules, $user);
        }

        $tag = $rules->tag($user);

        return $tag !== '' && $this->append($response, $tag);
    }

    /**
     * Header delivery: the site-wide ruleset lives at its own URL, and anything a template added
     * for this page still goes inline.
     *
     * A rules file is one document for the whole site by definition — it is fetched once and
     * cached — so a per-page addition cannot travel in it. Sending the template's additions inline
     * alongside keeps `craft.speculatr.prefetch()` working in both delivery modes, which matters
     * because otherwise switching delivery silently breaks templates.
     */
    private function serveByHeader(Response $response, Rules $rules, $user): bool
    {
        if ($rules->isSuppressed() || !$rules->appliesTo($user)) {
            return false;
        }

        $url = UrlHelper::siteUrl('speculatr/rules.json', ['v' => $this->version()]);

        // A structured-field list of strings, so the URL is quoted.
        $response->getHeaders()->add('Speculation-Rules', '"' . $url . '"');

        if (!$rules->hasRuntimeAdditions()) {
            return false;
        }

        $json = (string)json_encode(
            $rules->runtimeDocument(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
        );

        return $this->append($response, '<script type="speculationrules">' . $json . '</script>');
    }

    /**
     * Splices markup in before `</body>`.
     *
     * The last one, because a `</body>` inside a code sample or a script string is somebody's
     * content and the real one is the final one in the document. A response with no `</body>` is a
     * fragment — an htmx swap, an Element API payload rendered as HTML — and gets left alone
     * rather than having a script element stapled to the end of it.
     */
    private function append(Response $response, string $markup): bool
    {
        $html = $response->content;

        if (!is_string($html) || $html === '') {
            return false;
        }

        $position = strripos($html, self::BODY_CLOSE);

        if ($position === false) {
            return false;
        }

        $response->content = substr($html, 0, $position) . $markup . substr($html, $position);

        return true;
    }

    /**
     * The two cache headers, which belong on every response of a page that speculates — including
     * the ones this plugin declines to put rules on.
     */
    public function addResponseHeaders(Response $response): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $this->addNoVarySearch($response, $settings);
        $this->addVary($response, $settings);
    }

    /**
     * Tells caches that the tracking parameters do not change the response.
     *
     * This is the half that does the work. `expects_no_vary_search` in the rules only stops the
     * browser racing its own prefetch; without this header the prefetched entry never matches the
     * tracked URL at all and the whole thing is decoration.
     */
    private function addNoVarySearch(Response $response, Settings $settings): void
    {
        $value = $settings->noVarySearch();

        if ($value !== null) {
            $response->getHeaders()->set('No-Vary-Search', $value);
        }
    }

    /**
     * `Vary: Sec-Purpose`, when the response genuinely depends on it.
     *
     * It does whenever speculative requests are served something different, which is exactly what
     * `skipOnSpeculative` arranges. A shared cache that has not been told will happily store the
     * rules-free copy fetched by a prefetch and hand it to the next real visitor, who then gets no
     * speculation at all — a bug that only appears behind a CDN and only intermittently.
     */
    private function addVary(Response $response, Settings $settings): void
    {
        if (!$settings->skipOnSpeculative || !$settings->varyOnSecPurpose) {
            return;
        }

        $headers = $response->getHeaders();
        $existing = (string)$headers->get('Vary', '');

        if ($existing === '') {
            $headers->set('Vary', self::SEC_PURPOSE);
            return;
        }

        $parts = array_map('trim', explode(',', $existing));

        foreach ($parts as $part) {
            if (strcasecmp($part, self::SEC_PURPOSE) === 0 || $part === '*') {
                return;
            }
        }

        $headers->set('Vary', $existing . ', ' . self::SEC_PURPOSE);
    }

    /**
     * A short fingerprint of the settings, so the rules file's URL changes when the rules do.
     *
     * Without it a browser that cached the file yesterday keeps yesterday's exclusions, which is
     * the wrong way round for a list whose whole job is to say "never speculate this".
     */
    public function version(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return substr(md5((string)json_encode($settings->toArray())), 0, 8);
    }

    /**
     * `content-length` is stamped during `prepare()` — before the event this all runs in — so
     * lengthening the body without restamping truncates the page at exactly the byte the script
     * element started at. That presents as a broken template rather than a broken header.
     */
    public function restampLength(Response $response): void
    {
        $headers = $response->getHeaders();

        if ($headers->get('content-length') !== null && is_string($response->content)) {
            $headers->set('content-length', (string)strlen($response->content));
        }
    }
}
