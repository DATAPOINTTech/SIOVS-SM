<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config/bootstrap.php';

// Require authenticated session
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised. Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are supported.']);
    exit;
}

$payload  = json_decode((string) file_get_contents('php://input'), true) ?? [];
$required = ['type', 'reference', 'party', 'quantity', 'status'];
foreach ($required as $field) {
    if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
        http_response_code(422);
        echo json_encode(['error' => "The {$field} field is required."]);
        exit;
    }
}

$quantity = filter_var($payload['quantity'], FILTER_VALIDATE_INT);
if ($quantity === false || $quantity < 1) {
    http_response_code(422);
    echo json_encode(['error' => 'Quantity must be a whole number greater than zero.']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO transactions (type, reference, party, quantity, status) VALUES (:type, :reference, :party, :quantity, :status)'
);
$stmt->execute([
    ':type'      => trim((string) $payload['type']),
    ':reference' => trim((string) $payload['reference']),
    ':party'     => trim((string) $payload['party']),
    ':quantity'  => $quantity,
    ':status'    => trim((string) $payload['status']),
]);

echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
