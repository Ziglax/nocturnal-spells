<?php
/*
 * Shared tracker state.
 *   GET: full document, any authenticated member (officer or raider).
 *   POST (or PUT): replace the state, officers only, with optimistic
 *        locking: the client sends the revision it based its edit on; a
 *        mismatch returns 409 with the current document so the client can
 *        reload. POST is the primary verb because some shared hosts block
 *        PUT at the Apache/ModSecurity level.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_perm('read');
    $doc = with_state_lock(fn (array $doc) => $doc);
    json_out($doc);
}

if ($method === 'POST' || $method === 'PUT') {
    $user = require_perm('write');
    require_csrf_header();

    // Bound the body BEFORE buffering/decoding it (post_max_size does not
    // apply to php://input on PUT).
    $maxBody = 2_100_000;
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
        json_out(['error' => 'State too large.'], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBody + 1);
    if ($raw === false || strlen($raw) > $maxBody) {
        json_out(['error' => 'State too large.'], 413);
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || !is_array($body['state'] ?? null) || !is_int($body['rev'] ?? null)) {
        json_out(['error' => 'Body must be {"rev": int, "state": object}.'], 400);
    }

    $result = with_state_lock(function (array $doc, callable $persist) use ($body, $user) {
        if ($body['rev'] !== $doc['rev']) {
            return ['conflict' => true, 'doc' => $doc];
        }
        $newDoc = [
            'rev' => $doc['rev'] + 1,
            'state' => $body['state'],
            'updatedAt' => gmdate('c'),
            'updatedBy' => $user['name'],
        ];
        $persist($newDoc);
        return ['conflict' => false, 'doc' => $newDoc];
    });

    if ($result['conflict']) {
        json_out($result['doc'], 409);
    }
    json_out(['rev' => $result['doc']['rev'], 'updatedAt' => $result['doc']['updatedAt']]);
}

json_out(['error' => 'Method not allowed.'], 405);
