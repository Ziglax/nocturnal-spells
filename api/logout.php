<?php
/*
 * Ends the session. POST + the custom header only, so a third-party page
 * cannot force-log-out a member via a cross-site GET navigation.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'Method not allowed.'], 405);
}
require_csrf_header();

set_cookie(SESSION_COOKIE, '', 0);
json_out(['ok' => true]);
