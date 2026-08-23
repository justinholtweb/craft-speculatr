<?php

namespace justinholtweb\speculatr;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\Request;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\services\Exclusions;
use justinholtweb\speculatr\services\Injector;
use justinholtweb\speculatr\services\Rules;
use justinholtweb\speculatr\twig\SpeculatrVariable;
use Throwable;
use yii\base\Event;

/**
 * Speculatr — speculative loading for Craft.
 *
 * Chromium browsers will happily prefetch or prerender the page a reader is about to open, if you
 * tell them which links are safe to open early. The telling is a small JSON document; the safety
 * is the entire problem.
 *
 * Prerendering does not fetch a page, it *runs* one — scripts, analytics, everything — in a hidden
 * tab, before anybody has clicked. So the interesting part of this plugin is not the rules it
 * writes but the exclusions it assembles first, and it assembles them out of Craft's own
 * configuration so that a site with a renamed control panel, a custom logout path or a preview
 * token in a URL is protected without anyone having to remember.
 *
 * @property-read Rules $rules
 * @property-read Exclusions $exclusions
 * @property-read Injector $injector
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'speculatr:viewRules';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'speculatr';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'rules' => Rules::class,
                'exclusions' => Exclusions::class,
                'injector' => Injector::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerResponseFilter();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('speculatr', 'Speculatr');

        $item['subnav'] = [
            'rules' => [
                'label' => Craft::t('speculatr', 'Rules'),
                'url' => 'speculatr/rules',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('speculatr', 'Settings'),
                'url' => 'settings/plugins/speculatr',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('speculatr/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    // ------------------------------------------------------------------ the response filter

    /**
     * `EVENT_AFTER_PREPARE`, so the rules land on every front-end page however it was rendered —
     * a template, a plugin, an element index page — without a site having to add a tag to a
     * layout it may not control.
     */
    private function registerResponseFilter(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            try {
                $this->serve($event->sender);
            } catch (Throwable $e) {
                // A page with no speculation rules is a page. A page that 500s because a
                // performance feature threw is not. Nothing in here may take a site down.
                Craft::error('Could not serve speculation rules: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    private function serve(Response $response): void
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof Request) {
            return;
        }

        if (!$this->injector->servesRules($response, $request)) {
            return;
        }

        // Headers first, and on every response of a speculating page. A prefetched response is the
        // one that gets cached, so it is the one that most needs `No-Vary-Search` — even though it
        // is also the one that deliberately carries no rules.
        $this->injector->addResponseHeaders($response);

        if (!$this->injector->shouldServe($response, $request)) {
            return;
        }

        if ($this->injector->serve($response, $request)) {
            $this->injector->restampLength($response);
        }
    }

    // ------------------------------------------------------------------ wiring

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'speculatr' => 'speculatr/rules/index',
                'speculatr/rules' => 'speculatr/rules/index',
            ];
        });

        // The rules document for header delivery. A real site URL rather than an action URL,
        // because the browser fetches it as an ordinary same-origin resource and the path is what
        // ends up in a `Speculation-Rules` header somebody may have to read in devtools.
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['speculatr/rules.json'] = 'speculatr/document/index';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('speculatr', 'Speculatr'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('speculatr', 'View the speculation rules'),
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('speculatr', SpeculatrVariable::class);
        });
    }
}
