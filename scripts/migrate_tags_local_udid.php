<?php
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Ensuring local_udid column exists on tags...\n";
    try {
        $db->exec('ALTER TABLE tags ADD COLUMN local_udid VARCHAR(36) NULL');
        echo "Column added.\n";
    } catch (PDOException $e) {
        echo "Skip adding column: " . $e->getMessage() . "\n";
    }

    echo "Populating missing local_udid values...\n";
    $count = $db->exec("UPDATE tags SET local_udid = UUID() WHERE local_udid IS NULL OR local_udid = ''");
    echo "Updated $count rows.\n";

    echo "Enforcing NOT NULL + indexes...\n";
    $db->exec('ALTER TABLE tags MODIFY local_udid VARCHAR(36) NOT NULL');
    try {
        $db->exec('ALTER TABLE tags DROP INDEX uk_user_tag');
    } catch (PDOException $e) {
        // index may already be renamed
    }
    try {
        $db->exec('ALTER TABLE tags ADD UNIQUE KEY uk_user_tag_name (user_id, name)');
    } catch (PDOException $e) {
        echo "Skip adding uk_user_tag_name: " . $e->getMessage() . "\n";
    }
    try {
        $db->exec('ALTER TABLE tags ADD UNIQUE KEY uk_user_tag_local (user_id, local_udid)');
    } catch (PDOException $e) {
        echo "Skip adding uk_user_tag_local: " . $e->getMessage() . "\n";
    }

    $tables = [
        'cell_tags' => ['entity_column' => 'cell_id', 'table' => 'cells', 'table_column' => 'id', 'local_column' => 'local_udid'],
        'data_sheet_tags' => ['entity_column' => 'data_sheet_id', 'table' => 'data_sheets', 'table_column' => 'id', 'local_column' => 'local_udid'],
        'desktop_tags' => ['entity_column' => 'desktop_id', 'table' => 'desktops', 'table_column' => 'id', 'local_column' => 'local_udid'],
    ];

    foreach ($tables as $pivot => $info) {
        echo "Migrating $pivot ...\n";
        // Drop FK referencing tags
        try {
            $db->exec("ALTER TABLE $pivot DROP FOREIGN KEY {$pivot}_ibfk_2");
        } catch (PDOException $e) {
            echo "Skip dropping tag FK: " . $e->getMessage() . "\n";
        }
        // Rename tag column
        $db->exec("ALTER TABLE $pivot CHANGE COLUMN tag_id tag_local_udid VARCHAR(36) NOT NULL");
        // Convert numeric tag references to local_udid
        $sql = "UPDATE $pivot pt
            JOIN tags t ON pt.tag_local_udid REGEXP '^[0-9]+' AND CAST(pt.tag_local_udid AS UNSIGNED) = t.id
            SET pt.tag_local_udid = t.local_udid";
        $affected = $db->exec($sql);
        echo "Updated tag references: $affected\n";
    }

    echo "All done.\n";
} catch (PDOException $e) {
    echo "SQL Error: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
