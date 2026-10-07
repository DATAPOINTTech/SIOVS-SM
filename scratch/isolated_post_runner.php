<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$input = file_get_contents('php://stdin');
if (!$input) {
    exit(0);
}
$data = json_decode($input, true);
if (!$data) {
    exit(0);
}

$_SESSION['siovs_user'] = $data['user'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $data['post'] ?? [];

ob_start();
require __DIR__ . '/../page.php';
ob_end_clean();
