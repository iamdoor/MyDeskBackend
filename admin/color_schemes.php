<?php
/**
 * 管理後台 - 配色表管理
 */
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../lib/db.php';

$db = getDB();
$message = '';
$messageType = '';

// 處理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $message = '請填寫名稱';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare('INSERT INTO color_schemes (name, bg_color, primary_color, secondary_color, accent_color, text_color, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $name,
                $_POST['bg_color'] ?? '#FFFFFF',
                $_POST['primary_color'] ?? '#000000',
                $_POST['secondary_color'] ?? '#666666',
                $_POST['accent_color'] ?? '#007AFF',
                $_POST['text_color'] ?? '#000000',
                (int) ($_POST['is_active'] ?? 1),
                (int) ($_POST['sort_order'] ?? 0),
            ]);
            $message = '配色表建立成功';
            $messageType = 'success';
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE color_schemes SET name=?, bg_color=?, primary_color=?, secondary_color=?, accent_color=?, text_color=?, is_active=?, sort_order=? WHERE id=?');
            $stmt->execute([
                trim($_POST['name'] ?? ''),
                $_POST['bg_color'] ?? '#FFFFFF',
                $_POST['primary_color'] ?? '#000000',
                $_POST['secondary_color'] ?? '#666666',
                $_POST['accent_color'] ?? '#007AFF',
                $_POST['text_color'] ?? '#000000',
                (int) ($_POST['is_active'] ?? 1),
                (int) ($_POST['sort_order'] ?? 0),
                $id,
            ]);
            $message = '配色表更新成功';
            $messageType = 'success';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('DELETE FROM color_schemes WHERE id = ?')->execute([$id]);
            $message = '配色表已刪除';
            $messageType = 'success';
        }
    }
}

$schemes = $db->query('SELECT * FROM color_schemes ORDER BY sort_order ASC, id ASC')->fetchAll();
$editScheme = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM color_schemes WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editScheme = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyDesk Admin - 配色表管理</title>
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
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 24px; margin-bottom: 24px; }
        h2 { font-size: 18px; margin-bottom: 16px; color: #333; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        label { font-size: 13px; color: #666; font-weight: 500; }
        input[type="text"], input[type="number"], input[type="color"], select {
            border: 1px solid #ddd; border-radius: 6px; padding: 8px 10px; font-size: 14px; width: 100%;
        }
        input[type="color"] { padding: 2px 4px; height: 38px; cursor: pointer; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .btn-primary { background: #4a90d9; color: #fff; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-warning { background: #f39c12; color: #fff; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        .color-preview { width: 20px; height: 20px; border-radius: 4px; display: inline-block; border: 1px solid #ddd; vertical-align: middle; }
        .color-group { display: flex; gap: 4px; align-items: center; }
        .actions { display: flex; gap: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MyDesk Admin</h1>
        <a href="login.php?logout=1">登出</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">儀表板</a>
        <a href="members.php">會員管理</a>
        <a href="color_schemes.php" class="active">配色表管理</a>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- 新增 / 編輯表單 -->
        <div class="card">
            <h2><?= $editScheme ? '編輯配色表' : '新增配色表' ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="<?= $editScheme ? 'update' : 'create' ?>">
                <?php if ($editScheme): ?>
                    <input type="hidden" name="id" value="<?= $editScheme['id'] ?>">
                <?php endif; ?>
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>名稱</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($editScheme['name'] ?? '') ?>" required placeholder="如：海洋藍">
                    </div>
                    <div class="form-group">
                        <label>背景色 bg_color</label>
                        <input type="color" name="bg_color" value="<?= htmlspecialchars($editScheme['bg_color'] ?? '#FFFFFF') ?>">
                    </div>
                    <div class="form-group">
                        <label>主色 primary_color</label>
                        <input type="color" name="primary_color" value="<?= htmlspecialchars($editScheme['primary_color'] ?? '#000000') ?>">
                    </div>
                    <div class="form-group">
                        <label>次色 secondary_color</label>
                        <input type="color" name="secondary_color" value="<?= htmlspecialchars($editScheme['secondary_color'] ?? '#666666') ?>">
                    </div>
                    <div class="form-group">
                        <label>強調色 accent_color</label>
                        <input type="color" name="accent_color" value="<?= htmlspecialchars($editScheme['accent_color'] ?? '#007AFF') ?>">
                    </div>
                    <div class="form-group">
                        <label>文字色 text_color</label>
                        <input type="color" name="text_color" value="<?= htmlspecialchars($editScheme['text_color'] ?? '#000000') ?>">
                    </div>
                    <div class="form-group">
                        <label>排序</label>
                        <input type="number" name="sort_order" value="<?= (int) ($editScheme['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>狀態</label>
                        <select name="is_active">
                            <option value="1" <?= ($editScheme['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>啟用</option>
                            <option value="0" <?= ($editScheme['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>停用</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:16px; display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary"><?= $editScheme ? '儲存' : '新增' ?></button>
                    <?php if ($editScheme): ?>
                        <a href="color_schemes.php" class="btn" style="background:#eee; color:#333;">取消</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- 配色表列表 -->
        <div class="card">
            <h2>配色表列表（<?= count($schemes) ?> 個）</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>名稱</th>
                        <th>顏色預覽</th>
                        <th>排序</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schemes as $scheme): ?>
                    <tr>
                        <td><?= $scheme['id'] ?></td>
                        <td><strong><?= htmlspecialchars($scheme['name']) ?></strong></td>
                        <td>
                            <div class="color-group">
                                <span class="color-preview" style="background:<?= htmlspecialchars($scheme['bg_color']) ?>;" title="bg: <?= htmlspecialchars($scheme['bg_color']) ?>"></span>
                                <span class="color-preview" style="background:<?= htmlspecialchars($scheme['primary_color']) ?>;" title="primary: <?= htmlspecialchars($scheme['primary_color']) ?>"></span>
                                <span class="color-preview" style="background:<?= htmlspecialchars($scheme['secondary_color']) ?>;" title="secondary: <?= htmlspecialchars($scheme['secondary_color']) ?>"></span>
                                <span class="color-preview" style="background:<?= htmlspecialchars($scheme['accent_color']) ?>;" title="accent: <?= htmlspecialchars($scheme['accent_color']) ?>"></span>
                                <span class="color-preview" style="background:<?= htmlspecialchars($scheme['text_color']) ?>;" title="text: <?= htmlspecialchars($scheme['text_color']) ?>"></span>
                            </div>
                        </td>
                        <td><?= $scheme['sort_order'] ?></td>
                        <td><?= $scheme['is_active'] ? '<span style="color:#27ae60">啟用</span>' : '<span style="color:#e74c3c">停用</span>' ?></td>
                        <td>
                            <div class="actions">
                                <a href="?edit=<?= $scheme['id'] ?>" class="btn btn-warning btn-sm">編輯</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('確定刪除？')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $scheme['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">刪除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
