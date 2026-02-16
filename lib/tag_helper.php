<?php
require_once __DIR__ . '/auth.php';

function ensureTagLocalUdid(PDO $db, int $userId, string $tagName): string {
    $trimmed = trim($tagName);
    if ($trimmed === '') {
        throw new InvalidArgumentException('Tag name cannot be empty');
    }

    $stmt = $db->prepare('SELECT local_udid FROM tags WHERE user_id = ? AND name = ?');
    $stmt->execute([$userId, $trimmed]);
    $tag = $stmt->fetch();

    if ($tag) {
        return $tag['local_udid'];
    }

    $localUdid = generateUUID();
    $insert = $db->prepare('INSERT INTO tags (user_id, local_udid, name) VALUES (?, ?, ?)');
    $insert->execute([$userId, $localUdid, $trimmed]);
    return $localUdid;
}
