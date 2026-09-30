<?php
/*
 * Discord OAuth2 callback: exchanges the code, reads the member's roles on
 * the configured guild, maps them to a permission and opens a 7-day session.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (isset($_GET['error'])) {
    html_out('Login cancelled', 'Discord reported: ' . (string) $_GET['error'], 403);
}

// CSRF: the state must match the value we signed before redirecting.
$oauth = verify_payload($_COOKIE[OAUTH_COOKIE] ?? null);
set_cookie(OAUTH_COOKIE, '', 0);
$state = (string) ($_GET['state'] ?? '');
if (!$oauth || $state === '' || !hash_equals($oauth['state'], $state)) {
    html_out('Login failed', 'Invalid or expired login attempt, please try again.', 403);
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    html_out('Login failed', 'Discord sent no authorization code.', 400);
}

// Exchange the code for a user token.
$token = discord_request('POST', DISCORD_API . '/oauth2/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    [
        'client_id' => secrets()['discord_client_id'],
        'client_secret' => secrets()['discord_client_secret'],
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => secrets()['redirect_uri'],
    ]);
if ($token['code'] !== 200 || empty($token['data']['access_token'])) {
    html_out('Login failed', 'Could not exchange the Discord authorization code.', 502);
}
$bearer = ['Authorization: Bearer ' . $token['data']['access_token']];

// Who is logging in?
$me = discord_request('GET', DISCORD_API . '/users/@me', $bearer);
if ($me['code'] !== 200 || empty($me['data']['id'])) {
    html_out('Login failed', 'Could not read your Discord profile.', 502);
}

// Their roles on the guild decide the permission.
$member = discord_request('GET', DISCORD_API . '/users/@me/guilds/' . rawurlencode((string) cfg()['guildId']) . '/member', $bearer);
if ($member['code'] !== 200) {
    html_out('No access', 'You are not a member of the guild Discord server.', 403);
}
$roles = array_map('strval', $member['data']['roles'] ?? []);

$perm = null;
if (array_intersect($roles, array_map('strval', cfg()['roles']['officer'] ?? []))) $perm = 'write';
elseif (array_intersect($roles, array_map('strval', cfg()['roles']['raider'] ?? []))) $perm = 'read';
if ($perm === null) {
    html_out('No access', 'Your Discord account has neither the officer nor the raider role.', 403);
}

$name = $member['data']['nick']
    ?? $me['data']['global_name']
    ?? $me['data']['username']
    ?? 'unknown';

set_cookie(SESSION_COOKIE, sign_payload([
    'uid' => (string) $me['data']['id'],
    'name' => (string) $name,
    'perm' => $perm,
    'epoch' => (int) (cfg()['sessionEpoch'] ?? 1),
    'exp' => time() + SESSION_TTL,
]), SESSION_TTL);

header('Location: ../', true, 302);
