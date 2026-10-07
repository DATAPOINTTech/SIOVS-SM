<?php
require_once __DIR__ . '/../config/bootstrap.php';
global $pdo;

echo "--- STOCK ADJUSTMENTS ---\n";
$stmt = $pdo->query('SELECT id, adj_number, verification_id, item_id, quantity, notes, status FROM stock_adjustments ORDER BY id DESC LIMIT 10');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "ID: {$r['id']} | Num: {$r['adj_number']} | VerID: " . ($r['verification_id'] ?? 'NULL') . " | Qty: {$r['quantity']} | Status: {$r['status']} | Notes: {$r['notes']}\n";
}

echo "--- VERIFICATIONS TABLE COLS ---\n";
print_r($pdo->query('PRAGMA table_info(stock_verifications)')->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- VERIFICATIONS ROWS ---\n";
print_r($pdo->query('SELECT * FROM stock_verifications')->fetchAll(PDO::FETCH_ASSOC));

// Backfill verification_id from notes if null
$pdo->exec("UPDATE stock_adjustments SET verification_id = (SELECT id FROM stock_verifications WHERE stock_adjustments.notes LIKE '%' || stock_verifications.ver_number || '%' LIMIT 1) WHERE (verification_id IS NULL OR verification_id = 0) AND notes LIKE '%Verification%'");

echo "--- UPDATED ADJUSTMENTS ROWS ---\n";
print_r($pdo->query('SELECT id, adj_number, verification_id, item_id, quantity, reason, notes, status FROM stock_adjustments')->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- VERIFICATION AUDIT STATS IN PAGE.PHP ---\n";
$stmt = $pdo->query('
    SELECT v.id, v.ver_number, v.status,
           (SELECT COUNT(*) FROM stock_adjustments WHERE (verification_id = v.id OR notes LIKE "%" || v.ver_number || "%") AND status = "Approved") AS approved_adj_count,
           (SELECT COUNT(*) FROM stock_adjustments WHERE (verification_id = v.id OR notes LIKE "%" || v.ver_number || "%") AND status = "Pending") AS pending_adj_count
    FROM stock_verifications v
');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
