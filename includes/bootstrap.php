<?php
/**
 * App Bootstrap (pages)
 *
 * Starts session/auth, opens DB connection, and loads shared helpers.
 * Include this at the top of page scripts to avoid repeated requires.
 */

// Load .env first so config files (auth/db) can read env overrides on hosted envs
if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
    if (function_exists('load_app_env')) {
        load_app_env();
    }
}

require_once __DIR__ . '/../config/auth.php';   // session + auth helpers
require_once __DIR__ . '/../config/db.php';     // $conn + get_db_connection()
require_once __DIR__ . '/helpers.php';          // UI/formatting helpers
<<<<<<< HEAD
=======

// Load .env if present (local dev convenience)
// This runs after helpers; the env loader is idempotent and safe if .env is missing
if (file_exists(__DIR__ . '/env.php')) {
	require_once __DIR__ . '/env.php';
	if (function_exists('load_app_env')) {
		load_app_env();
	}
}

// Ensure all PHP date/time functions use Philippine time by default
try {
	$appTz = getenv('APP_TIMEZONE');
	if (!$appTz || trim($appTz) === '') { $appTz = 'Asia/Manila'; }
	if (function_exists('date_default_timezone_set')) {
		@date_default_timezone_set($appTz);
	}
} catch (Throwable $e) { /* ignore */ }
>>>>>>> 48ebce5 (feat(moderation+time): Add report moderation workflow (pending/approved/denied), admin review queue, public shows approved only; profile moderation chip; set PHP timezone to Asia/Manila and align MySQL session time_zone)
