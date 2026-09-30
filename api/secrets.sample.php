<?php
/*
 * Copy this file to api/secrets.php and fill in the values.
 * NEVER commit or publish secrets.php.
 *
 * discord_client_id / discord_client_secret: from your application at
 *   https://discord.com/developers/applications (OAuth2 tab).
 * app_secret: any long random string (at least 32 chars), used to sign
 *   session cookies. Generate one with e.g.:
 *   php -r "echo bin2hex(random_bytes(32));"
 * redirect_uri: the EXACT callback URL, also declared in the Discord
 *   application's OAuth2 redirect list.
 */

return [
    'discord_client_id' => 'YOUR_CLIENT_ID',
    'discord_client_secret' => 'YOUR_CLIENT_SECRET',
    'app_secret' => 'YOUR_LONG_RANDOM_SECRET_AT_LEAST_32_CHARS',
    'redirect_uri' => 'https://your-domain.tld/api/callback.php',
];
