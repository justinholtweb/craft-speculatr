<?php

namespace justinholtweb\speculatr\services;

use Craft;
use craft\base\Component;
use justinholtweb\speculatr\Plugin;

/**
 * Holding prerendering back until the visitor has consented to what a prerender runs.
 *
 * A prefetch downloads HTML and stops. A prerender *runs* the page in a hidden tab — its analytics
 * and advertising tags included — before anybody has clicked, and before a visitor who has not yet
 * answered the cookie banner has been asked anything. So when Toss is the site's consent manager,
 * the page carries prefetch rules only, and the prerender rules are added in the browser once
 * Toss says the visitor has granted every category in `prerenderConsent`, and taken away again if
 * they withdraw.
 *
 * The answer itself is never read here. A page may be served from a full-page cache to anybody, so
 * the rules in it are identical for every visitor and the upgrade happens in the browser from
 * `window.Toss` / `toss:consent` (Toss's `docs/consent-api.md`). Everything this service decides is
 * a fact about the *site*: whether Toss is the consent manager, and which categories prerendering
 * waits for. Both are the same for every visitor, so both are safe to bake into a cached page.
 *
 * The Speculation Rules specification lets a page add and remove rule sets at any time: inserting
 * a `<script type="speculationrules">` starts its speculations, and removing it cancels the ones it
 * started. That is what makes "prefetch statically, prerender on consent" the right way round —
 * the safe half is in the markup and the half that needs permission is added by permission.
 */
class Consent extends Component
{
    /**
     * Whether Toss is installed, enabled, and its cookie consent kit is on.
     *
     * The plugin check comes first and is not an optimisation: on a site without Toss, merely
     * naming a `justinholtweb\toss` class fatals. Toss's `isActive()` reads the kit's own switch,
     * not its licence, so a lapsed Toss Pro never makes Speculatr stop deferring.
     */
    public function tossIsActive(): bool
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('toss')) {
            return false;
        }

        $toss = \justinholtweb\toss\Plugin::getInstance();

        return $toss !== null && $toss->consent->isActive();
    }

    /**
     * The categories prerendering waits for. `necessary` is always granted, so it never holds
     * anything back and is left out.
     *
     * @return string[]
     */
    public function categories(): array
    {
        // Filtered again here as well as on save: `config/speculatr.php` never passes validation.
        /** @var array<mixed> $configured */
        $configured = Plugin::getInstance()->getSettings()->prerenderConsent;
        $out = [];

        foreach ($configured as $name) {
            if (is_string($name) && $name !== 'necessary' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether prerender rules wait for consent on this site. The same answer for every visitor. */
    public function gatesPrerender(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->deferToToss && $this->categories() !== [] && $this->tossIsActive();
    }

    /**
     * Splits a rules document into what goes in the page and what waits for consent.
     *
     * Every prerender rule moves to the held half, and the page gets a prefetch in its place so the
     * visitor who has not answered yet still gets most of the speed. A prefetch that is already
     * there — the prefetch half of `both`, or a named URL somebody prefetches as well — is not
     * duplicated. Once the prerender rules are added in the browser, Chrome upgrades the matching
     * prefetches rather than fetching twice.
     *
     * @param array<string, array<int, array<string, mixed>>> $document
     * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array<string, array<int, array<string, mixed>>>}
     */
    public function split(array $document): array
    {
        $held = $document['prerender'] ?? [];
        unset($document['prerender']);

        if ($held === []) {
            return [$document, []];
        }

        $prefetch = $document['prefetch'] ?? [];
        $hasDocumentRule = false;
        $listed = [];

        foreach ($prefetch as $rule) {
            if (($rule['source'] ?? '') === 'document') {
                $hasDocumentRule = true;
            } else {
                $listed = [...$listed, ...($rule['urls'] ?? [])];
            }
        }

        foreach ($held as $rule) {
            if (($rule['source'] ?? '') === 'document') {
                // A document rule prefetching every eligible link already covers this one, at an
                // eagerness at least as keen (the prerender half of `both` is a step behind).
                if (!$hasDocumentRule) {
                    $prefetch[] = $rule;
                    $hasDocumentRule = true;
                }

                continue;
            }

            $urls = array_values(array_diff($rule['urls'] ?? [], $listed));

            if ($urls === []) {
                continue;
            }

            $listed = [...$listed, ...$urls];
            $prefetch[] = ['urls' => $urls] + $rule;
        }

        if ($prefetch !== []) {
            $document['prefetch'] = $prefetch;
        }

        return [$document, ['prerender' => $held]];
    }

    /**
     * The inline script that adds the held prerender rules once consent is granted.
     *
     * Classic JavaScript with the rules and the categories baked in — nothing in it is about a
     * visitor. It follows Toss with `Toss.onConsent()` if Toss's runtime has already run, or the
     * `toss:consent` event if it has not (Toss inlines its runtime near `</body>`, and so does
     * Speculatr). Undecided (`null`) and refused both mean no: only an explicit grant of every
     * category adds the rules, and a withdrawal removes them, which cancels any prerender they
     * started.
     *
     * The rule set it inserts carries this script's nonce, so a policy that allows the script by
     * nonce allows the rules too. A policy without a nonce needs `'inline-speculation-rules'`.
     *
     * @param array<string, array<int, array<string, mixed>>> $held
     */
    public function upgradeScript(array $held): string
    {
        if ($held === []) {
            return '';
        }

        $rules = (string)json_encode($held, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $args = (string)json_encode([$rules, $this->categories()], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        $js = '(function(rules,cats){'
            . 'var d=document,me=d.currentScript,nonce=me&&me.nonce,el=null;'
            . 'if(!(window.HTMLScriptElement&&HTMLScriptElement.supports&&HTMLScriptElement.supports("speculationrules")))return;'
            . 'function apply(c){'
            . 'var ok=!!(c&&c.categories)&&cats.every(function(k){return c.categories[k]===true;});'
            . 'if(ok&&!el){el=d.createElement("script");el.type="speculationrules";if(nonce)el.nonce=nonce;'
            . 'el.setAttribute("data-speculatr","consent");el.textContent=rules;d.body.appendChild(el);}'
            . 'else if(!ok&&el){el.remove();el=null;}}'
            . 'if(window.Toss&&typeof window.Toss.onConsent==="function"){window.Toss.onConsent(apply);}'
            . 'else{d.addEventListener("toss:consent",function(e){apply(e.detail);});}'
            . '}).apply(null,' . $args . ');';

        $view = Craft::$app->getView();
        // `getCspNonce()` arrived partway through the Craft 5 line.
        $nonce = method_exists($view, 'getCspNonce') ? $view->getCspNonce() : null;
        $nonceAttr = is_string($nonce) && $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';

        return '<script data-speculatr="consent"' . $nonceAttr . '>' . $js . '</script>';
    }
}
