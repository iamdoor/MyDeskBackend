<?php

function findCategory(PDO $db, int $userId, $identifier, ?string $type = null): ?array {
    if ($identifier === null || $identifier === '') {
        return null;
    }

    $queryValue = null;
    $isNumeric = false;

    if (is_int($identifier)) {
        $queryValue = $identifier;
        $isNumeric = true;
    } elseif (is_string($identifier)) {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_digit($trimmed)) {
            $queryValue = (int) $trimmed;
            $isNumeric = true;
        } else {
            $queryValue = $trimmed;
        }
    } else {
        return null;
    }

    if ($isNumeric) {
        $sql = 'SELECT id, local_udid, type FROM categories WHERE id = ? AND user_id = ? AND is_deleted = 0';
        $params = [$queryValue, $userId];
    } else {
        $sql = 'SELECT id, local_udid, type FROM categories WHERE local_udid = ? AND user_id = ? AND is_deleted = 0';
        $params = [$queryValue, $userId];
    }

    if ($type !== null) {
        $sql .= ' AND type = ?';
        $params[] = $type;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $category = $stmt->fetch();

    return $category ?: null;
}

function findSubCategory(PDO $db, int $userId, $identifier): ?array {
    if ($identifier === null || $identifier === '') {
        return null;
    }

    $queryValue = null;
    $isNumeric = false;

    if (is_int($identifier)) {
        $queryValue = $identifier;
        $isNumeric = true;
    } elseif (is_string($identifier)) {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_digit($trimmed)) {
            $queryValue = (int) $trimmed;
            $isNumeric = true;
        } else {
            $queryValue = $trimmed;
        }
    } else {
        return null;
    }

    if ($isNumeric) {
        $sql = 'SELECT id, category_id, local_udid FROM sub_categories WHERE id = ? AND user_id = ? AND is_deleted = 0';
        $params = [$queryValue, $userId];
    } else {
        $sql = 'SELECT id, category_id, local_udid FROM sub_categories WHERE local_udid = ? AND user_id = ? AND is_deleted = 0';
        $params = [$queryValue, $userId];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sub = $stmt->fetch();

    return $sub ?: null;
}
