<?php

namespace justinholtweb\speculatr\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\Plugin;
use yii\web\Response;

/**
 * The rules document, for sites delivering by header rather than inline.
 *
 * Served from a real site URL so it is an ordinary same-origin resource the browser can cache, and
 * with the media type the specification requires — a rules file served as `application/json` is
 * declined, silently, and the site simply never speculates.
 */
class DocumentController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $response = Craft::$app->getResponse();

        if (!$settings->enabled || $settings->delivery !== Settings::DELIVERY_HEADER) {
            throw new \yii\web\NotFoundHttpException();
        }

        $user = Craft::$app->getUser()->getIdentity();
        $json = $plugin->rules->json($user);

        $response->format = Response::FORMAT_RAW;
        $response->content = $json !== '' ? $json : '{}';

        $headers = $response->getHeaders();
        $headers->set('content-type', 'application/speculationrules+json');

        // The URL carries a fingerprint of the settings, so a long cache is safe and a change to
        // the exclusions still reaches everybody on their next page view.
        $headers->set('cache-control', 'public, max-age=3600');

        // The document depends on who is asking, so a shared cache must not reuse one visitor's
        // copy for another's. Without this a signed-in user's ruleset can be handed to a guest.
        $headers->set('vary', 'Cookie');

        return $response;
    }
}
