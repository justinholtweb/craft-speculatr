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
        // the exclusions still reaches everybody on their next page view. Only the guest copy may
        // sit in a shared cache; a signed-in visitor's is private.
        // `?a[]=` arrives as an array; casting one would warn, and in dev mode a warning is a 500.
        $request = Craft::$app->getRequest();
        $audience = $request->getQueryParam('a');
        $version = $request->getQueryParam('v');
        $headers->set('cache-control', $plugin->injector->documentCacheControl(
            $user,
            is_string($audience) ? $audience : '',
            is_string($version) ? $version : '',
        ));

        // The document depends on who is asking. The audience is in the URL for caches that key on
        // nothing else; this is for the browser, whose own cache does honour it.
        $headers->set('vary', 'Cookie');

        return $response;
    }
}
