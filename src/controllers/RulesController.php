<?php

namespace justinholtweb\speculatr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\Plugin;
use yii\web\Response;

/**
 * The control panel's one screen: what is being sent, what is excluded and why, and what would
 * happen to a URL you are worried about.
 */
class RulesController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        // The rules differ by audience, so the screen has to say which audience it is showing —
        // otherwise an admin looking at their own view concludes guests get something they do not.
        $audience = $request->getParam('audience', 'guest');
        $user = $audience === 'guest' ? null : Craft::$app->getUser()->getIdentity();

        $url = trim((string)$request->getParam('url', ''));
        $verdict = $url !== '' ? $plugin->rules->explain($url, $user) : null;

        return $this->renderTemplate('speculatr/rules/index', [
            'settings' => $settings,
            'audience' => $audience,
            'json' => $plugin->rules->json($user, true),
            'appliesToGuests' => $settings->forGuests,
            'appliesToUser' => $plugin->rules->appliesTo(Craft::$app->getUser()->getIdentity()),
            'effectiveMode' => $plugin->rules->effectiveMode($user),
            'exclusions' => $plugin->exclusions->all(),
            'url' => $url,
            'verdict' => $verdict,
            'deliveryUrl' => $settings->delivery === Settings::DELIVERY_HEADER
                ? \craft\helpers\UrlHelper::siteUrl('speculatr/rules.json', ['v' => $plugin->injector->version()])
                : null,
            'commerceInstalled' => Craft::$app->getPlugins()->isPluginInstalled('commerce'),
        ]);
    }
}
