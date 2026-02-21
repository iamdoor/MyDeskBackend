<?php
/**
 * 管理後台 - 會員管理
 */
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../lib/db.php';

$db = getDB();

// 處理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $memberId = (int) ($_POST['member_id'] ?? 0);

    if ($memberId > 0) {
        switch ($action) {
            case 'suspend':
                $db->prepare('UPDATE users SET status = "suspended" WHERE id = ?')->execute([$memberId]);
                break;
            case 'activate':
                $db->prepare('UPDATE users SET status = "active" WHERE id = ?')->execute([$memberId]);
                break;
        }
    }

    header('Location: members.php');
    exit;
}

// 搜尋
$search = $_GET['search'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];

if ($search !== '') {
    $where = '(username LIKE ? OR email LIKE ?)';
    $kw = '%' . $search . '%';
    $params = [$kw, $kw];
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT u.id, u.username, u.email, u.status, u.created_at, u.updated_at,
           (SELECT COUNT(*) FROM cells WHERE user_id = u.id AND is_deleted = 0) AS cell_count,
           (SELECT COUNT(*) FROM data_sheets WHERE user_id = u.id AND is_deleted = 0) AS sheet_count,
           (SELECT COUNT(*) FROM devices WHERE user_id = u.id) AS device_count
    FROM users u
    WHERE $where
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$members = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDesk Admin - 會員管理</title>
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
        .search-bar { margin-bottom: 16px; display: flex; gap: 8px; }
        .search-bar input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; flex: 1; max-width: 300px; }
        .search-bar button { padding: 8px 16px; background: #4a90d9; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        table { width: 100%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #fafafa; color: #888; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .badge-active { background: #e8f5e9; color: #2e7d32; }
        .badge-suspended { background: #fbe9e7; color: #c62828; }
        .btn-sm { padding: 4px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-success { background: #27ae60; color: #fff; }
        .pagination { margin-top: 16px; display: flex; gap: 4px; justify-content: center; }
        .pagination a { padding: 8px 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; font-size: 14px; }
        .pagination a.active { background: #4a90d9; color: #fff; border-color: #4a90d9; }
        .info { color: #888; font-size: 13px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MyDesk Admin</h1>
        <a href="login.php?logout=1">登出</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">儀表板</a>
        <a href="members.php" class="active">會員管理</a>
        <a href="color_schemes.php">配色表管理</a>
    </div>
    <div class="container">
        <form class="search-bar" method="GET">
            <input type="text" name="search" placeholder="搜尋帳號或信箱..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">搜尋</button>
        </form>

        <div class="info">共 <?= number_format($total) ?> 位會員</div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>帳號</th>
                    <th>信箱</th>
                    <th>狀態</th>
                    <th>Cell</th>
                    <th>資料單</th>
                    <th>裝置</th>
                    <th>註冊時間</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= $m['id'] ?></td>
                    <td><?= htmlspecialchars($m['username']) ?></td>
                    <td><?= htmlspecialchars($m['email']) ?></td>
                    <td>
                        <span class="badge <?= $m['status'] === 'active' ? 'badge-active' : 'badge-suspended' ?>">
                            <?= $m['status'] === 'active' ? '正常' : '停用' ?>
                        </span>
                    </td>
                    <td><?= number_format($m['cell_count']) ?></td>
                    <td><?= number_format($m['sheet_count']) ?></td>
                    <td><?= number_format($m['device_count']) ?></td>
                    <td><?= $m['created_at'] ?></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                            <?php if ($m['status'] === 'active'): ?>
                                <button type="submit" name="action" value="suspend" class="btn-sm btn-danger" onclick="return confirm('確定要停用此帳號？')">停用</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="activate" class="btn-sm btn-success">啟用</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                <tr><td colspan="9" style="text-align:center;color:#888;padding:24px;">無資料</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
