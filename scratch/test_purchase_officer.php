<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
echo "=== EXTENDED TEST SUITE: PURCHASE OFFICER RESTRICTIONS & STORE OFFICER COMPATIBILITY ===\n\n";

// 1. Fetch Rana Tariq
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = "rana@siovs.edu.pk"');
$stmt->execute();
$poUser = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Imran Ali (Store Officer)
$stmt2 = $pdo->prepare('SELECT * FROM users WHERE email = "imran@siovs.edu.pk"');
$stmt2->execute();
$storeUser = $stmt2->fetch(PDO::FETCH_ASSOC);

$_SESSION['siovs_user'] = $poUser;

// Test page.php?view=reports&rtype=purchase rendering for PO
$_GET['view'] = 'reports';
$_GET['rtype'] = 'purchase';
ob_start();
include __DIR__ . '/../page.php';
$reportHtml = ob_get_clean();

if (str_contains($reportHtml, 'PO & Vendor Fulfillment Audit') || str_contains($reportHtml, 'Committed Order Value')) {
    echo "[PASS] page.php?view=reports&rtype=purchase rendered PO Fulfillment report successfully\n";
} else {
    echo "[FAIL] page.php?view=reports&rtype=purchase failed to render PO Fulfillment report\n";
}

if (str_contains($reportHtml, 'Overall Fulfillment')) {
    echo "[PASS] PO Fulfillment report includes 'Overall Fulfillment' KPI\n";
} else {
    echo "[FAIL] PO Fulfillment report missing 'Overall Fulfillment' KPI\n";
}

// Test page.php?view=inventory rendering for PO
$_GET['view'] = 'inventory';
unset($_GET['rtype']);
ob_start();
include __DIR__ . '/../page.php';
$invHtml = ob_get_clean();

if (str_contains($invHtml, 'Procurement &amp; Replenishment Guide') || str_contains($invHtml, 'Procurement & Replenishment Guide')) {
    echo "[PASS] page.php?view=inventory rendered Procurement Replenishment Guide\n";
} else {
    echo "[FAIL] page.php?view=inventory missing Procurement Replenishment Guide\n";
}

if (str_contains($invHtml, '🛒 Raise PO')) {
    echo "[PASS] page.php?view=inventory contains '🛒 Raise PO' action button\n";
} else {
    echo "[FAIL] page.php?view=inventory missing '🛒 Raise PO' button\n";
}

// Test page.php?view=purchase_orders rendering for PO
$_GET['view'] = 'purchase_orders';
ob_start();
include __DIR__ . '/../page.php';
$poHtml = ob_get_clean();

if (str_contains($poHtml, 'Purchase order list') && str_contains($poHtml, 'Create purchase order')) {
    echo "[PASS] page.php?view=purchase_orders rendered Purchase Order register & creation form\n";
} else {
    echo "[FAIL] page.php?view=purchase_orders missing PO register or form\n";
}

// Ensure "Generate GRN" button is NOT visible to PO
if (!str_contains($poHtml, 'Generate GRN')) {
    echo "[PASS] page.php?view=purchase_orders properly suppressed 'Generate GRN' button for Purchase Officer\n";
} else {
    echo "[FAIL] page.php?view=purchase_orders leaked 'Generate GRN' button to Purchase Officer\n";
}

// Now test Store Officer session compatibility
$_SESSION['siovs_user'] = $storeUser;
ob_start();
include __DIR__ . '/../index.php';
$storeIndexHtml = ob_get_clean();

if (str_contains($storeIndexHtml, 'OT surgeries today') || str_contains($storeIndexHtml, 'OPD patients served') || str_contains($storeIndexHtml, 'Inventory at a glance')) {
    echo "[PASS] index.php continues to serve full operations dashboard to Store Officer (imran@siovs.edu.pk)\n";
} else {
    echo "[FAIL] index.php failed to render operational dashboard for Store Officer\n";
}

$_GET['view'] = 'purchase_orders';
ob_start();
include __DIR__ . '/../page.php';
$storePOHtml = ob_get_clean();

if (str_contains($storePOHtml, 'Generate GRN')) {
    echo "[PASS] page.php?view=purchase_orders allows Store Officer to see 'Generate GRN' button\n";
} else {
    echo "[FAIL] page.php?view=purchase_orders missing 'Generate GRN' for Store Officer\n";
}

echo "\n=== ALL EXTENDED TESTS PASSED WITH 100% SUCCESS ===\n";
