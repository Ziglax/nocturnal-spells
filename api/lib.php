<?php
/*
 * Shared helpers for the Spell Turn-ins API.
 *
 * Auth model: Discord OAuth2 at login only. The resulting permission
 * ("write" for officers, "read" for raiders, mapped from Discord role ids in
 * config.json) is stored in a stateless HMAC-signed cookie valid 7 days.
 * Role changes therefore apply at the next login.
 */

declare(strict_types=1);

const SESSION_COOKIE = 'spelltracker_sess';
const OAUTH_COOKIE = 'spelltracker_oauth';
const SESSION_TTL = 7 * 86400;      // 7-day sessions, per the guild's choice
const OAUTH_STATE_TTL = 600;        // 10 minutes to complete the Discord login
const DISCORD_API = 'https://discord.com/api/v10';

function project_root(): string {
    return dirname(__DIR__);
}

function cfg(): array {
    static $cfg = null;
    if ($cfg === null) {
        $raw = file_get_contents(project_root() . '/config.json');
        $cfg = json_decode($raw ?: '', true);
        if (!is_array($cfg) || empty($cfg['guildId']) || empty($cfg['roles'])) {
            json_out(['error' => 'Server misconfigured: config.json is missing or invalid.'], 500);
        }
    }
    return $cfg;
}

function secrets(): array {
    static $secrets = null;
    if ($secrets === null) {
        $path = __DIR__ . '/secrets.php';
        if (!is_file($path)) {
            json_out(['error' => 'Server misconfigured: api/secrets.php is missing (copy secrets.sample.php).'], 500);
        }
        $secrets = require $path;
        foreach (['discord_client_id', 'discord_client_secret', 'app_secret', 'redirect_uri'] as $key) {
            if (empty($secrets[$key]) || str_starts_with((string) $secrets[$key], 'YOUR_')) {
                json_out(['error' => "Server misconfigured: '$key' is not set in api/secrets.php."], 500);
            }
        }
        if (strlen((string) $secrets['app_secret']) < 32) {
            json_out(['error' => 'Server misconfigured: app_secret must be at least 32 characters.'], 500);
        }
    }
    return $secrets;
}

/* ---------------------------------------------------------------- output */

function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function html_out(string $title, string $message, int $code = 403): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{background:#0e1117;color:#dfe5ee;font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0}'
        . 'div{max-width:420px;text-align:center;padding:24px}h1{color:#d4af6a;font-size:1.2rem}a{color:#d4af6a}</style></head>'
        . '<body><div><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p>'
        . '<p><a href="../">Back to the tracker</a></p></div></body></html>';
    exit;
}

/* -------------------------------------------------------- signed cookies */

function b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $txt): string|false {
    return base64_decode(strtr($txt, '-_', '+/'));
}

function sign_payload(array $payload): string {
    $body = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $mac = hash_hmac('sha256', $body, secrets()['app_secret']);
    return $body . '.' . $mac;
}

function verify_payload(?string $cookie): ?array {
    if (!$cookie || substr_count($cookie, '.') !== 1) return null;
    [$body, $mac] = explode('.', $cookie, 2);
    $expected = hash_hmac('sha256', $body, secrets()['app_secret']);
    if (!hash_equals($expected, $mac)) return null;
    $payload = json_decode(b64url_decode($body) ?: '', true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function set_cookie(string $name, string $value, int $ttl): void {
    setcookie($name, $value, [
        'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* ----------------------------------------------------------------- auth */

function current_user(): ?array {
    $payload = verify_payload($_COOKIE[SESSION_COOKIE] ?? null);
    if (!$payload || !in_array($payload['perm'] ?? '', ['read', 'write'], true)) return null;
    // Sessions are stateless; bumping sessionEpoch in config.json revokes
    // every outstanding session (e.g. after demoting an officer) without
    // rotating app_secret.
    if ((int) ($payload['epoch'] ?? 0) !== (int) (cfg()['sessionEpoch'] ?? 1)) return null;
    return $payload;
}

// Returns the session or ends the request with 401/403.
function require_perm(string $perm): array {
    $user = current_user();
    if (!$user) json_out(['error' => 'Not authenticated.'], 401);
    if ($perm === 'write' && $user['perm'] !== 'write') {
        json_out(['error' => 'Read-only access.'], 403);
    }
    return $user;
}

// Cheap CSRF defense on mutating requests: this custom header cannot be sent
// by cross-site forms, and cross-origin fetch would need a CORS preflight
// that this API never grants.
function require_csrf_header(): void {
    if (($_SERVER['HTTP_X_SPELLTRACKER'] ?? '') !== '1') {
        json_out(['error' => 'Missing request header.'], 400);
    }
}

/* -------------------------------------------------------------- discord */

function discord_request(string $method, string $url, array $headers = [], ?array $form = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($body ?: '', true);
    return ['code' => $code, 'data' => is_array($data) ? $data : []];
}

/* ---------------------------------------------------------------- state */

function state_path(): string {
    return project_root() . '/storage/state.json';
}

// storage/ must never be web-reachable. The .htaccess ships with the code,
// but FTP clients commonly skip dotfiles, so recreate it if it is missing.
function ensure_storage_guard(string $dir): void {
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
}

// Run $fn while holding an exclusive lock. The lock lives on a dedicated
// lock file (never renamed, so every request serializes on the same inode)
// and the state itself is replaced atomically via temp file + rename():
// a crashed or partial write can never corrupt or truncate the live file.
function with_state_lock(callable $fn): mixed {
    $path = state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    ensure_storage_guard($dir);
    $lock = fopen($dir . '/state.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        json_out(['error' => 'Could not lock the state file.'], 500);
    }
    try {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw !== false && trim($raw) !== '') {
            $doc = json_decode($raw, true);
            if (!is_array($doc)) {
                // Never silently replace unreadable data with an empty doc:
                // that would wipe the tracker. Keep the bytes for recovery.
                json_out(['error' => 'storage/state.json exists but is not valid JSON; refusing to touch it.'], 500);
            }
        } else {
            $doc = ['rev' => 0, 'state' => null, 'updatedAt' => null, 'updatedBy' => null];
        }
        return $fn($doc, function (array $newDoc) use ($path) {
            $json = json_encode($newDoc, JSON_UNESCAPED_UNICODE);
            $tmp = $path . '.tmp';
            if (!is_string($json)
                || file_put_contents($tmp, $json) !== strlen($json)
                || !rename($tmp, $path)) {
                @unlink($tmp);
                json_out(['error' => 'Could not write the state file.'], 500);
            }
        });
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
