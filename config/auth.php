<?php
// Use MySQL-backed sessions on hosting to persist across instances.
// Falls back to file sessions if DB is unavailable.
if (!class_exists('DbSessionHandler')) {
    class DbSessionHandler implements SessionHandlerInterface {
        private int $ttl;
        public function __construct(int $ttl = 604800) { // 7 days default
            $this->ttl = max(300, $ttl);
        }
        private function db() {
            // Lazy import to avoid hard dependency if DB is down
            require_once __DIR__ . '/db.php';
            return get_db_connection();
        }
        private function ensureTable($db): void {
            static $done = false; if ($done) return; $done = true;
            $db->query("CREATE TABLE IF NOT EXISTS php_sessions (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                data LONGTEXT NOT NULL,
                expires INT UNSIGNED NOT NULL,
                INDEX (expires)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        public function open($savePath, $sessionName): bool { return true; }
        public function close(): bool { return true; }
        public function read($id): string {
            try {
                $db = $this->db();
                $this->ensureTable($db);
                $stmt = $db->prepare("SELECT data FROM php_sessions WHERE id = ? AND expires > UNIX_TIMESTAMP()");
                $stmt->bind_param('s', $id);
                $stmt->execute();
                $stmt->bind_result($data);
                if ($stmt->fetch()) {
                    $stmt->close();
                    $db->close();
                    $raw = base64_decode($data, true);
                    return $raw !== false ? $raw : '';
                }
                $stmt->close();
                $db->close();
            } catch (Throwable $e) { /* fallback to empty */ }
            return '';
        }
        public function write($id, $data): bool {
            try {
                $db = $this->db();
                $this->ensureTable($db);
                $encoded = base64_encode($data);
                $expires = time() + $this->ttl;
                $stmt = $db->prepare("INSERT INTO php_sessions (id, data, expires) VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE data = VALUES(data), expires = VALUES(expires)");
                $stmt->bind_param('ssi', $id, $encoded, $expires);
                $ok = $stmt->execute();
                $stmt->close();
                $db->close();
                return $ok;
            } catch (Throwable $e) { return false; }
        }
        public function destroy($id): bool {
            try { $db = $this->db(); $stmt = $db->prepare("DELETE FROM php_sessions WHERE id = ?"); $stmt->bind_param('s', $id); $stmt->execute(); $stmt->close(); $db->close(); } catch (Throwable $e) {}
            return true;
        }
        public function gc($max_lifetime): int|false {
            try { $db = $this->db(); $db->query("DELETE FROM php_sessions WHERE expires <= UNIX_TIMESTAMP()"); $affected = $db->affected_rows; $db->close(); return max(0, (int)$affected); } catch (Throwable $e) { return 0; }
        }
    }
}

// Harden session cookie settings before starting the session
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Ensure sessions are stored in a stable, writable path (helps on hosts where default /tmp isn't shared/persistent)
    $defaultPath = __DIR__ . '/../tmp_sessions';
    $savePath = $defaultPath;
    try {
        if (!is_dir($defaultPath)) { @mkdir($defaultPath, 0777, true); }
        if (!is_writable($defaultPath)) { $savePath = sys_get_temp_dir(); }
    } catch (Throwable $e) { $savePath = sys_get_temp_dir(); }
    if (is_string($savePath) && $savePath !== '') { @session_save_path($savePath); }

    // Detect HTTPS robustly (works behind proxies/CDNs)
    $xfProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $xfSsl   = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    $cfVis   = (string)($_SERVER['HTTP_CF_VISITOR'] ?? ''); // e.g. {"scheme":"https"}
    $cfHttps = stripos($cfVis, '"https"') !== false;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || ($xfProto === 'https')
        || ($xfSsl === 'on')
        || $cfHttps;
    // Ensure cookies work across the whole site and are HTTP-only
    // SameSite=Lax allows POST->redirect flows to carry the cookie
    if (function_exists('session_set_cookie_params')) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    // Choose DB-backed sessions for better persistence on hosting (env override: USE_DB_SESSIONS=0 to disable)
    $useDbSessions = getenv('USE_DB_SESSIONS');
    if ($useDbSessions === false || $useDbSessions === '' || $useDbSessions === '1') {
        $ttl = (int)(getenv('SESSION_LIFETIME') ?: 604800);
        try {
            $handler = new DbSessionHandler($ttl);
            @session_set_save_handler($handler, true);
        } catch (Throwable $e) { /* fall back silently */ }
    }
    // Use a custom session name to avoid conflicts with other PHP apps on the same host
    if (function_exists('session_name')) {
        @session_name('GOMKSESSID');
    }
    // Strengthen session behavior
    if (function_exists('ini_set')) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        // Allow Lax cookie for top-level POST redirects; upgrade to Strict if app is fully same-site navigations
        @ini_set('session.cookie_samesite', 'Lax');
        // Keep server-side session files around longer to reduce unexpected expirations
        @ini_set('session.gc_maxlifetime', '604800'); // 7 days
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
    }
    session_start();
}

const ADMIN_EMAIL = 'admin';
const ADMIN_PASSWORD = 'admin';

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function is_admin(): bool
{
    return isset($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'admin';
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: profile.php');
        exit;
    }
}

function initialize_demo_state(): void
{
    if (!isset($_SESSION['reports'])) {
        $_SESSION['reports'] = [
            [
                'id' => 1,
                'title' => 'Flooding at Bulelak Street',
                'category' => 'Community',
                'status' => 'unresolved',
                'reporter' => 'Miguel De Guzman',
                'location' => 'Barangay San Roque',
                'submitted_at' => '2025-09-20 08:24',
                'summary' => 'Heavy rainfall overnight caused knee-deep flooding along Bulelak Street. Residents report difficulty accessing tricycle terminals and need drainage assistance.',
                'image' => 'uploads/flooding.png',
                'tags' => ['community', 'flooding', 'drainage'],
            ],
            [
                'id' => 2,
                'title' => 'Illegal Parking along Riverbanks',
                'category' => 'Public Safety',
                'status' => 'in_progress',
                'reporter' => 'Aira Mendoza',
                'location' => 'Riverbanks Center',
                'submitted_at' => '2025-09-22 14:08',
                'summary' => 'Multiple private vehicles are blocking the emergency lane at Riverbanks. Traffic aides have already been notified but require towing support.',
                'image' => 'uploads/no-parking.png',
                'tags' => ['public-safety', 'traffic', 'riverbanks'],
            ],
            [
                'id' => 3,
                'title' => 'Potholes at J.P. Rizal',
                'category' => 'Infrastructure',
                'status' => 'solved',
                'reporter' => 'Luis Santos',
                'location' => 'J.P. Rizal Street',
                'submitted_at' => '2025-09-26 09:47',
                'summary' => 'Large potholes have appeared near the public market. DPWH crew already patched the affected lane and reopened traffic.',
                'image' => 'uploads/road-construction.png',
                'tags' => ['infrastructure', 'roads'],
            ],
            [
                'id' => 4,
                'title' => 'Riverbank tree trimming',
                'category' => 'Maintenance',
                'status' => 'unresolved',
                'reporter' => 'Jessa Cruz',
                'location' => 'Marikina River Park',
                'submitted_at' => '2025-09-29 07:32',
                'summary' => 'Overgrown branches are leaning over the jogging path and risk falling on passersby. Residents request trimming before the weekend fun run.',
                'image' => null,
                'tags' => ['maintenance', 'parks'],
            ],
        ];
    }

    if (!isset($_SESSION['announcements'])) {
        $_SESSION['announcements'] = [
            [
                'id' => 1,
                'title' => 'Scheduled road repairs on Shoe Avenue',
                'body' => 'Maintenance crews will be onsite from Oct 8-10. Expect partial lane closures.',
                'created_at' => date('c'),
                'image' => null,
            ],
        ];
    }
}

initialize_demo_state();
