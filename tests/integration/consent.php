<?php
/**
 * Speculatr consent checks: prerendering held for Toss.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-speculatr/tests/integration/consent.php
 *
 * Toss's consent kit is switched on and off on Toss's in-memory settings model only; nothing is
 * saved, and Speculatr's settings are put back in a `finally`. The browser half — the inline script
 * adding and removing the prerender rule set — is run against a fake DOM in Node by
 * `consent-upgrade.cjs`.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Speculatr is not installed in this site.\n";
    exit(1);
}

$tossInstalled = Craft::$app->getPlugins()->isPluginEnabled('toss');

if (!$tossInstalled) {
    echo "Toss is not installed and enabled in this site; the consent checks need it.\n";
    exit(1);
}

$toss = justinholtweb\toss\Plugin::getInstance();
$tossSettings = $toss->getSettings();
$tossOriginalKits = (array)$tossSettings->kits;
$kitHandle = justinholtweb\toss\kits\CookieConsentKit::handle();

$settings = $plugin->getSettings();
$original = $settings->toArray();

/** Switches Toss's cookie consent kit on or off, in memory. */
$kit = static function(bool $on) use ($tossSettings, $kitHandle): void {
    $kits = (array)$tossSettings->kits;
    $kits[$kitHandle] = array_merge((array)($kits[$kitHandle] ?? []), ['enabled' => $on]);
    $tossSettings->kits = $kits;
};

$configure = static function(array $values) use ($plugin, $settings): void {
    foreach ($values as $key => $value) {
        $settings->$key = $value;
    }

    $plugin->exclusions->reset();
    $plugin->rules->reset();
};

$restore = static function() use ($configure, $original, $kit, $tossSettings, $tossOriginalKits): void {
    $configure($original);
    $tossSettings->kits = $tossOriginalKits;
};

/** The defaults this file's checks start from: guests, prerender, moderate, Toss deferring. */
$baseline = [
    'enabled' => true, 'forGuests' => true, 'mode' => Settings::MODE_PRERENDER, 'eagerness' => 'moderate',
    'deferToToss' => true, 'prerenderConsent' => ['analytics', 'marketing'], 'prefetchUrls' => [],
    'delivery' => Settings::DELIVERY_INLINE, 'excludePages' => [],
];

try {

section('Settings');

check('defaults: defer to Toss, waiting for analytics + marketing', function() {
    $model = new Settings();

    return $model->deferToToss === true && $model->prerenderConsent === ['analytics', 'marketing'];
});

check('an empty checkbox group saves as no categories', function() {
    $model = new Settings(['prerenderConsent' => '']);
    $model->validate(['prerenderConsent']);

    return $model->prerenderConsent === [];
});

check('necessary, duplicates and non-names are dropped', function() {
    $model = new Settings(['prerenderConsent' => ['necessary', 'analytics', 'bad name', '"><x', 'analytics', 7]]);
    $model->validate(['prerenderConsent']);

    return $model->prerenderConsent === ['analytics'] ?: json_encode($model->prerenderConsent);
});

check('a config-file value that skipped validation is filtered before reaching the page', function() use ($plugin, $configure, $restore) {
    $configure(['prerenderConsent' => ['marketing', 'necessary', '</script>']]);
    $categories = $plugin->consent->categories();
    $restore();

    return $categories === ['marketing'] ?: json_encode($categories);
});

section('When prerendering is held');

check('Toss absent or kit off: nothing is held and the page prerenders as before', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(false);
    $gated = $plugin->consent->gatesPrerender();
    $document = $plugin->rules->document(null);
    $held = $plugin->rules->heldDocument(null);
    $tag = $plugin->rules->tag(null);
    $restore();

    return !$gated && isset($document['prerender']) && $held === [] && !str_contains($tag, 'data-speculatr')
        ?: json_encode(compact('gated', 'document', 'held'));
});

check('kit on: held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(true);
    $gated = $plugin->consent->gatesPrerender();
    $restore();

    return $gated === true;
});

check('deferToToss off: not held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure(['deferToToss' => false] + $baseline);
    $kit(true);
    $gated = $plugin->consent->gatesPrerender();
    $document = $plugin->rules->document(null);
    $restore();

    return $gated === false && isset($document['prerender']);
});

check('no categories (or only necessary): not held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $kit(true);
    $configure(['prerenderConsent' => []] + $baseline);
    $none = $plugin->consent->gatesPrerender();
    $configure(['prerenderConsent' => ['necessary']] + $baseline);
    $necessary = $plugin->consent->gatesPrerender();
    $restore();

    return $none === false && $necessary === false;
});

section('The page’s rules and the held rules');

check('prerender mode: the page prefetches at the same eagerness and where, prerender is held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(false);
    $full = $plugin->rules->document(null);
    $kit(true);
    $document = $plugin->rules->document(null);
    $held = $plugin->rules->heldDocument(null);
    $restore();

    return array_keys($document) === ['prefetch']
        && count($document['prefetch']) === 1
        && $document['prefetch'][0] === $full['prerender'][0]
        && $held === ['prerender' => $full['prerender']]
        ?: json_encode(compact('document', 'held'));
});

check('both mode: the existing prefetch rule is not duplicated; the stepped-down prerender is held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure(['mode' => Settings::MODE_BOTH, 'eagerness' => 'eager'] + $baseline);
    $kit(true);
    $document = $plugin->rules->document(null);
    $held = $plugin->rules->heldDocument(null);
    $restore();

    return array_keys($document) === ['prefetch']
        && count($document['prefetch']) === 1
        && $document['prefetch'][0]['eagerness'] === 'eager'
        && count($held['prerender'] ?? []) === 1
        && $held['prerender'][0]['eagerness'] === 'moderate'
        ?: json_encode(compact('document', 'held'));
});

check('prefetch mode: nothing to hold, no script', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure(['mode' => Settings::MODE_PREFETCH] + $baseline);
    $kit(true);
    $held = $plugin->rules->heldDocument(null);
    $tag = $plugin->rules->tag(null);
    $restore();

    return $held === [] && $tag !== '' && !str_contains($tag, 'data-speculatr');
});

check('a template’s prerender() is prefetched on the page and its prerender held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(true);
    $plugin->rules->addUrls('/contact', 'prerender', 'eager');
    $document = $plugin->rules->document(null);
    $held = $plugin->rules->heldDocument(null);
    $plugin->rules->reset();
    $restore();

    $list = static fn(array $rules) => array_values(array_filter($rules, static fn($r) => ($r['source'] ?? '') === 'list'));

    return ($list($document['prefetch'] ?? [])[0]['urls'] ?? null) === ['/contact']
        && ($list($document['prefetch'])[0]['eagerness'] ?? null) === 'eager'
        && ($list($held['prerender'] ?? [])[0]['urls'] ?? null) === ['/contact']
        ?: json_encode(compact('document', 'held'));
});

check('a URL already prefetched by name is not listed twice', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure(['prefetchUrls' => ['/contact']] + $baseline);
    $kit(true);
    $plugin->rules->addUrls('/contact', 'prerender');
    $document = $plugin->rules->document(null);
    $plugin->rules->reset();
    $restore();

    $urls = [];

    foreach ($document['prefetch'] ?? [] as $rule) {
        $urls = [...$urls, ...($rule['urls'] ?? [])];
    }

    return $urls === ['/contact'] ?: json_encode($document);
});

check('a signed-in visitor downgraded to prefetch has nothing held', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $user = craft\elements\User::find()->one();
    $configure(['forLoggedIn' => true, 'downgradeForLoggedIn' => true] + $baseline);
    $kit(true);
    $held = $plugin->rules->heldDocument($user);
    $tag = $plugin->rules->tag($user);
    $restore();

    return $user !== null && $held === [] && !str_contains($tag, 'data-speculatr');
});

section('Cache safety');

check('the page’s rules never read a visitor’s answer: no server-side consent read anywhere in src', function() {
    $hits = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $code = (string)file_get_contents($file->getPathname());

            if (preg_match('/consent->(has|snapshot|allows|state)\(|\$_COOKIE|getCookies\(\)/', $code)) {
                $hits[] = $file->getFilename();
            }
        }
    }

    return $hits === [] ?: 'reads consent in ' . implode(', ', $hits);
});

check('the tag is byte-identical whoever asks (two requests, different Toss cookies)', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(true);
    $tags = [];

    foreach (['{"analytics":true,"marketing":true}', '{"analytics":false,"marketing":false}'] as $value) {
        $_COOKIE = ['toss_consent' => $value, 'toss' => $value];
        $tags[] = $plugin->rules->tag(null);
    }

    $_COOKIE = [];
    $restore();

    return $tags[0] === $tags[1] && $tags[0] !== '';
});

check('the inline tag: prefetch rules, then the upgrade script with the held rules and categories', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(true);
    $tag = $plugin->rules->tag(null);
    $restore();

    preg_match('#^<script type="speculationrules">(.*?)</script>(<script data-speculatr="consent">.*</script>)$#s', $tag, $m);

    if (!$m) {
        return 'unexpected tag: ' . substr($tag, 0, 200);
    }

    $static = json_decode($m[1], true);

    return array_keys($static) === ['prefetch']
        && str_contains($m[2], '["analytics","marketing"]')
        && str_contains($m[2], '\"prerender\"')
        // Nothing in the script can close the element early.
        && substr_count($m[2], '</') === 1
        ?: $tag;
});

check('the browser half: added on grant only, removed on withdrawal, carries the nonce', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(true);
    $tag = $plugin->rules->tag(null);
    $held = $plugin->rules->heldDocument(null);
    $restore();

    preg_match('#(<script data-speculatr="consent">.*</script>)$#s', $tag, $m);
    $file = tempnam(sys_get_temp_dir(), 'speculatr-consent');
    file_put_contents($file, $m[1] ?? '');
    $output = shell_exec('node ' . escapeshellarg(__DIR__ . '/consent-upgrade.cjs') . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);

    $r = json_decode((string)$output, true);

    if (!is_array($r)) {
        return 'node said: ' . $output;
    }

    $one = static fn(array $set) => count($set) === 1 && $set[0]['type'] === 'speculationrules' && $set[0]['marker'] === 'consent';

    return $r['undecided'] === []
        && $one($r['granted']) && $r['granted'][0]['rules'] === $held && $r['granted'][0]['nonce'] === 'n0nce'
        && $r['withdrawn'] === []
        && $one($r['regranted'])
        && $one($r['eventGranted'])
        && $r['partial'] === []
        && $r['unsupported'] === []
        && $one($r['noNonce']) && $r['noNonce'][0]['nonce'] === ''
        ?: (string)$output;
});

section('Header delivery');

$page = static function(): craft\web\Response {
    $response = new craft\web\Response();
    $response->setStatusCode(200);
    $response->getHeaders()->set('content-type', 'text/html; charset=UTF-8');
    $response->content = '<html><body>hi</body></html>';

    return $response;
};

check('the rules file holds no prerender rules; the page carries the upgrade script inline', function() use ($plugin, $configure, $restore, $kit, $baseline, $page) {
    $configure(['delivery' => Settings::DELIVERY_HEADER] + $baseline);
    $kit(true);
    $file = json_decode($plugin->rules->json(null), true);
    $response = $page();
    $served = $plugin->injector->serve($response, new craft\web\Request());
    $restore();

    return array_keys($file) === ['prefetch']
        && $served === true
        && $response->getHeaders()->get('Speculation-Rules') !== null
        && str_contains((string)$response->content, '<script data-speculatr="consent">')
        && !str_contains((string)$response->content, '<script type="speculationrules">')
        ?: json_encode(['file' => $file, 'content' => $response->content]);
});

check('without Toss, header delivery adds nothing inline (as before)', function() use ($plugin, $configure, $restore, $kit, $baseline, $page) {
    $configure(['delivery' => Settings::DELIVERY_HEADER] + $baseline);
    $kit(false);
    $response = $page();
    $served = $plugin->injector->serve($response, new craft\web\Request());
    $restore();

    return $served === false && $response->content === '<html><body>hi</body></html>';
});

check('the rules-file URL changes when Toss starts holding prerenders', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(false);
    $off = $plugin->injector->version();
    $kit(true);
    $on = $plugin->injector->version();
    $restore();

    return $off !== $on;
});

section('Explaining a URL');

check('the checker says a held prerender is prefetched until consent', function() use ($plugin, $configure, $restore, $kit, $baseline) {
    $configure($baseline);
    $kit(true);
    $verdict = $plugin->rules->explain('/about', null);
    $restore();

    return $verdict->speculated && $verdict->prerender && $verdict->prefetch
        && str_contains(implode(' ', $verdict->caveats), 'analytics + marketing')
        ?: json_encode($verdict->toArray());
});

check('console: rules/show --held answers from the saved settings', function() use ($plugin) {
    // A fresh process reads the saved settings, so the answer follows whatever this site has saved.
    $gated = $plugin->consent->gatesPrerender() && $plugin->rules->fullDocument(null) !== [];
    $out = (string)shell_exec('php craft speculatr/rules/show --held 2>&1');

    return ($gated ? str_contains($out, '"prerender"') : str_contains($out, 'No prerender rules are held for consent')) ?: $out;
});

} finally {
    $restore();
}

echo "\n" . str_repeat('─', 60) . "\n";
echo sprintf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
