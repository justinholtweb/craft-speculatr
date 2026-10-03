<?php
/**
 * Speculatr integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-speculatr/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: the exclusions actually read out of this Craft install's
 * configuration, the rules document as it is really assembled, and the agreement between every
 * emitted URL pattern and the PHP that claims to answer the same question.
 *
 * That last one is the point of the file. A wrong URL pattern fails silently — the browser accepts
 * the rules, matches nothing, and the exclusion simply never happens — so the checks below assert
 * the exact pattern strings, and every one of them was verified against a real `URLPattern`
 * implementation before being written down here.
 *
 * Idempotent, and touches no database and no project config: settings are changed on the in-memory
 * model and put back at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\speculatr\models\Exclusion;
use justinholtweb\speculatr\models\Settings;
use justinholtweb\speculatr\Plugin;
use justinholtweb\speculatr\services\Injector;

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

$settings = $plugin->getSettings();
$original = $settings->toArray();

/** Applies settings for one check and clears the memoised exclusions. */
$configure = static function(array $values) use ($plugin, $settings): void {
    foreach ($values as $key => $value) {
        $settings->$key = $value;
    }

    $plugin->exclusions->reset();
    $plugin->rules->reset();
};

$restore = static function() use ($configure, $original): void {
    $configure($original);
};

try {

// ------------------------------------------------------------------ settings

section('Settings');

check('stepDown walks one notch towards conservative', function() {
    return Settings::stepDown('immediate') === 'eager'
        && Settings::stepDown('eager') === 'moderate'
        && Settings::stepDown('moderate') === 'conservative'
        && Settings::stepDown('conservative') === 'conservative';
});

check('stepDown survives an unknown value', function() {
    return Settings::stepDown('enthusiastic') === 'conservative';
});

check('editable-table rows normalise to strings', function() {
    $model = new Settings(['excludePaths' => [['value' => 'checkout/*'], ['value' => ''], 'cart']]);
    $model->validate();

    return $model->excludePaths === ['checkout/*', 'cart']
        ?: 'got ' . json_encode($model->excludePaths);
});

check('clearing a table clears the setting', function() {
    // `skipOnEmpty => false` is what makes this work; without it the one case that never
    // normalises is the empty one, and the old value survives a deliberate clear.
    $model = new Settings(['excludePaths' => []]);
    $model->validate();

    return $model->excludePaths === [];
});

check('a parameter name that is not one is dropped', function() {
    $model = new Settings(['excludeParams' => ['utm_source', 'no spaces', 'a=b', 'ok-1']]);
    $model->validate();

    return $model->excludeParams === ['utm_source', 'ok-1']
        ?: 'got ' . json_encode($model->excludeParams);
});

check('extensions are normalised and de-duplicated', function() {
    $model = new Settings(['excludeExtensions' => ['.PDF', 'pdf', 'zip ', 'not an extension', '']]);
    $model->validate();

    return $model->excludeExtensions === ['pdf', 'zip']
        ?: 'got ' . json_encode($model->excludeExtensions);
});

check('an invalid mode is rejected', function() {
    $model = new Settings(['mode' => 'teleport']);

    return $model->validate() === false && $model->hasErrors('mode');
});

check('no setting is required, so a bare model validates', function() {
    // A required plugin setting blocks a fresh install outright: Craft validates the settings
    // model before the plugin has ever been configured.
    return (new Settings())->validate() === true;
});

check('noVarySearch is a structured-field list', function() {
    $model = new Settings(['ignoreTrackingParams' => true, 'trackingParams' => ['utm_source', 'gclid']]);

    return $model->noVarySearch() === 'params=("utm_source" "gclid")'
        ?: 'got ' . var_export($model->noVarySearch(), true);
});

check('noVarySearch is null when there is nothing to say', function() {
    return (new Settings(['ignoreTrackingParams' => false]))->noVarySearch() === null
        && (new Settings(['ignoreTrackingParams' => true, 'trackingParams' => []]))->noVarySearch() === null;
});

check('excludesPage matches with and without a leading slash', function() {
    $model = new Settings(['excludePages' => ['account/*', '/one-off']]);

    return $model->excludesPage('account/orders')
        && $model->excludesPage('/account/orders')
        && $model->excludesPage('one-off')
        && !$model->excludesPage('accounts-payable');
});

check('an empty exclude-page pattern does not exclude everything', function() {
    $model = new Settings(['excludePages' => ['', '/']]);

    return !$model->excludesPage('anything');
});

// ------------------------------------------------------------------ patterns

section('URL patterns');

check('a path exclusion emits both the bare and the wildcard form', function() {
    // `/admin/*` does not match `/admin` — verified against a real URLPattern. Emitting only the
    // wildcard leaves the single most important URL uncovered.
    return Exclusion::path('admin/*', 'x')->patterns() === ['/admin', '/admin/*']
        ?: 'got ' . json_encode(Exclusion::path('admin/*', 'x')->patterns());
});

check('an exact path emits one pattern that also takes a trailing slash', function() {
    // `/logout` does not match `/logout/`, which Craft routes to the same action — verified
    // against a real URLPattern. `/logout{/}?` matches both, and not `/logoutx` or `/logout/x`.
    return Exclusion::path('logout', 'x')->patterns() === ['/logout{/}?']
        && Exclusion::path('/cart/', 'x')->patterns() === ['/cart{/}?']
        ?: 'got ' . json_encode(Exclusion::path('logout', 'x')->patterns());
});

check('pattern syntax in a typed path is escaped, the wildcard is not', function() {
    // `/cart(` throws in URLPattern, and a ruleset that throws is rejected whole. `\:` throws too
    // in the string form; `{\:}` is the literal colon that does not.
    $got = [
        Exclusion::path('cart(old)', 'x')->patterns(),
        Exclusion::path('a:b/*', 'x')->patterns(),
        Exclusion::path('c++*', 'x')->patterns(),
    ];

    return $got === [['/cart\\(old\\){/}?'], ['/a{\\:}b', '/a{\\:}b/*'], ['/c\\+\\+*']]
        ?: 'got ' . json_encode($got);
});

check('a parameter-value exclusion pins the start of the value', function() {
    return Exclusion::paramValue('p', 'actions', 'x')->patterns() === ['/*\?*(^|&)p=actions*']
        ?: 'got ' . json_encode(Exclusion::paramValue('p', 'actions', 'x')->patterns());
});

check('a parameter exclusion anchors on the start or an ampersand', function() {
    return Exclusion::param('action', 'x')->patterns() === ['/*\?*(^|&)action=*']
        ?: 'got ' . json_encode(Exclusion::param('action', 'x')->patterns());
});

check('extensions collapse into one alternation', function() {
    $patterns = Exclusion::extensions(['pdf', 'zip'], 'x')->patterns();

    return $patterns === ['/*.([pP][dD][fF]|[zZ][iI][pP])']
        ?: 'got ' . json_encode($patterns);
});

check('a digit in an extension is left alone', function() {
    return Exclusion::extensions(['mp4'], 'x')->patterns() === ['/*.([mM][pP]4)'];
});

check('the any-query exclusion requires a non-empty search', function() {
    // `*` would also match the empty search of a URL with no query string, i.e. everything.
    return Exclusion::query('x')->patterns() === ['/*\?(.+)'];
});

check('a selector exclusion emits no URL pattern', function() {
    $exclusion = Exclusion::selector('[download]', 'x');

    return $exclusion->patterns() === [] && $exclusion->selectorPattern() === '[download]';
});

// ------------------------------------------------------------------ matching

section('Matching agrees with the emitted patterns');

$cases = [
    // [exclusion, path, params, expected]
    [Exclusion::path('admin/*', 'x'), '/admin', [], true],
    [Exclusion::path('admin/*', 'x'), '/admin/entries', [], true],
    [Exclusion::path('admin/*', 'x'), '/administrator', [], false],
    [Exclusion::path('logout', 'x'), '/logout', [], true],
    [Exclusion::path('logout', 'x'), '/logout-page', [], false],
    [Exclusion::path('logout', 'x'), '/logout/', [], true],
    [Exclusion::path('logout', 'x'), '/logout/x', [], false],
    [Exclusion::path('cart/', 'x'), '/cart', [], true],
    [Exclusion::paramValue('p', 'actions', 'x'), '/index.php', ['p' => 'actions/users/logout'], true],
    [Exclusion::paramValue('p', 'logout', 'x'), '/index.php', ['p' => 'logout'], true],
    [Exclusion::paramValue('p', 'actions', 'x'), '/index.php', ['p' => 'blog'], false],
    [Exclusion::paramValue('p', 'actions', 'x'), '/index.php', ['xp' => 'actions'], false],
    [Exclusion::param('action', 'x'), '/page', ['action' => 'a'], true],
    [Exclusion::param('action', 'x'), '/page', ['transaction' => 'a'], false],
    [Exclusion::param('action', 'x'), '/page', [], false],
    [Exclusion::extensions(['pdf', 'zip'], 'x'), '/files/a.pdf', [], true],
    [Exclusion::extensions(['pdf', 'zip'], 'x'), '/files/a.PDF', [], true],
    [Exclusion::extensions(['pdf', 'zip'], 'x'), '/files/a.pdfx', [], false],
    [Exclusion::extensions(['pdf'], 'x'), '/page', [], false],
    [Exclusion::query('x'), '/page', ['a' => '1'], true],
    [Exclusion::query('x'), '/page', [], false],
    [Exclusion::selector('.x', 'x'), '/page', [], false],
];

foreach ($cases as $i => [$exclusion, $path, $params, $expected]) {
    check(
        sprintf('%s vs %s%s → %s', $exclusion->kind . ':' . $exclusion->value, $path, $params ? '?' . http_build_query($params) : '', $expected ? 'excluded' : 'allowed'),
        static fn() => $exclusion->matchesUrl($path, $params) === $expected,
    );
}

// ------------------------------------------------------------------ Craft's configuration

section('Exclusions read out of Craft');

$restore();

check('the control panel is excluded under whatever it is called', function() use ($plugin) {
    $trigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/');
    $patterns = [];

    foreach ($plugin->exclusions->fromCraft() as $exclusion) {
        $patterns = [...$patterns, ...$exclusion->patterns()];
    }

    return in_array('/' . $trigger, $patterns, true) && in_array('/' . $trigger . '/*', $patterns, true)
        ?: 'cpTrigger ' . $trigger . ' not covered by ' . json_encode($patterns);
});

check('action requests are excluded by path and by query parameter', function() use ($plugin) {
    $trigger = trim((string)Craft::$app->getConfig()->getGeneral()->actionTrigger, '/');
    $patterns = [];

    foreach ($plugin->exclusions->fromCraft() as $exclusion) {
        $patterns = [...$patterns, ...$exclusion->patterns()];
    }

    return in_array('/' . $trigger . '/*', $patterns, true)
        && in_array('/*\?*(^|&)action=*', $patterns, true);
});

check('the logout path is excluded', function() use ($plugin) {
    $logout = Craft::$app->getConfig()->getGeneral()->logoutPath;

    if (!is_string($logout) || trim($logout, '/') === '') {
        return true; // Nothing configured, nothing to cover.
    }

    foreach ($plugin->exclusions->fromCraft() as $exclusion) {
        if (in_array('/' . trim($logout, '/') . '{/}?', $exclusion->patterns(), true)) {
            return true;
        }
    }

    return 'logoutPath ' . $logout . ' is not excluded';
});

check('preview, site and CSRF tokens are excluded', function() use ($plugin) {
    $general = Craft::$app->getConfig()->getGeneral();
    $wanted = array_filter([$general->tokenParam, $general->siteToken, $general->csrfTokenName]);
    $found = [];

    foreach ($plugin->exclusions->fromCraft() as $exclusion) {
        if ($exclusion->kind === Exclusion::KIND_PARAM) {
            $found[] = $exclusion->value;
        }
    }

    foreach ($wanted as $param) {
        if (!in_array($param, $found, true)) {
            return $param . ' is not excluded; found ' . json_encode($found);
        }
    }

    return true;
});

check('an auth path configured as false is skipped rather than excluding the site root', function() use ($plugin) {
    // These are `string|false`, and turning a false into `/` would exclude every link there is.
    foreach ($plugin->exclusions->fromCraft() as $exclusion) {
        if ($exclusion->patterns() === ['/'] || $exclusion->patterns() === ['/', '/*']) {
            return 'an exclusion covers the whole site: ' . $exclusion->reason;
        }
    }

    return true;
});

check('the control panel exclusion is only in signed-in visitors’ rules', function() use ($plugin, $configure, $restore) {
    // The rules are public. A guest's copy naming a renamed cpTrigger publishes it.
    $trigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/');
    $configure(['enabled' => true, 'forGuests' => true, 'forLoggedIn' => true]);

    $guest = $plugin->rules->json(null);
    $member = $plugin->rules->json(new craft\elements\User());
    $guestVerdict = $plugin->rules->explain('/' . $trigger . '/entries', null);
    $memberVerdict = $plugin->rules->explain('/' . $trigger . '/entries', new craft\elements\User());
    $restore();

    $needle = '"/' . $trigger . '/*"';

    return !str_contains($guest, $needle)
        && str_contains($member, $needle)
        && $guestVerdict->blockedBy === null
        && $memberVerdict->blockedBy !== null
        ?: json_encode(['guest' => str_contains($guest, $needle), 'member' => str_contains($member, $needle)]);
});

check('everything else is in a guest’s rules too', function() use ($plugin, $configure, $restore) {
    $trigger = trim((string)Craft::$app->getConfig()->getGeneral()->actionTrigger, '/');
    $configure(['enabled' => true, 'forGuests' => true]);
    $guest = $plugin->rules->json(null);
    $restore();

    return str_contains($guest, '"/' . $trigger . '/*"') && str_contains($guest, 'action=*');
});

check('a site served from a subfolder has its own Craft routes excluded', function() use ($plugin) {
    // On `https://example.com/fr/`, logout is `/fr/logout` and actions are `/fr/actions/…`. A
    // pattern written from the origin root sees neither.
    $site = Craft::$app->getSites()->getPrimarySite();
    $original = $site->getBaseUrl(false);
    $general = Craft::$app->getConfig()->getGeneral();
    $trigger = trim((string)$general->actionTrigger, '/');
    $logout = is_string($general->logoutPath) ? trim($general->logoutPath, '/') : '';

    try {
        $site->setBaseUrl('https://example.test/fr/');
        $plugin->exclusions->reset();

        $paths = [];
        $patterns = [];

        foreach ($plugin->exclusions->fromCraft() as $exclusion) {
            $patterns = [...$patterns, ...$exclusion->patterns()];
        }

        $hits = $plugin->exclusions->firstMatch('/fr/' . $trigger . '/users/logout', []) !== null
            && in_array('/fr/' . $trigger . '/*', $patterns, true)
            && in_array('/' . $trigger . '/*', $patterns, true)
            && ($logout === '' || in_array('/fr/' . $logout . '{/}?', $patterns, true));
    } finally {
        $site->setBaseUrl($original);
        $plugin->exclusions->reset();
    }

    return $hits ?: 'subfolder routes not covered: ' . json_encode($patterns);
});

check('an install that keeps index.php in its URLs is covered both ways', function() use ($plugin) {
    $general = Craft::$app->getConfig()->getGeneral();
    $original = $general->omitScriptNameInUrls;
    $trigger = trim((string)$general->actionTrigger, '/');

    try {
        $general->omitScriptNameInUrls = false;
        $plugin->exclusions->reset();

        $byPath = $plugin->exclusions->firstMatch('/index.php/' . $trigger . '/users/logout', []) !== null;
        $byParam = $plugin->exclusions->firstMatch('/index.php', [$general->pathParam => $trigger . '/users/logout']) !== null;
        $page = $plugin->exclusions->firstMatch('/index.php', [$general->pathParam => 'blog']) === null;
    } finally {
        $general->omitScriptNameInUrls = $original;
        $plugin->exclusions->reset();
    }

    return ($byPath && $byParam && $page) ?: json_encode(compact('byPath', 'byParam', 'page'));
});

check('a named URL that an exclusion covers is never emitted', function() use ($plugin, $configure, $restore) {
    $trigger = trim((string)Craft::$app->getConfig()->getGeneral()->actionTrigger, '/');
    $configure(['prefetchUrls' => ['/about', '/' . $trigger . '/users/logout']]);
    $plugin->rules->addUrls(['/contact', '/?action=users/logout'], 'prerender');
    $document = $plugin->rules->document();
    $plugin->rules->reset();
    $restore();

    $listed = [];

    foreach ([...($document['prefetch'] ?? []), ...($document['prerender'] ?? [])] as $rule) {
        if (($rule['source'] ?? null) === 'list') {
            $listed = [...$listed, ...$rule['urls']];
        }
    }

    sort($listed);

    return $listed === ['/about', '/contact'] ?: 'listed ' . json_encode($listed);
});

check('the settings’ own exclusions arrive', function() use ($plugin, $configure, $restore) {
    $configure(['excludePaths' => ['checkout/*'], 'excludeParams' => ['add-to-cart'], 'excludeSelectors' => ['[data-modal]']]);

    $patterns = [];
    $selectors = [];

    foreach ($plugin->exclusions->all() as $exclusion) {
        $patterns = [...$patterns, ...$exclusion->patterns()];

        if ($exclusion->isSelector()) {
            $selectors[] = $exclusion->selectorPattern();
        }
    }

    $restore();

    return in_array('/checkout/*', $patterns, true)
        && in_array('/*\?*(^|&)add-to-cart=*', $patterns, true)
        && in_array('[data-modal]', $selectors, true);
});

check('a template’s exclusion is added, and reset forgets it', function() use ($plugin) {
    $before = count($plugin->exclusions->all());
    $plugin->exclusions->addPath('demo-only/*');
    $during = count($plugin->exclusions->all());
    $plugin->exclusions->reset();
    $after = count($plugin->exclusions->all());

    return $during === $before + 1 && $after === $before;
});

// ------------------------------------------------------------------ the document

section('The rules document');

check('prerender mode emits one prerender document rule', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_PRERENDER, 'eagerness' => 'moderate', 'forGuests' => true, 'prefetchUrls' => []]);
    $document = $plugin->rules->document(null);
    $restore();

    return array_keys($document) === ['prerender']
        && count($document['prerender']) === 1
        && $document['prerender'][0]['source'] === 'document'
        && $document['prerender'][0]['eagerness'] === 'moderate';
});

check('both mode holds the prerender a notch behind the prefetch', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_BOTH, 'eagerness' => 'eager', 'forGuests' => true, 'prefetchUrls' => []]);
    $document = $plugin->rules->document(null);
    $restore();

    return $document['prefetch'][0]['eagerness'] === 'eager'
        && $document['prerender'][0]['eagerness'] === 'moderate'
        ?: 'got prefetch=' . $document['prefetch'][0]['eagerness'] . ' prerender=' . $document['prerender'][0]['eagerness'];
});

check('the where clause starts by matching the whole origin', function() use ($plugin) {
    $where = $plugin->rules->where();
    $first = $where['and'][0];

    return $first === ['href_matches' => '/*', 'relative_to' => 'document'];
});

check('every exclusion reaches the where clause', function() use ($plugin, $configure, $restore) {
    $configure(['excludePaths' => ['checkout/*']]);
    $where = $plugin->rules->where();
    $restore();

    $patterns = [];

    foreach ($where['and'] as $predicate) {
        $inner = $predicate['not'] ?? $predicate;

        if (isset($inner['href_matches'])) {
            $patterns[] = $inner['href_matches'];
        }
    }

    return in_array('/checkout', $patterns, true) && in_array('/checkout/*', $patterns, true)
        ?: 'got ' . json_encode($patterns, JSON_UNESCAPED_SLASHES);
});

check('relative_to document is on every href predicate', function() use ($plugin) {
    foreach ($plugin->rules->where()['and'] as $predicate) {
        $inner = $predicate['not'] ?? $predicate;

        if (isset($inner['href_matches']) && ($inner['relative_to'] ?? null) !== 'document') {
            return 'missing relative_to on ' . json_encode($inner);
        }
    }

    return true;
});

check('expects_no_vary_search mirrors the header value', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_PREFETCH, 'ignoreTrackingParams' => true, 'trackingParams' => ['utm_source']]);
    $document = $plugin->rules->document(null);
    $restore();

    return ($document['prefetch'][0]['expects_no_vary_search'] ?? null) === 'params=("utm_source")';
});

check('expects_no_vary_search is absent when tracking parameters are off', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_PREFETCH, 'ignoreTrackingParams' => false]);
    $document = $plugin->rules->document(null);
    $restore();

    return !array_key_exists('expects_no_vary_search', $document['prefetch'][0]);
});

check('named URLs become an immediate prefetch list rule', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_PRERENDER, 'prefetchUrls' => ['contact', '/pricing']]);
    $document = $plugin->rules->document(null);
    $restore();

    $list = array_values(array_filter($document['prefetch'] ?? [], static fn($r) => ($r['source'] ?? '') === 'list'));

    return count($list) === 1
        && $list[0]['urls'] === ['/contact', '/pricing']
        && $list[0]['eagerness'] === 'immediate'
        ?: 'got ' . json_encode($document['prefetch'] ?? null);
});

check('named URLs are never prerendered', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_PRERENDER, 'prefetchUrls' => ['/contact']]);
    $document = $plugin->rules->document(null);
    $restore();

    foreach ($document['prerender'] ?? [] as $rule) {
        if (($rule['source'] ?? '') === 'list') {
            return 'a named URL was prerendered';
        }
    }

    return true;
});

check('an absolute same-origin named URL is trimmed to a path', function() use ($plugin, $configure, $restore) {
    $origin = $plugin->rules->origins()[0];
    $configure(['prefetchUrls' => [$origin . '/contact']]);
    $document = $plugin->rules->document(null);
    $restore();

    $list = array_values(array_filter($document['prefetch'] ?? [], static fn($r) => ($r['source'] ?? '') === 'list'));

    return ($list[0]['urls'] ?? null) === ['/contact'] ?: 'got ' . json_encode($list);
});

check('a cross-origin named URL is dropped', function() use ($plugin, $configure, $restore) {
    $configure(['prefetchUrls' => ['https://example.com/elsewhere']]);
    $document = $plugin->rules->document(null);
    $restore();

    $list = array_values(array_filter($document['prefetch'] ?? [], static fn($r) => ($r['source'] ?? '') === 'list'));

    return $list === [] ?: 'got ' . json_encode($list);
});

// ------------------------------------------------------------------ audience

section('Audience');

check('guests are served when the guest switch is on', function() use ($plugin, $configure, $restore) {
    $configure(['forGuests' => true]);
    $yes = $plugin->rules->appliesTo(null);
    $configure(['forGuests' => false]);
    $no = $plugin->rules->appliesTo(null);
    $restore();

    return $yes === true && $no === false;
});

check('a guest gets nothing at all when the switch is off', function() use ($plugin, $configure, $restore) {
    $configure(['forGuests' => false]);
    $document = $plugin->rules->document(null);
    $json = $plugin->rules->json(null);
    $tag = $plugin->rules->tag(null);
    $restore();

    return $document === [] && $json === '' && $tag === '';
});

check('an admin is served by either the admin or the signed-in switch', function() use ($plugin, $configure, $restore) {
    $admin = new craft\elements\User(['admin' => true]);
    $user = new craft\elements\User(['admin' => false]);

    $configure(['forAdmins' => true, 'forLoggedIn' => false]);
    $adminOnly = $plugin->rules->appliesTo($admin) === true && $plugin->rules->appliesTo($user) === false;

    $configure(['forAdmins' => false, 'forLoggedIn' => true]);
    $everyone = $plugin->rules->appliesTo($admin) === true && $plugin->rules->appliesTo($user) === true;

    $configure(['forAdmins' => false, 'forLoggedIn' => false]);
    $nobody = $plugin->rules->appliesTo($admin) === false && $plugin->rules->appliesTo($user) === false;

    $restore();

    return $adminOnly && $everyone && $nobody;
});

check('prerender degrades to prefetch for a signed-in user', function() use ($plugin, $configure, $restore) {
    $user = new craft\elements\User(['admin' => false]);
    $configure(['mode' => Settings::MODE_PRERENDER, 'downgradeForLoggedIn' => true]);

    $forUser = $plugin->rules->effectiveMode($user);
    $forGuest = $plugin->rules->effectiveMode(null);

    $restore();

    return $forUser === Settings::MODE_PREFETCH && $forGuest === Settings::MODE_PRERENDER;
});

check('both degrades to prefetch too', function() use ($plugin, $configure, $restore) {
    $user = new craft\elements\User(['admin' => false]);
    $configure(['mode' => Settings::MODE_BOTH, 'downgradeForLoggedIn' => true]);
    $mode = $plugin->rules->effectiveMode($user);
    $restore();

    return $mode === Settings::MODE_PREFETCH;
});

check('the degrade can be turned off', function() use ($plugin, $configure, $restore) {
    $user = new craft\elements\User(['admin' => false]);
    $configure(['mode' => Settings::MODE_PRERENDER, 'downgradeForLoggedIn' => false]);
    $mode = $plugin->rules->effectiveMode($user);
    $restore();

    return $mode === Settings::MODE_PRERENDER;
});

// ------------------------------------------------------------------ serialising

section('Serialising');

check('the tag is a speculationrules script holding valid JSON', function() use ($plugin, $configure, $restore) {
    $configure(['forGuests' => true, 'enabled' => true]);
    $tag = $plugin->rules->tag(null);
    $restore();

    if (!str_starts_with($tag, '<script type="speculationrules">') || !str_ends_with($tag, '</script>')) {
        return 'unexpected shape: ' . substr($tag, 0, 60);
    }

    $json = substr($tag, strlen('<script type="speculationrules">'), -strlen('</script>'));

    return json_decode($json, true) !== null;
});

check('a closing script tag in a setting cannot break out of the element', function() use ($plugin, $configure, $restore) {
    // A `<script>` holds raw text, so its content is never entity-decoded — the only defence is
    // not emitting the byte sequence at all. `JSON_HEX_TAG` is what does that.
    $configure(['excludePaths' => ['</script><script>alert(1)</script>']]);
    $tag = $plugin->rules->tag(null);
    $restore();

    $inner = substr($tag, strlen('<script type="speculationrules">'), -strlen('</script>'));

    return !str_contains($inner, '</script>') && !str_contains($inner, '<script')
        ?: 'escaped badly: ' . $inner;
});

check('slashes are not escaped, so the patterns stay readable', function() use ($plugin) {
    return str_contains($plugin->rules->json(null), '"/*"');
});

check('the backslash in a parameter pattern survives JSON encoding', function() use ($plugin, $configure, $restore) {
    // The JSON must carry `\\?`, which a parser turns back into the `\?` URLPattern needs.
    $configure(['excludeParams' => ['add-to-cart']]);
    $json = $plugin->rules->json(null);
    $restore();

    return str_contains($json, '\\\\?*(^|&)add-to-cart=*')
        && json_decode($json, true) !== null
        ?: 'not found in ' . substr($json, 0, 200);
});

check('a decoded pattern is exactly what URLPattern expects', function() use ($plugin, $configure, $restore) {
    $configure(['excludeParams' => ['add-to-cart']]);
    $document = json_decode($plugin->rules->json(null), true);
    $restore();

    $found = false;

    foreach ($document['prerender'][0]['where']['and'] ?? $document['prefetch'][0]['where']['and'] as $predicate) {
        if (($predicate['not']['href_matches'] ?? '') === '/*\?*(^|&)add-to-cart=*') {
            $found = true;
        }
    }

    return $found;
});

// ------------------------------------------------------------------ explaining

section('Explaining a URL');

check('an ordinary page is speculated', function() use ($plugin, $configure, $restore) {
    $configure(['mode' => Settings::MODE_PRERENDER, 'forGuests' => true, 'eagerness' => 'moderate']);
    $verdict = $plugin->rules->explain('/about', null);
    $restore();

    return $verdict->speculated && $verdict->prerender && !$verdict->prefetch
        && $verdict->summary() === 'prerender (moderate)';
});

check('the control panel is not, for anybody signed in', function() use ($plugin, $configure, $restore) {
    // A guest's rules leave the control panel out on purpose — see the signed-in-only check.
    $trigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/');
    $configure(['forLoggedIn' => true]);
    $verdict = $plugin->rules->explain('/' . $trigger . '/entries', new craft\elements\User());
    $restore();

    return $verdict->speculated === false;
});

check('an action query parameter is not', function() use ($plugin) {
    return $plugin->rules->explain('/page?action=users/logout', null)->speculated === false;
});

check('a token-bearing URL is not', function() use ($plugin) {
    $param = Craft::$app->getConfig()->getGeneral()->tokenParam;

    return $plugin->rules->explain('/page?' . $param . '=abc', null)->speculated === false;
});

check('a file is not, whatever the case of its extension', function() use ($plugin, $configure, $restore) {
    $configure(['excludeDownloads' => true, 'excludeExtensions' => ['pdf']]);
    $lower = $plugin->rules->explain('/files/a.pdf', null)->speculated;
    $upper = $plugin->rules->explain('/files/A.PDF', null)->speculated;
    $restore();

    return $lower === false && $upper === false;
});

check('another origin is not', function() use ($plugin) {
    return $plugin->rules->explain('https://example.com/page', null)->speculated === false;
});

check('every origin this install serves is same-origin', function() use ($plugin) {
    foreach ($plugin->rules->origins() as $origin) {
        if ($plugin->rules->explain($origin . '/page', null)->speculated !== true) {
            return $origin . ' was treated as cross-origin';
        }
    }

    return true;
});

check('a default port is not a different origin', function() use ($plugin) {
    // `https://example.com` and `https://example.com:443` are the one origin they actually are.
    $origins = $plugin->rules->origins();

    foreach ($origins as $origin) {
        if (str_starts_with($origin, 'https://') && !str_contains(substr($origin, 8), ':')) {
            return $plugin->rules->explain($origin . ':443/page', null)->speculated === true
                ?: $origin . ':443 was treated as cross-origin';
        }
    }

    return true; // No default-port origin on this install to test with.
});

check('the verdict names the exclusion that stopped it', function() use ($plugin) {
    $verdict = $plugin->rules->explain('/page?' . Craft::$app->getConfig()->getGeneral()->tokenParam . '=abc', null);

    return $verdict->blockedBy !== null && $verdict->blockedBy->source === Exclusion::SOURCE_CRAFT;
});

check('a speculated verdict admits what a URL cannot settle', function() use ($plugin, $configure, $restore) {
    $configure(['excludeNofollow' => true]);
    $verdict = $plugin->rules->explain('/about', null);
    $restore();

    return $verdict->caveats !== [] && str_contains($verdict->caveats[0], 'nofollow');
});

check('a disabled plugin explains itself', function() use ($plugin, $configure, $restore) {
    $configure(['enabled' => false]);
    $verdict = $plugin->rules->explain('/about', null);
    $restore();

    return $verdict->speculated === false && str_contains($verdict->reason, 'turned off');
});

// ------------------------------------------------------------------ the injector

section('Sec-Purpose');

check('a navigation is not speculative', function() use ($plugin) {
    return $plugin->injector->classify('') === Injector::PURPOSE_NONE;
});

check('prefetch is a prefetch', function() use ($plugin) {
    return $plugin->injector->classify('prefetch') === Injector::PURPOSE_PREFETCH;
});

check('prefetch;prerender is a prerender, not a prefetch', function() use ($plugin) {
    // The prerender value *contains* the prefetch token. Testing for "contains prefetch" first
    // classifies every prerender as a prefetch, and whatever was guarded on it silently stops.
    return $plugin->injector->classify('prefetch;prerender') === Injector::PURPOSE_PRERENDER;
});

check('the header is matched case-insensitively and trimmed', function() use ($plugin) {
    return $plugin->injector->classify('  Prefetch;Prerender ') === Injector::PURPOSE_PRERENDER
        && $plugin->injector->classify(' PREFETCH ') === Injector::PURPOSE_PREFETCH;
});

check('an unrecognised purpose is not treated as speculation', function() use ($plugin) {
    return $plugin->injector->classify('something-else') === Injector::PURPOSE_NONE;
});

section('Responses');

check('only text/html is a page', function() use ($plugin) {
    return $plugin->injector->isHtml('text/html; charset=UTF-8')
        && $plugin->injector->isHtml('TEXT/HTML')
        && !$plugin->injector->isHtml('application/rss+xml')
        && !$plugin->injector->isHtml('application/json');
});

/** A front-end HTML response, as the response filter would see one. */
$page = static function(string $content = '<html><body>hi</body></html>'): craft\web\Response {
    $response = new craft\web\Response();
    $response->setStatusCode(200);
    $response->getHeaders()->set('content-type', 'text/html; charset=UTF-8');
    $response->content = $content;

    return $response;
};

/** A front-end request, optionally speculative. */
$visit = static function(string $secPurpose = ''): craft\web\Request {
    $request = new craft\web\Request();

    if ($secPurpose !== '') {
        $request->getHeaders()->set('Sec-Purpose', $secPurpose);
    }

    return $request;
};

check('a plain page carries rules', function() use ($plugin, $page, $visit, $configure, $restore) {
    $configure(['enabled' => true, 'excludePages' => []]);
    $result = $plugin->injector->shouldServe($page(), $visit());
    $restore();

    return $result === true;
});

check('a prefetched page carries no rules', function() use ($plugin, $page, $visit, $configure, $restore) {
    $configure(['enabled' => true, 'skipOnSpeculative' => true, 'excludePages' => []]);
    $result = $plugin->injector->shouldServe($page(), $visit('prefetch'));
    $restore();

    return $result === false;
});

check('but a prefetched page still gets the cache headers', function() use ($plugin, $page, $visit, $configure, $restore) {
    // The prefetched response is the one being *cached*, so it is the one that most needs
    // `No-Vary-Search` — even though it is also the one deliberately carrying no rules. Gating the
    // header on "carries rules" strips it from the only copy that needed it, and the whole
    // tracking-parameter feature fails silently in exactly the case it exists for.
    $configure(['enabled' => true, 'skipOnSpeculative' => true, 'ignoreTrackingParams' => true,
        'trackingParams' => ['utm_source'], 'excludePages' => []]);

    $response = $page();
    $request = $visit('prefetch');
    $serves = $plugin->injector->servesRules($response, $request);

    if ($serves) {
        $plugin->injector->addResponseHeaders($response);
    }

    $restore();

    return $serves === true
        && $response->getHeaders()->get('No-Vary-Search') === 'params=("utm_source")'
        && $response->getHeaders()->get('Vary') === 'Sec-Purpose';
});

check('a non-HTML response is left entirely alone', function() use ($plugin, $page, $visit) {
    $response = $page();
    $response->getHeaders()->set('content-type', 'application/rss+xml');

    return $plugin->injector->servesRules($response, $visit()) === false;
});

check('a non-200 response is left alone', function() use ($plugin, $page, $visit) {
    $response = $page();
    $response->setStatusCode(404);

    return $plugin->injector->servesRules($response, $visit()) === false;
});

check('a disabled plugin serves nothing', function() use ($plugin, $page, $visit, $configure, $restore) {
    $configure(['enabled' => false]);
    $result = $plugin->injector->servesRules($page(), $visit());
    $restore();

    return $result === false;
});

check('skipOnSpeculative off means a prefetch gets rules too', function() use ($plugin, $page, $visit, $configure, $restore) {
    $configure(['enabled' => true, 'skipOnSpeculative' => false, 'excludePages' => []]);
    $result = $plugin->injector->shouldServe($page(), $visit('prefetch'));
    $restore();

    return $result === true;
});

check('Vary is not sent when there is no variance to declare', function() use ($plugin, $page, $configure, $restore) {
    $configure(['skipOnSpeculative' => false, 'ignoreTrackingParams' => false]);
    $response = $page();
    $plugin->injector->addResponseHeaders($response);
    $restore();

    return $response->getHeaders()->get('Vary') === null
        && $response->getHeaders()->get('No-Vary-Search') === null;
});

check('Vary appends rather than replacing', function() use ($plugin, $page, $configure, $restore) {
    $configure(['skipOnSpeculative' => true, 'varyOnSecPurpose' => true]);
    $response = $page();
    $response->getHeaders()->set('Vary', 'Accept-Encoding');
    $plugin->injector->addResponseHeaders($response);
    $restore();

    return $response->getHeaders()->get('Vary') === 'Accept-Encoding, Sec-Purpose';
});

check('Vary is not added twice', function() use ($plugin, $page, $configure, $restore) {
    $configure(['skipOnSpeculative' => true, 'varyOnSecPurpose' => true]);
    $response = $page();
    $plugin->injector->addResponseHeaders($response);
    $plugin->injector->addResponseHeaders($response);
    $restore();

    return $response->getHeaders()->get('Vary') === 'Sec-Purpose';
});

check('a Vary of * is left alone', function() use ($plugin, $page, $configure, $restore) {
    $configure(['skipOnSpeculative' => true, 'varyOnSecPurpose' => true]);
    $response = $page();
    $response->getHeaders()->set('Vary', '*');
    $plugin->injector->addResponseHeaders($response);
    $restore();

    return $response->getHeaders()->get('Vary') === '*';
});

check('an excluded page carries neither rules nor headers', function() use ($plugin, $page, $visit, $configure, $restore) {
    $configure(['enabled' => true, 'excludePages' => ['*']]);
    $result = $plugin->injector->servesRules($page(), $visit());
    $restore();

    return $result === false;
});

check('the version fingerprint changes when the rules do', function() use ($plugin, $configure, $restore) {
    $configure(['excludePaths' => []]);
    $before = $plugin->injector->version();
    $configure(['excludePaths' => ['checkout/*']]);
    $after = $plugin->injector->version();
    $restore();

    return $before !== $after && strlen($before) === 8;
});

check('guests and signed-in visitors are sent different rules-file URLs', function() use ($plugin) {
    $guest = $plugin->injector->documentUrl(null);
    $member = $plugin->injector->documentUrl(new craft\elements\User());

    return $guest !== $member
        && str_starts_with($guest, '/') && !str_starts_with($guest, '//')
        && str_contains($guest, 'speculatr/rules.json')
        && str_contains($guest, 'a=guest')
        && str_contains($member, 'a=user')
        && str_contains($guest, 'v=' . $plugin->injector->version());
});

check('only the guest rules file may sit in a shared cache', function() use ($plugin) {
    $guest = $plugin->injector->documentCacheControl(null);
    $member = $plugin->injector->documentCacheControl(new craft\elements\User());
    $admin = $plugin->injector->documentCacheControl(new craft\elements\User(['admin' => true]));

    return str_starts_with($guest, 'public')
        && str_starts_with($member, 'private')
        && str_starts_with($admin, 'private');
});

check('a guest cannot seed a shared cache under the signed-in or a stale URL', function() use ($plugin) {
    $v = $plugin->injector->version();

    return str_starts_with($plugin->injector->documentCacheControl(null, 'guest', $v), 'public')
        && str_starts_with($plugin->injector->documentCacheControl(null, 'user', $v), 'private')
        && str_starts_with($plugin->injector->documentCacheControl(null, 'guest', 'stale123'), 'private')
        && str_starts_with($plugin->injector->documentCacheControl(null, '', ''), 'private');
});

// ------------------------------------------------------------------ runtime additions

section('Template additions');

check('a prefetched URL becomes a list rule', function() use ($plugin, $configure, $restore) {
    $configure(['prefetchUrls' => []]);
    $plugin->rules->addUrls('/contact');
    $document = $plugin->rules->document(null);
    $plugin->rules->reset();
    $restore();

    $list = array_values(array_filter($document['prefetch'] ?? [], static fn($r) => ($r['source'] ?? '') === 'list'));

    return ($list[0]['urls'] ?? null) === ['/contact'];
});

check('a URL named twice is emitted once', function() use ($plugin, $configure, $restore) {
    // The settings list and a template's prefetch() very often name the same page.
    $configure(['prefetchUrls' => ['/contact']]);
    $plugin->rules->addUrls('/contact');
    $document = $plugin->rules->document(null);
    $plugin->rules->reset();
    $restore();

    $urls = [];

    foreach ($document['prefetch'] ?? [] as $rule) {
        if (($rule['source'] ?? '') === 'list') {
            $urls = [...$urls, ...$rule['urls']];
        }
    }

    return $urls === ['/contact'] ?: 'got ' . json_encode($urls, JSON_UNESCAPED_SLASHES);
});

check('a URL may still be both prefetched and prerendered', function() use ($plugin, $configure, $restore) {
    $configure(['prefetchUrls' => ['/contact']]);
    $plugin->rules->addUrls('/contact', 'prerender');
    $document = $plugin->rules->document(null);
    $plugin->rules->reset();
    $restore();

    $prerendered = array_values(array_filter($document['prerender'] ?? [], static fn($r) => ($r['source'] ?? '') === 'list'));

    return ($prerendered[0]['urls'] ?? null) === ['/contact'];
});

check('an unknown eagerness falls back rather than being emitted', function() use ($plugin) {
    $plugin->rules->addUrls('/contact', 'prefetch', 'enthusiastic');
    $document = $plugin->rules->runtimeDocument();
    $plugin->rules->reset();

    return $document['prefetch'][0]['eagerness'] === 'immediate';
});

check('an unknown action falls back to prefetch, never prerender', function() use ($plugin) {
    $plugin->rules->addUrls('/contact', 'nonsense');
    $document = $plugin->rules->runtimeDocument();
    $plugin->rules->reset();

    return array_keys($document) === ['prefetch'];
});

check('empty URLs add no rule at all', function() use ($plugin) {
    $plugin->rules->addUrls(['', '   ']);
    $document = $plugin->rules->runtimeDocument();
    $plugin->rules->reset();

    return $document === [];
});

check('a template can switch the page’s rules off', function() use ($plugin, $configure, $restore) {
    $configure(['forGuests' => true, 'enabled' => true]);
    $plugin->rules->suppress();
    $document = $plugin->rules->document(null);
    $tag = $plugin->rules->tag(null);
    $plugin->rules->reset();
    $restore();

    return $document === [] && $tag === '';
});

check('reset clears a suppression', function() use ($plugin) {
    $plugin->rules->suppress();
    $plugin->rules->reset();

    return $plugin->rules->isSuppressed() === false;
});

} finally {
    $restore();
}

echo "\n" . str_repeat('─', 60) . "\n";
echo sprintf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
