<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
$data = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'time' => date('Y-m-d H:i:s'),
    'body' => file_get_contents('php://input'),
    'post' => $_POST,
    'get' => $_GET,
];
$_SESSION['last_request'] = $data;
echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
