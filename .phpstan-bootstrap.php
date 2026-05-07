<?php
/**
 * PHPStan-only bootstrap. Defines plugin constants so files analysed in
 * isolation can resolve them. Never executed at runtime — referenced from
 * phpstan.neon `bootstrapFiles`.
 */

declare(strict_types=1);

if (!defined('OPENTRUST_VERSION')) {
    define('OPENTRUST_VERSION', '1.0.0');
}
if (!defined('OPENTRUST_PLUGIN_DIR')) {
    define('OPENTRUST_PLUGIN_DIR', __DIR__ . '/');
}
if (!defined('OPENTRUST_PLUGIN_URL')) {
    define('OPENTRUST_PLUGIN_URL', 'https://example.com/wp-content/plugins/opentrust/');
}
if (!defined('OPENTRUST_PLUGIN_FILE')) {
    define('OPENTRUST_PLUGIN_FILE', __DIR__ . '/opentrust.php');
}
if (!defined('OPENTRUST_DB_VERSION')) {
    define('OPENTRUST_DB_VERSION', 2);
}
