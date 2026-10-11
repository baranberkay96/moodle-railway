<?php
// Moodle configuration for Railway. Every value comes from environment variables, so this
// file is baked into the image and never edited on the server. See config-dist.php in the
// Moodle tree for the meaning of each setting.

unset($CFG);
global $CFG;
$CFG = new stdClass();

/**
 * Read an environment variable; empty values count as unset.
 */
$railwayenv = static function (string $name, ?string $default = null): ?string {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
};

// 1. Database (Railway Postgres over the private network).
$CFG->dbtype    = $railwayenv('MOODLE_DB_TYPE', 'pgsql');
$CFG->dblibrary = 'native';
$CFG->dbhost    = $railwayenv('MOODLE_DB_HOST', 'localhost');
$CFG->dbname    = $railwayenv('MOODLE_DB_NAME', 'moodle');
$CFG->dbuser    = $railwayenv('MOODLE_DB_USER', 'postgres');
$CFG->dbpass    = $railwayenv('MOODLE_DB_PASSWORD', '');
$CFG->prefix    = $railwayenv('MOODLE_DB_PREFIX', 'mdl_');
$CFG->dboptions = [
    'dbpersist' => false,
    'dbsocket' => false,
    'dbport' => $railwayenv('MOODLE_DB_PORT', '5432'),
    'connecttimeout' => 10,
];

// 2. Site location. Railway terminates TLS at its edge (sslproxy).
$CFG->wwwroot = rtrim((string) $railwayenv('MOODLE_URL', 'http://localhost'), '/');
$CFG->sslproxy = str_starts_with($CFG->wwwroot, 'https://');
$CFG->routerconfigured = true;

// 3. Data directories: moodledata on the Railway volume, per-container cache on local disk.
$CFG->dataroot = $railwayenv('MOODLE_DATAROOT', '/var/moodledata');
$CFG->localcachedir = '/var/cache/moodle';
$CFG->directorypermissions = 02770;
$CFG->admin = 'admin';

// 4. Sessions in Redis (falls back to database sessions when MOODLE_REDIS_HOST is empty).
if ($railwayenv('MOODLE_REDIS_HOST') !== null) {
    $CFG->session_handler_class = '\core\session\redis';
    $CFG->session_redis_host = $railwayenv('MOODLE_REDIS_HOST');
    $CFG->session_redis_port = (int) $railwayenv('MOODLE_REDIS_PORT', '6379');
    $CFG->session_redis_auth = (string) $railwayenv('MOODLE_REDIS_PASSWORD', '');
    $CFG->session_redis_database = (int) $railwayenv('MOODLE_REDIS_SESSION_DB', '0');
    $CFG->session_redis_prefix = $railwayenv('MOODLE_REDIS_SESSION_PREFIX', 'mdl_sess_');
    $CFG->session_redis_acquire_lock_timeout = 120;
    $CFG->session_redis_lock_expire = 7200;
} else {
    $CFG->session_handler_class = '\core\session\database';
}

// 5. Outgoing mail (optional). Railway allows outbound SMTP on the Pro plan only.
if ($railwayenv('MOODLE_SMTP_HOST') !== null) {
    $CFG->smtphosts = $railwayenv('MOODLE_SMTP_HOST') . ':' . $railwayenv('MOODLE_SMTP_PORT', '587');
    $CFG->smtpsecure = $railwayenv('MOODLE_SMTP_SECURE', 'tls');
    $CFG->smtpauthtype = 'LOGIN';
    $CFG->smtpuser = (string) $railwayenv('MOODLE_SMTP_USER', '');
    $CFG->smtppass = (string) $railwayenv('MOODLE_SMTP_PASSWORD', '');
}
if ($railwayenv('MOODLE_NOREPLY_ADDRESS') !== null) {
    $CFG->noreplyaddress = $railwayenv('MOODLE_NOREPLY_ADDRESS');
}

// 6. Container hardening: code is read-only, executable paths are fixed here, cron is CLI-only.
$CFG->disableupdateautodeploy = true;
$CFG->preventexecpath = true;
$CFG->pathtogs = '/usr/bin/gs';
$CFG->pathtopdftoppm = '/usr/bin/pdftoppm';
$CFG->pathtodu = '/usr/bin/du';
$CFG->cronclionly = true;

// 7. Optional raw PHP for anything else (evaluated last, so it wins).
$railwayextra = $railwayenv('MOODLE_CONFIG_EXTRA');
if ($railwayextra !== null) {
    eval($railwayextra);
}
unset($railwayenv, $railwayextra);

require_once(__DIR__ . '/lib/setup.php');
