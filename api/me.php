<?php
/* Returns the current session: user identity and permission level. */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = current_user();
if (!$user) json_out(['error' => 'Not authenticated.'], 401);

json_out([
    'user' => ['id' => $user['uid'], 'name' => $user['name']],
    'perm' => $user['perm'],
    'expiresAt' => $user['exp'],
]);
