<?php
/**
 * 管理後台 - 儀表板
 */
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../lib/db.php';

$db = getDB();

// 統計數據
$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$cellCount = (int) $db->query('SELECT COUNT(*) FROM cells WHERE is_deleted = 0')->fetchColumn();
$sheetCount = (int) $db->query('SELECT COUNT(*) FROM data_sheets WHERE is_deleted = 0')->fetchColumn();
$desktopCount = (int) $db->query('SELECT COUNT(*) FROM desktops WHERE is_deleted = 0')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDesk Admin - Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; }
        .header { background: #4a90d9; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 20px; }
        .header a { color: #fff; text-decoration: none; }
        .nav { background: #fff; border-bottom: 1px solid #ddd; padding: 0 24px; }
        .nav a { display: inline-block; padding: 12px 16px; text-decoration: none; color: #555; font-size: 14px; }
        .nav a:hover, .nav a.active { color: #4a90d9; border-bottom: 2px solid #4a90d9; }
        .container { max-width: 1200px; margin: 24px auto; padding: 0 24px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-card h3 { color: #888; font-size: 14px; margin-bottom: 8px; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #333; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MyDesk Admin</h1>
        <a href="login.php?logout=1">登出</a>
    </div>
    <div class="nav">
        <a href="dashboard.php" class="active">儀表板</a>
        <a href="members.php">會員管理</a>
    </div>
    <div class="container">
        <div class="stats">
            <div class="stat-card">
                <h3>使用者數</h3>
                <div class="number"><?= number_format($userCount) ?></div>
            </div>
            <div class="stat-card">
                <h3>Cell 數</h3>
                <div class="number"><?= number_format($cellCount) ?></div>
            </div>
            <div class="stat-card">
                <h3>資料單數</h3>
                <div class="number"><?= number_format($sheetCount) ?></div>
            </div>
            <div class="stat-card">
                <h3>桌面數</h3>
                <div class="number"><?= number_format($desktopCount) ?></div>
            </div>
        </div>
    </div>
</body>
</html>
