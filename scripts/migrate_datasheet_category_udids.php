<?php
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $steps = [
        'category' => "UPDATE data_sheets ds
            JOIN categories c ON ds.category_id REGEXP '^[0-9]+' AND CAST(ds.category_id AS UNSIGNED) = c.id
            SET ds.category_id = c.local_udid",
        'sub_category' => "UPDATE data_sheets ds
            JOIN sub_categories sc ON ds.sub_category_id REGEXP '^[0-9]+' AND CAST(ds.sub_category_id AS UNSIGNED) = sc.id
            SET ds.sub_category_id = sc.local_udid",
        'backfill' => "UPDATE data_sheets ds
            JOIN sub_categories sc ON ds.sub_category_id = sc.local_udid
            JOIN categories c ON (
                (sc.category_id REGEXP '^[0-9]+' AND CAST(sc.category_id AS UNSIGNED) = c.id)
                OR sc.category_id = c.local_udid
            )
            SET ds.category_id = c.local_udid
            WHERE ds.category_id IS NULL OR ds.category_id = ''"
    };

    foreach ($steps as $label => $sql) {
        echo "Running step: $label ...\n";
        $count = $db->exec($sql);
        echo "Affected rows ($label): $count\n";
        @ob_flush();
        @flush();
    }

    echo "Completed successfully.\n";
} catch (PDOException $e) {
    echo "SQL Error: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
