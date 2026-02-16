<?php
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Dropping legacy foreign key (if exists)...\n";
    try {
        $db->exec('ALTER TABLE sub_categories DROP FOREIGN KEY sub_categories_ibfk_1');
        echo "Foreign key dropped.\n";
    } catch (PDOException $e) {
        echo "Skip dropping FK: " . $e->getMessage() . "\n";
    }

    echo "Modifying column type...\n";
    $db->exec('ALTER TABLE sub_categories MODIFY category_id VARCHAR(36) NOT NULL');
    echo "Column modified.\n";

    echo "Updating category references to local_udid ...\n";
    $sql = "UPDATE sub_categories sc
        JOIN categories c ON sc.category_id REGEXP '^[0-9]+' AND CAST(sc.category_id AS UNSIGNED) = c.id
        SET sc.category_id = c.local_udid";
    $count = $db->exec($sql);
    echo "Affected rows: $count\n";

    echo "Done.\n";
} catch (PDOException $e) {
    echo "SQL Error: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
