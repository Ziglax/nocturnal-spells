<?php
/* Starts the Discord OAuth2 flow. */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$state = bin2hex(random_bytes(16));
set_cookie(OAUTH_COOKIE, sign_payload(['state' => $state, 'exp' => time() + OAUTH_STATE_TTL]), OAUTH_STATE_TTL);

$params = http_build_query([
    'client_id' => secrets()['discord_client_id'],
    'response_type' => 'code',
    'redirect_uri' => secrets()['redirect_uri'],
    'scope' => 'identify guilds.members.read',
    'state' => $state,
]);

header('Location: https://discord.com/oauth2/authorize?' . $params, true, 302);
