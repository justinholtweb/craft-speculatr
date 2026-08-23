<?php

namespace justinholtweb\speculatr\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use justinholtweb\speculatr\models\Exclusion;
use justinholtweb\speculatr\Plugin;

/**
 * Everything that must never be speculated, and why.
 *
 * The load-bearing idea of the whole plugin lives here. Prefetching is a performance feature;
 * prerendering is a performance feature that also *runs the page*. A rule that says "speculate
 * every link" is a rule that eventually opens the one link that logs somebody out, burns a
 * one-time token, or adds something to a cart — behind their back, from a hidden tab, before they
 * had decided to click.
 *
 * So the default answer is assembled from Craft's own configuration rather than from a list of
 * guesses. Craft already knows where the control panel lives, what its action trigger is, what
 * the logout path is and which query parameters mean "this URL is a credential". Speculatr reads
 * those, so a site with `cpTrigger` set to something other than `admin` is protected without
 * anybody remembering to say so.
 */
class Exclusions extends Component
{
    /** @var Exclusion[]|null Built once per request. */
    private ?array $memo = null;

    /** @var Exclusion[] Added by templates, for this request only. */
    private array $runtime = [];

    /**
     * Everything excluded, in the order it should be read: Craft's own configuration first,
     * because that is the part nobody had to think about.
     *
     * @return Exclusion[]
     */
    public function all(): array
    {
        $this->memo ??= [
            ...$this->fromCraft(),
            ...$this->builtIn(),
            ...$this->fromSettings(),
        ];

        return [...$this->memo, ...$this->runtime];
    }

    /** @return Exclusion[] Just the URL-pattern ones. */
    public function urlExclusions(): array
    {
        return array_values(array_filter($this->all(), static fn(Exclusion $e) => !$e->isSelector()));
    }

    /** @return Exclusion[] Just the ones that are a fact about an anchor rather than a URL. */
    public function selectorExclusions(): array
    {
        return array_values(array_filter($this->all(), static fn(Exclusion $e) => $e->isSelector()));
    }

    /** Adds an exclusion for this request only. Templates reach this through `craft.speculatr`. */
    public function addPath(string $pattern, string $reason = ''): void
    {
        $this->runtime[] = Exclusion::path(
            $pattern,
            $reason !== '' ? $reason : Craft::t('speculatr', 'Added by a template'),
            Exclusion::SOURCE_TEMPLATE,
        );
    }

    /**
     * The exclusions Craft's own configuration implies.
     *
     * @return Exclusion[]
     */
    public function fromCraft(): array
    {
        $general = Craft::$app->getConfig()->getGeneral();
        $out = [];

        // The control panel. `cpTrigger` is null when the CP is served from its own hostname, in
        // which case a same-origin rule cannot reach it anyway.
        $cpTrigger = $general->cpTrigger;

        if (is_string($cpTrigger) && trim($cpTrigger, '/') !== '') {
            $out[] = Exclusion::path(
                trim($cpTrigger, '/') . '/*',
                Craft::t('speculatr', 'The control panel (`cpTrigger`)'),
                Exclusion::SOURCE_CRAFT,
            );
        }

        // Action requests. Prerendering one of these does not load a page — it *performs the
        // action*, which is the single worst thing this plugin could be talked into doing.
        $actionTrigger = trim((string)$general->actionTrigger, '/');

        if ($actionTrigger !== '') {
            $out[] = Exclusion::path(
                $actionTrigger . '/*',
                Craft::t('speculatr', 'Action requests (`actionTrigger`)'),
                Exclusion::SOURCE_CRAFT,
            );
        }

        // The same thing again, as a query parameter. Craft routes `?action=…` whatever the path
        // is, so excluding only the path segment leaves the door open.
        $out[] = Exclusion::param(
            'action',
            Craft::t('speculatr', 'Action requests by query parameter'),
            Exclusion::SOURCE_CRAFT,
        );

        // Anything that signs somebody in, out, or sets a password. Prerendering a logout is how
        // a reader gets signed out by hovering over the link.
        $authPaths = [
            'loginPath' => Craft::t('speculatr', 'The login page (`loginPath`)'),
            'logoutPath' => Craft::t('speculatr', 'The logout URL (`logoutPath`)'),
            'setPasswordPath' => Craft::t('speculatr', 'Setting a password (`setPasswordPath`)'),
            'setPasswordRequestPath' => Craft::t('speculatr', 'Requesting a password reset (`setPasswordRequestPath`)'),
            'setPasswordSuccessPath' => Craft::t('speculatr', 'Password set (`setPasswordSuccessPath`)'),
            'verifyEmailPath' => Craft::t('speculatr', 'Email verification (`verifyEmailPath`)'),
            'activateAccountSuccessPath' => Craft::t('speculatr', 'Account activated (`activateAccountSuccessPath`)'),
            'invalidUserTokenPath' => Craft::t('speculatr', 'Expired user token (`invalidUserTokenPath`)'),
        ];

        foreach ($authPaths as $property => $reason) {
            $path = $general->$property ?? null;

            // These are `string|false`, and an empty string is a real configured value meaning the
            // site root — which must not be turned into an exclusion of everything.
            if (!is_string($path) || trim($path, '/') === '') {
                continue;
            }

            $out[] = Exclusion::path(trim($path, '/'), $reason, Exclusion::SOURCE_CRAFT);
        }

        // Craft's tokens. A preview or share token has a duration and, often, a usage limit; a
        // speculation that spends one is a link the recipient then cannot open.
        $tokenParam = trim((string)$general->tokenParam);

        if ($tokenParam !== '') {
            $out[] = Exclusion::param(
                $tokenParam,
                Craft::t('speculatr', 'Preview and share tokens (`tokenParam`)'),
                Exclusion::SOURCE_CRAFT,
            );
        }

        $siteToken = trim((string)$general->siteToken);

        if ($siteToken !== '') {
            $out[] = Exclusion::param(
                $siteToken,
                Craft::t('speculatr', 'Site tokens (`siteToken`)'),
                Exclusion::SOURCE_CRAFT,
            );
        }

        $csrfTokenName = trim((string)$general->csrfTokenName);

        if ($csrfTokenName !== '') {
            $out[] = Exclusion::param(
                $csrfTokenName,
                Craft::t('speculatr', 'CSRF tokens (`csrfTokenName`)'),
                Exclusion::SOURCE_CRAFT,
            );
        }

        // Published plugin and control panel resources. Not pages, and never worth a hidden tab.
        $resourcePath = $this->resourcePath();

        if ($resourcePath !== null) {
            $out[] = Exclusion::path(
                $resourcePath . '/*',
                Craft::t('speculatr', 'Published resources (`resourceBaseUrl`)'),
                Exclusion::SOURCE_CRAFT,
            );
        }

        return $out;
    }

    /**
     * The exclusions Speculatr ships whatever the site looks like.
     *
     * @return Exclusion[]
     */
    public function builtIn(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $out = [];

        if ($settings->optOutClass !== '') {
            $out[] = Exclusion::selector(
                '.' . $settings->optOutClass,
                Craft::t('speculatr', 'Links wearing the opt-out class'),
            );
        }

        if ($settings->excludeNofollow) {
            $out[] = Exclusion::selector(
                '[rel~="nofollow"]',
                Craft::t('speculatr', 'Links marked `rel="nofollow"`'),
            );
        }

        if ($settings->excludeDownloads) {
            $out[] = Exclusion::selector(
                '[download]',
                Craft::t('speculatr', 'Links that download rather than navigate'),
            );

            if ($settings->excludeExtensions !== []) {
                $out[] = Exclusion::extensions(
                    $settings->excludeExtensions,
                    Craft::t('speculatr', 'Files rather than pages'),
                );
            }
        }

        if ($settings->excludeNewWindow) {
            $out[] = Exclusion::selector(
                '[target="_blank"]',
                Craft::t('speculatr', 'Links that open in a new tab, which a prerender cannot be handed'),
            );
        }

        return $out;
    }

    /**
     * The exclusions somebody typed in.
     *
     * @return Exclusion[]
     */
    public function fromSettings(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $out = [];
        $reason = Craft::t('speculatr', 'Configured in Speculatr’s settings');

        if ($settings->excludeQueryStrings) {
            $out[] = Exclusion::query(Craft::t('speculatr', 'Any URL carrying a query string'));
        }

        foreach ($settings->excludePaths as $path) {
            $out[] = Exclusion::path((string)$path, $reason, Exclusion::SOURCE_SETTINGS);
        }

        foreach ($settings->excludeParams as $param) {
            $out[] = Exclusion::param((string)$param, $reason, Exclusion::SOURCE_SETTINGS);
        }

        foreach ($settings->excludeSelectors as $selector) {
            $out[] = Exclusion::selector((string)$selector, $reason, Exclusion::SOURCE_SETTINGS);
        }

        return $out;
    }

    /**
     * Whichever exclusion covers a URL first, or null if none does.
     *
     * @param array<string, mixed> $params
     */
    public function firstMatch(string $path, array $params): ?Exclusion
    {
        foreach ($this->urlExclusions() as $exclusion) {
            if ($exclusion->matchesUrl($path, $params)) {
                return $exclusion;
            }
        }

        return null;
    }

    /**
     * The path `resourceBaseUrl` resolves to, when it is on this site rather than a CDN.
     *
     * Absolute URLs on another host cannot be matched by a same-origin rule, so there is nothing
     * to exclude and saying so would only be noise in the control panel.
     */
    private function resourcePath(): ?string
    {
        try {
            $url = Craft::getAlias(Craft::$app->getConfig()->getGeneral()->resourceBaseUrl);
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($url) || $url === '') {
            return null;
        }

        if (UrlHelper::isAbsoluteUrl($url)) {
            $host = parse_url($url, PHP_URL_HOST);
            $siteHost = parse_url(UrlHelper::baseSiteUrl(), PHP_URL_HOST);

            if ($host !== null && $siteHost !== null && strcasecmp($host, $siteHost) !== 0) {
                return null;
            }
        }

        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' ? $path : null;
    }

    /** Forgets the memo. The checks change settings under it. */
    public function reset(): void
    {
        $this->memo = null;
        $this->runtime = [];
    }
}
