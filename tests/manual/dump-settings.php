<?php
/**
 * Prints Speculatr's saved settings. For confirming a control panel save round-tripped.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-speculatr/tests/manual/dump-settings.php
 */
require getcwd() . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$settings = justinholtweb\speculatr\Plugin::getInstance()->getSettings();

foreach ($settings->toArray() as $key => $value) {
    echo str_pad($key, 24), json_encode($value), "\n";
}
