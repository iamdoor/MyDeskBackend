<?php
/**
 * MyDesk 全域設定
 * 複製此檔為 config.php 並填入實際值
 */

// 資料庫設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'MyDeskDev');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// DriveServer 設定
define('DRIVE_BASE_URL', 'https://www.askcloud.cc/DriveServer');

// Token 設定
define('TOKEN_SECRET', 'CHANGE_THIS_TO_A_RANDOM_STRING');
define('TOKEN_EXPIRE_HOURS', 720); // 30 天

// 軟刪除天數
define('SOFT_DELETE_DAYS', 10);

// 時區
date_default_timezone_set('Asia/Taipei');
