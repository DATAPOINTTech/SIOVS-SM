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

register_shutdown_function(function () {
    echo json_encode(['flash' => $_SESSION['flash'] ?? null]);
});

$_SESSION['siovs_user'] = $data['user'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $data['post'] ?? [];

if (isset($data['get'])) {
    $_GET = $data['get'];
} else {
    $act = $_POST['action'] ?? '';
    if (str_contains($act, 'requisition')) {
        $_GET['view'] = 'requisitions';
    } elseif (str_contains($act, 'return')) {
        $_GET['view'] = 'returns';
    } elseif (str_contains($act, 'deletion')) {
        $_GET['view'] = 'deletions';
    } else {
        $_GET['view'] = 'adjustments';
    }
}

ob_start();
require __DIR__ . '/../page.php';
ob_end_clean();
