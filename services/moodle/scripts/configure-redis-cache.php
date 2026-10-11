<?php
// Point Moodle's application cache (MUC) at Redis, using Moodle's own cache config API.
// Idempotent: creates the "redis" store instance once, updates it when the connection
// settings change, and maps the application cache mode to it. Session and request caches
// keep Moodle's defaults. Runs as the web server user after install/upgrade.

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');

use core_cache\config_writer;
use core_cache\store;

$host = getenv('MOODLE_REDIS_HOST') ?: '';
if ($host === '') {
    mtrace('[redis-cache] MOODLE_REDIS_HOST is empty: keeping the default file cache');
    exit(0);
}

$configuration = [
    'server' => $host . ':' . (getenv('MOODLE_REDIS_PORT') ?: '6379'),
    'prefix' => getenv('MOODLE_REDIS_CACHE_PREFIX') ?: 'mdl_muc_',
    'password' => getenv('MOODLE_REDIS_PASSWORD') ?: '',
    'serializer' => 1, // Redis::SERIALIZER_PHP
    'compressor' => 0,
    'connectiontimeout' => 3,
];

try {
    if (!class_exists('Redis')) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', 'phpredis extension missing');
    }
    $writer = config_writer::instance();
    $stores = $writer->get_all_stores();
    if (!array_key_exists('redis', $stores)) {
        $writer->add_store_instance('redis', 'redis', $configuration);
        mtrace('[redis-cache] created store instance "redis" (' . $configuration['server'] . ')');
    } else if (($stores['redis']['configuration'] ?? []) != $configuration) {
        $writer->edit_store_instance('redis', 'redis', $configuration);
        mtrace('[redis-cache] updated store instance "redis" (' . $configuration['server'] . ')');
    }
    $writer->set_mode_mappings([
        store::MODE_APPLICATION => ['redis'],
        store::MODE_SESSION => ['default_session'],
        store::MODE_REQUEST => ['default_request'],
    ]);
    mtrace('[redis-cache] application cache mapped to Redis');
} catch (Throwable $e) {
    // Moodle keeps working on its file cache; report and continue booting.
    mtrace('[redis-cache] could not configure Redis cache: ' . $e->getMessage());
    exit(0);
}
