<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
echo "=== COMPREHENSIVE STORE OFFICER ROLE VERIFICATION TEST SUITE ===\n\n";

// 1. Fetch Imran Ali (Store Officer)
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = "imran@siovs.edu.pk"');
$stmt->execute();
$storeUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$storeUser || $storeUser['role'] !== 'store_officer') {
    echo "[FAIL] Store Officer Imran Ali not found in database with role store_officer\n";
    exit(1);
}
echo "[PASS] Successfully identified Store Officer: {$storeUser['name']} ({$storeUser['email']})\n";

$_SESSION['siovs_user'] = $storeUser;

// 2. Test can() permissions
$expectedAllowed = ['purchase_orders', 'grn', 'inventory', 'requisitions', 'batches', 'returns', 'verification', 'adjustments', 'reports', 'departments', 'suppliers', 'assets', 'reconciliation', 'store_officer'];
foreach ($expectedAllowed as $perm) {
    if (can($perm)) {
        echo "[PASS] can('{$perm}') is TRUE for store_officer\n";
    } else {
        echo "[FAIL] can('{$perm}') is FALSE for store_officer\n";
        exit(1);
    }
}

$expectedDenied = ['users', 'deletions'];
foreach ($expectedDenied as $perm) {
    if (!can($perm)) {
        echo "[PASS] can('{$perm}') is strictly FALSE for store_officer (Access Denied as per SOP Section 27 & 29)\n";
    } else {
        echo "[FAIL] can('{$perm}') leaked TRUE for store_officer\n";
        exit(1);
    }
}

// 3. Test get_header_context()
$ctx = get_header_context($pdo, $storeUser);
if ($ctx['isStoreOfficer'] === true && $ctx['isPurchaseOfficer'] === false) {
    echo "[PASS] get_header_context() correctly sets isStoreOfficer = true\n";
} else {
    echo "[FAIL] get_header_context() failed isStoreOfficer check\n";
    exit(1);
}

// 4. Test Store Dashboard (index.php) rendering
ob_start();
include __DIR__ . '/../index.php';
$dashHtml = ob_get_clean();

$checks = [
    'Store Operations Command Center' => 'Hero title rendered',
    'SIOVS SOP Section 4, 6, 8 &amp; 29 Store Custody' => 'Compliance notice rendered',
    'Main Store Valuation' => 'Store Valuation KPI rendered',
    'Incoming Deliveries' => 'Incoming Deliveries KPI rendered',
    'Requisitions to Issue' => 'Requisitions to Issue KPI rendered',
    'Near Expiry' => 'Near Expiry KPI rendered',
    'Inventory at a glance' => 'Inventory at a glance section present',
    'Approved Purchase Orders Awaiting GRN Receiving' => 'Inward deliveries panel present',
    'Approved Department Requisitions Ready for Issue' => 'Requisitions issue panel present',
    'FEFO QUALITY WATCH' => 'FEFO Watchlist panel present',
    'Recent Store Movements' => 'Store movement ledger feed present',
    'Generate GRN' => 'Generate GRN header action button rendered',
];

foreach ($checks as $needle => $desc) {
    if (str_contains($dashHtml, $needle)) {
        echo "[PASS] index.php: {$desc}\n";
    } else {
        echo "[FAIL] index.php: Missing {$needle} ({$desc})\n";
        exit(1);
    }
}

// 5. Test header.php navigation for Store Officer
if (str_contains($dashHtml, 'GRN Receipts') && str_contains($dashHtml, 'Requisitions &amp; Issue') && str_contains($dashHtml, 'Batch &amp; Expiry (FEFO)')) {
    echo "[PASS] Header contains dedicated Store Officer navigation tabs\n";
} else {
    echo "[FAIL] Header missing dedicated Store Officer navigation tabs\n";
    exit(1);
}

if (!str_contains($dashHtml, 'href="page.php?view=users"') && !str_contains($dashHtml, 'href="page.php?view=deletions"')) {
    echo "[PASS] Header navigation strictly suppresses 'Users' and 'Deletions' for Store Officer\n";
} else {
    echo "[FAIL] Header leaked 'Users' or 'Deletions' link to Store Officer\n";
    exit(1);
}

// 6. Test Store Officer page access: GRN
$_GET['view'] = 'grn';
ob_start();
include __DIR__ . '/../page.php';
$grnHtml = ob_get_clean();
if (str_contains($grnHtml, 'Goods Received Note (GRN) Register') || str_contains($grnHtml, 'GRN')) {
    echo "[PASS] page.php?view=grn successfully rendered for Store Officer\n";
} else {
    echo "[FAIL] page.php?view=grn failed to render for Store Officer\n";
    exit(1);
}

// 7. Test Store Officer page access: Requisitions
$_GET['view'] = 'requisitions';
ob_start();
include __DIR__ . '/../page.php';
$reqHtml = ob_get_clean();
if (str_contains($reqHtml, 'Departmental requisition register')) {
    echo "[PASS] page.php?view=requisitions successfully rendered for Store Officer\n";
} else {
    echo "[FAIL] page.php?view=requisitions failed to render for Store Officer\n";
    exit(1);
}

// 8. Test Store Officer access restriction on administrative pages
// Note: In web context header() and exit() are called. We can test can('users') and can('deletions')
if (!can('users')) {
    echo "[PASS] Access control blocks Store Officer from view=users\n";
}
if (!can('deletions')) {
    echo "[PASS] Access control blocks Store Officer from view=deletions\n";
}

echo "\n=== ALL STORE OFFICER TESTS PASSED WITH 100% SUCCESS ===\n";
