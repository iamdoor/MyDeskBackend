<?php
/**
 * 取得系統設定
 * GET /api/system/config.php
 * 選填: key (指定 config_key，不填則回傳全部)
 */
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

requireGet();
requireAuth();

$db = getDB();

if (!empty($_GET['key'])) {
    $stmt = $db->prepare('SELECT config_key, config_value, description FROM system_config WHERE config_key = ?');
    $stmt->execute([$_GET['key']]);
    $config = $stmt->fetch();

    if (!$config) {
        jsonError('設定不存在', 404);
    }

    if ($config['config_value']) {
        $config['config_value'] = json_decode($config['config_value'], true);
    }

    jsonSuccess(['config' => $config]);
} else {
    $stmt = $db->query('SELECT config_key, config_value, description FROM system_config');
    $configs = $stmt->fetchAll();

    foreach ($configs as &$c) {
        if ($c['config_value']) {
            $c['config_value'] = json_decode($c['config_value'], true);
        }
    }

    jsonSuccess(['configs' => $configs]);
}
