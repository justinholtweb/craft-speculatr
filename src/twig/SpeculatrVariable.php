<?php

namespace justinholtweb\speculatr\twig;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\speculatr\models\Verdict;
use justinholtweb\speculatr\Plugin;
use Twig\Markup;

/**
 * `craft.speculatr` — for the decisions a template is in a better position to make than a settings
 * screen.
 *
 * A page knows things the configuration cannot: that this particular listing's first result is
 * where nearly everybody goes next, or that this page is the one step of a checkout that must
 * never be opened early.
 */
class SpeculatrVariable
{
    // ------------------------------------------------------------------ what is happening now

    /** Whether this request was made by a browser guessing rather than by somebody navigating. */
    public function getIsSpeculative(): bool
    {
        return Plugin::getInstance()->injector->isSpeculative();
    }

    /** Whether this request is a prefetch: the HTML is being downloaded, but nothing is running. */
    public function getIsPrefetch(): bool
    {
        return Plugin::getInstance()->injector->isPrefetch();
    }

    /**
     * Whether this request is a prerender.
     *
     * The page is being loaded and run in a hidden tab. Worth checking before anything that should
     * happen when a person arrives rather than when a browser guesses — recording a view, counting
     * a hit, starting a video.
     */
    public function getIsPrerender(): bool
    {
        return Plugin::getInstance()->injector->isPrerender();
    }

    public function getEnabled(): bool
    {
        return Plugin::getInstance()->getSettings()->enabled;
    }

    // ------------------------------------------------------------------ changing this page

    /** Switches speculation rules off for this page. */
    public function disable(): void
    {
        Plugin::getInstance()->rules->suppress();
    }

    /**
     * Excludes a path pattern from this page's rules. `*` wildcards, leading slash optional.
     *
     * For the exclusion that is true of the page you are on rather than of the site — a listing
     * whose links carry a filter parameter, say.
     */
    public function exclude(string $pattern, string $reason = ''): void
    {
        Plugin::getInstance()->exclusions->addPath($pattern, $reason);
    }

    /**
     * Prefetches specific URLs from this page, whether or not anything links to them.
     *
     * Takes URLs, elements, or a mix. `immediate` by default, because naming a URL explicitly is
     * already the decision the eagerness levels exist to defer.
     *
     * @param mixed $urls A URL, an element, or an array of either.
     */
    public function prefetch(mixed $urls, string $eagerness = 'immediate'): void
    {
        Plugin::getInstance()->rules->addUrls($this->resolve($urls), 'prefetch', $eagerness);
    }

    /**
     * Prerenders specific URLs from this page.
     *
     * Sparingly. Each one is an entire page load — scripts and all — spent before anybody has
     * clicked anything, and Chrome will only hold a couple at a time.
     *
     * @param mixed $urls A URL, an element, or an array of either.
     */
    public function prerender(mixed $urls, string $eagerness = 'moderate'): void
    {
        Plugin::getInstance()->rules->addUrls($this->resolve($urls), 'prerender', $eagerness);
    }

    // ------------------------------------------------------------------ seeing the rules

    /**
     * The rules for this request, ready to print.
     *
     * Only needed by a site that has turned automatic injection off, or one that wants the script
     * element somewhere specific.
     */
    public function tag(): Markup
    {
        $tag = Plugin::getInstance()->rules->tag(Craft::$app->getUser()->getIdentity());

        return new Markup($tag, Craft::$app->charset);
    }

    /** The rules as JSON, for looking at. */
    public function json(bool $pretty = true): string
    {
        return Plugin::getInstance()->rules->json(Craft::$app->getUser()->getIdentity(), $pretty);
    }

    /** @return array<string, mixed> The rules as an array. */
    public function document(): array
    {
        return Plugin::getInstance()->rules->document(Craft::$app->getUser()->getIdentity());
    }

    /** What would happen to a link to this URL, and why. */
    public function explain(string $url): Verdict
    {
        return Plugin::getInstance()->rules->explain($url, Craft::$app->getUser()->getIdentity());
    }

    /**
     * Elements become their URLs; anything else is trusted as one.
     *
     * @return string[]
     */
    private function resolve(mixed $urls): array
    {
        $out = [];

        foreach (is_array($urls) ? $urls : [$urls] as $url) {
            if ($url instanceof ElementInterface) {
                $url = $url->getUrl();
            }

            if (is_string($url) && trim($url) !== '') {
                $out[] = trim($url);
            }
        }

        return $out;
    }
}
