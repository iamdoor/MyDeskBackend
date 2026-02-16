<?php
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Dropping legacy foreign key if exists...\n";
    try {
        $db->exec('ALTER TABLE data_sheet_cells DROP FOREIGN KEY data_sheet_cells_ibfk_1');
        echo "Foreign key dropped.\n";
    } catch (PDOException $e) {
        echo "Skip dropping FK: " . $e->getMessage() . "\n";
    }

    echo "Renaming column to data_sheet_local_udid ...\n";
    $db->exec('ALTER TABLE data_sheet_cells CHANGE COLUMN data_sheet_id data_sheet_local_udid VARCHAR(36) NOT NULL');
    echo "Column renamed.\n";

    echo "Updating values to local_udid ...\n";
    $sql = "UPDATE data_sheet_cells dsc
        JOIN data_sheets ds ON dsc.data_sheet_local_udid REGEXP '^[0-9]+' AND CAST(dsc.data_sheet_local_udid AS UNSIGNED) = ds.id
        SET dsc.data_sheet_local_udid = ds.local_udid";
    $count = $db->exec($sql);
    echo "Affected rows: $count\n";

    echo "Done.\n";
} catch (PDOException $e) {
    echo "SQL Error: " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
