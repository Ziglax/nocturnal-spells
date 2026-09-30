<?php
/*
 * Deployment self-diagnostic (any authenticated member). Open
 * https://your-domain/api/health.php while logged in to check the host.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_perm('read');

$dir = project_root() . '/storage';
$dirExists = is_dir($dir);
$probe = false;
if ($dirExists) {
    $probeFile = $dir . '/write-test.tmp';
    $probe = @file_put_contents($probeFile, 'ok') !== false;
    @unlink($probeFile);
}

json_out([
    'php' => PHP_VERSION,
    'curl' => extension_loaded('curl'),
    'https' => is_https(),
    'storageDirExists' => $dirExists,
    'storageWritable' => $probe,
    'storageGuard' => is_file($dir . '/.htaccess'),
    'stateExists' => is_file(state_path()),
    'you' => ['name' => $user['name'], 'perm' => $user['perm']],
]);
