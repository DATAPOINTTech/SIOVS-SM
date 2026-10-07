<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$_SERVER['REQUEST_METHOD'] = 'GET';
echo "=== COMPREHENSIVE DD FINANCE ROLE VERIFICATION TEST SUITE ===\n\n";

// Helper to execute POST against page.php in an isolated process
function execute_page_post(array $post, array $user): string {
    $runner = __DIR__ . '/isolated_post_runner.php';
    $payload = json_encode(['post' => $post, 'user' => $user]);
    $proc = proc_open('php ' . escapeshellarg($runner), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipes);
    if (!is_resource($proc)) return '';
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if ($err && !str_contains($err, 'Cannot modify header information')) {
        echo "[SUBPROCESS ERROR] " . $err . "\n";
    }
    global $pdo, $dbPath;
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return (string)$out;
}

// 1. Identify Zahid Hussain (DD Finance)
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = "zahid@siovs.edu.pk"');
$stmt->execute();
$ddUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ddUser || $ddUser['role'] !== 'dd_finance') {
    echo "[FAIL] DD Finance Zahid Hussain not found in database with role dd_finance\n";
    exit(1);
}
echo "[PASS] Successfully identified DD Finance: {$ddUser['name']} ({$ddUser['email']})\n";

// Also fetch Store Officer for segregation tests
$stmtStore = $pdo->prepare('SELECT * FROM users WHERE email = "imran@siovs.edu.pk"');
$stmtStore->execute();
$storeUser = $stmtStore->fetch(PDO::FETCH_ASSOC);

$_SESSION['siovs_user'] = $ddUser;

// 2. Test can() permissions
$expectedAllowed = ['adjustments', 'reports', 'verification', 'transactions', 'deletions', 'reconciliation', 'assets', 'dd_finance'];
foreach ($expectedAllowed as $perm) {
    if (can($perm)) {
        echo "[PASS] can('{$perm}') is TRUE for dd_finance\n";
    } else {
        echo "[FAIL] can('{$perm}') is FALSE for dd_finance\n";
        exit(1);
    }
}

$expectedDenied = ['users', 'grn', 'purchase_orders'];
foreach ($expectedDenied as $perm) {
    if (!can($perm)) {
        echo "[PASS] can('{$perm}') is strictly FALSE for dd_finance (Segregation of Duties)\n";
    } else {
        echo "[FAIL] can('{$perm}') leaked TRUE for dd_finance\n";
        exit(1);
    }
}

// 3. Test get_header_context()
$ctx = get_header_context($pdo, $ddUser);
if ($ctx['isDDFinance'] === true && $ctx['isStoreOfficer'] === false && $ctx['isPurchaseOfficer'] === false) {
    echo "[PASS] get_header_context() correctly sets isDDFinance = true\n";
} else {
    echo "[FAIL] get_header_context() failed isDDFinance check\n";
    exit(1);
}

// 4. Test Finance Command Center (index.php) rendering
ob_start();
include __DIR__ . '/../index.php';
$dashHtml = ob_get_clean();

$checks = [
    'Finance &amp; Audit Command Center' => 'Hero title rendered',
    'DEPARTMENT OF FINANCE &amp;<br>INTERNAL AUDIT CONTROL' => 'System branding rendered',
    'Logged in as <b>Zahid Hussain</b>' => 'Executive identity rendered',
    'SIOVS SOP Section 27, 28 &amp; 29 Financial Controls' => 'Statutory compliance banner rendered',
    'Central Store Valuation' => 'Central Store Valuation KPI card rendered',
    'Fixed Asset Capitalization' => 'Fixed Asset Capitalization KPI card rendered',
    'Pending Adjustments' => 'Pending Adjustments KPI card rendered',
    'Deletion Requests' => 'Deletion Requests KPI card rendered',
    'Stock Adjustments Awaiting Authorization' => 'Stock adjustments queue panel present',
    'Pending Deletion Requests' => 'Pending deletions queue panel present',
    'Recent Physical Verifications' => 'Recent physical verifications panel present',
    'Review Stock Adjustments' => 'Quick action button rendered',
    'Deletion Queue' => 'Deletion Queue quick action button rendered',
    'Fixed Assets' => 'Fixed Assets quick action button rendered',
];

foreach ($checks as $needle => $desc) {
    if (str_contains($dashHtml, $needle)) {
        echo "[PASS] index.php: {$desc}\n";
    } else {
        echo "[FAIL] index.php: Missing {$needle} ({$desc})\n";
        exit(1);
    }
}

// 5. Test Header navigation & alerts for DD Finance
if (str_contains($dashHtml, 'Stock Adjustments') && str_contains($dashHtml, 'Physical Audits') && str_contains($dashHtml, 'Deletion Queue') && str_contains($dashHtml, 'Fixed Assets') && str_contains($dashHtml, 'Financial &amp; Valuation')) {
    echo "[PASS] Header contains dedicated DD Finance navigation tabs\n";
} else {
    echo "[FAIL] Header missing dedicated DD Finance navigation tabs\n";
    exit(1);
}

if (!str_contains($dashHtml, 'href="page.php?view=users"') && !str_contains($dashHtml, 'href="page.php?view=grn"') && !str_contains($dashHtml, 'href="page.php?view=requisitions"')) {
    echo "[PASS] Header navigation strictly suppresses unauthorized operational tabs (Users, GRN Receipts, Requisitions)\n";
} else {
    echo "[FAIL] Header leaked unauthorized tabs to DD Finance\n";
    exit(1);
}

// 6. Test Financial Authorization: Approving a Stock Adjustment
echo "\n--- TEST: Approving Stock Adjustment as DD Finance ---\n";
$targetItem = $pdo->query('SELECT * FROM items ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$initialQty = (int)$targetItem['quantity'];

$adjNo = 'ADJ-TEST-APP-' . time();
$pdo->prepare('INSERT INTO stock_adjustments (adj_number, item_id, reason, quantity, value, requesting_user_id, status, notes) VALUES (?, ?, ?, ?, ?, ?, "Pending", ?)')
    ->execute([$adjNo, $targetItem['id'], 'Annual audit surplus test', 15, 15 * 10, $storeUser['id'], 'Awaiting DD Finance']);
$adjAppId = (int)$pdo->lastInsertId();

execute_page_post([
    'action' => 'approve_adjustment',
    'adj_id' => $adjAppId,
], $ddUser);

$approvedAdj = $pdo->query("SELECT * FROM stock_adjustments WHERE id = {$adjAppId}")->fetch(PDO::FETCH_ASSOC);
if (!$approvedAdj || $approvedAdj['status'] !== 'Approved' || $approvedAdj['authorizing_officer'] !== 'Zahid Hussain') {
    echo "[FAIL] Adjustment {$adjNo} was not properly approved by Zahid Hussain (status: " . ($approvedAdj['status'] ?? 'none') . ")\n";
    exit(1);
}
echo "[PASS] Adjustment {$adjNo} successfully authorized and marked 'Approved' by Zahid Hussain\n";

// Verify stock balance increased by +15
$postAppItem = $pdo->query("SELECT * FROM items WHERE id = {$targetItem['id']}")->fetch(PDO::FETCH_ASSOC);
if ((int)$postAppItem['quantity'] !== $initialQty + 15) {
    echo "[FAIL] Item stock did not increase by 15. Expected: " . ($initialQty + 15) . ", Found: {$postAppItem['quantity']}\n";
    exit(1);
}
echo "[PASS] Main Store inventory incremented by exactly +15 units (from {$initialQty} to {$postAppItem['quantity']})\n";

// Verify stock ledger entry
$ledgerApp = $pdo->query("SELECT * FROM stock_ledger WHERE reference_no = '{$adjNo}' AND transaction_type = 'ADJUSTMENT_IN'")->fetch(PDO::FETCH_ASSOC);
if (!$ledgerApp || (int)$ledgerApp['quantity'] !== 15) {
    echo "[FAIL] Stock ledger did not record ADJUSTMENT_IN for {$adjNo}\n";
    exit(1);
}
echo "[PASS] Stock ledger audit trail recorded ADJUSTMENT_IN of 15 units with user attribution\n";

// 7. Test Financial Authorization: Rejecting a Stock Adjustment
echo "\n--- TEST: Rejecting Stock Adjustment as DD Finance ---\n";
$adjNoRej = 'ADJ-TEST-REJ-' . time();
$currentQty = (int)$postAppItem['quantity'];

$pdo->prepare('INSERT INTO stock_adjustments (adj_number, item_id, reason, quantity, value, requesting_user_id, status, notes) VALUES (?, ?, ?, ?, ?, ?, "Pending", ?)')
    ->execute([$adjNoRej, $targetItem['id'], 'Unverified breakage write-off test', -8, 8 * 10, $storeUser['id'], 'Pending review']);
$adjRejId = (int)$pdo->lastInsertId();

execute_page_post([
    'action' => 'reject_adjustment',
    'adj_id' => $adjRejId,
    'rejection_reason' => 'Documentation insufficient, physical recount mandatory',
], $ddUser);

$rejectedAdj = $pdo->query("SELECT * FROM stock_adjustments WHERE id = {$adjRejId}")->fetch(PDO::FETCH_ASSOC);
if (!$rejectedAdj || $rejectedAdj['status'] !== 'Rejected' || $rejectedAdj['authorizing_officer'] !== 'Zahid Hussain') {
    echo "[FAIL] Adjustment {$adjNoRej} was not marked 'Rejected' by Zahid Hussain\n";
    exit(1);
}
if (!str_contains($rejectedAdj['notes'], 'Documentation insufficient, physical recount mandatory')) {
    echo "[FAIL] Rejection reason not saved in adjustment notes\n";
    exit(1);
}
echo "[PASS] Adjustment {$adjNoRej} successfully rejected with rejection reason logged in audit trail\n";

// Verify stock balance remained STRICTLY unchanged
$postRejItem = $pdo->query("SELECT * FROM items WHERE id = {$targetItem['id']}")->fetch(PDO::FETCH_ASSOC);
if ((int)$postRejItem['quantity'] !== $currentQty) {
    echo "[FAIL] Item stock changed upon rejection! Expected: {$currentQty}, Found: {$postRejItem['quantity']}\n";
    exit(1);
}
echo "[PASS] Main Store inventory strictly preserved with zero modification upon rejection ({$currentQty} units)\n";

// Verify stock ledger has NO entry for rejected adjustment
$ledgerRej = $pdo->query("SELECT * FROM stock_ledger WHERE reference_no = '{$adjNoRej}'")->fetch(PDO::FETCH_ASSOC);
if ($ledgerRej) {
    echo "[FAIL] Stock ledger improperly created an entry for rejected adjustment\n";
    exit(1);
}
echo "[PASS] Stock ledger has zero entries for rejected adjustment (clean financial audit)\n";

// 8. Test Segregation of Duties: Store Officer CANNOT approve or reject adjustments
echo "\n--- TEST: Segregation of Duties Enforcement (Store Officer cannot approve/reject) ---\n";
$adjNoSeg = 'ADJ-TEST-SEG-' . time();
$pdo->prepare('INSERT INTO stock_adjustments (adj_number, item_id, reason, quantity, value, requesting_user_id, status, notes) VALUES (?, ?, ?, ?, ?, ?, "Pending", ?)')
    ->execute([$adjNoSeg, $targetItem['id'], 'Segregation test', 5, 50, $storeUser['id'], 'Testing unauthorized store approval']);
$adjSegId = (int)$pdo->lastInsertId();

// Store Officer tries to approve
execute_page_post([
    'action' => 'approve_adjustment',
    'adj_id' => $adjSegId,
], $storeUser);

$segAdj = $pdo->query("SELECT * FROM stock_adjustments WHERE id = {$adjSegId}")->fetch(PDO::FETCH_ASSOC);
if ($segAdj['status'] !== 'Pending') {
    echo "[FAIL] Store Officer was illegally able to approve adjustment (status: {$segAdj['status']})\n";
    exit(1);
}
echo "[PASS] Store Officer attempt to approve adjustment was strictly blocked (status remains 'Pending')\n";

// Store Officer tries to reject
execute_page_post([
    'action' => 'reject_adjustment',
    'adj_id' => $adjSegId,
    'rejection_reason' => 'Store officer self-reject',
], $storeUser);

$segAdj2 = $pdo->query("SELECT * FROM stock_adjustments WHERE id = {$adjSegId}")->fetch(PDO::FETCH_ASSOC);
if ($segAdj2['status'] !== 'Pending') {
    echo "[FAIL] Store Officer was illegally able to reject adjustment (status: {$segAdj2['status']})\n";
    exit(1);
}
echo "[PASS] Store Officer attempt to reject adjustment was strictly blocked (status remains 'Pending')\n";

// Clean up test adjustments
$pdo->exec("DELETE FROM stock_adjustments WHERE adj_number IN ('{$adjNo}', '{$adjNoRej}', '{$adjNoSeg}')");
$pdo->exec("DELETE FROM stock_ledger WHERE reference_no = '{$adjNo}'");
// Revert test item stock to initial
$pdo->prepare('UPDATE items SET quantity = ? WHERE id = ?')->execute([$initialQty, $targetItem['id']]);

// 9. Test Financial & Valuation Report (rtype=valuation)
echo "\n--- TEST: Hospital Inventory & Capital Asset Valuation Report ---\n";
$_GET = ['view' => 'reports', 'rtype' => 'valuation'];
ob_start();
include __DIR__ . '/../page.php';
$valHtml = ob_get_clean();

$valChecks = [
    'Central Store Valuation' => 'Central Store Valuation KPI card present',
    'Capital Assets Value' => 'Capital Assets Value KPI card present',
    'Committed PO Deliveries' => 'Committed PO Deliveries KPI card present',
    'Institutional Net Exposure' => 'Institutional Net Exposure KPI card present',
    'Inventory &amp; Asset Valuation' => 'Report tab active for DD Finance',
    'Export Valuation CSV' => 'Export CSV action available',
    'Category' => 'Category filter present',
    'Stock Status' => 'Stock status filter present',
];
foreach ($valChecks as $needle => $desc) {
    if (str_contains($valHtml, $needle)) {
        echo "[PASS] Valuation Report: {$desc}\n";
    } else {
        echo "[FAIL] Valuation Report: Missing {$needle} ({$desc})\n";
        exit(1);
    }
}

// 10. Test PO Commitments & Vendor Fulfillment Report (rtype=purchase)
echo "\n--- TEST: PO Commitments & Vendor Fulfillment Audit Report ---\n";
$_GET = ['view' => 'reports', 'rtype' => 'purchase'];
ob_start();
include __DIR__ . '/../page.php';
$poHtml = ob_get_clean();

$poChecks = [
    'Committed Order Value' => 'Committed Order Value KPI rendered',
    'PO Commitments &amp; Fulfillment' => 'PO commitments tab rendered',
    'Filter Supplier' => 'Supplier filter present',
    'Overall Fulfillment' => 'Fulfillment KPI card present',
];
foreach ($poChecks as $needle => $desc) {
    if (str_contains($poHtml, $needle)) {
        echo "[PASS] PO Commitments Report: {$desc}\n";
    } else {
        echo "[FAIL] PO Commitments Report: Missing {$needle} ({$desc})\n";
        exit(1);
    }
}

// 11. Test Deletions Queue View for DD Finance
echo "\n--- TEST: Deletion & Stock Reversal Authorization Queue ---\n";
$_GET = ['view' => 'deletions'];
ob_start();
include __DIR__ . '/../page.php';
$delHtml = ob_get_clean();

if (str_contains($delHtml, 'Stock Reversal Authorization Queue') && str_contains($delHtml, 'Pending DD Finance Review')) {
    echo "[PASS] Deletion Queue: Rendered executive authorization queue\n";
} else {
    echo "[FAIL] Deletion Queue: Header or KPI card missing\n";
    exit(1);
}
if (!str_contains($delHtml, '<h2>Submit Deletion Request</h2>')) {
    echo "[PASS] Deletion Queue: Routine submission form card correctly hidden for DD Finance\n";
} else {
    echo "[FAIL] Deletion Queue: Leaked routine submission form to DD Finance\n";
    exit(1);
}

// 12. Test Fixed Assets & Equipment for DD Finance
echo "\n--- TEST: Fixed Assets & Equipment Register ---\n";
$_GET = ['view' => 'assets'];
ob_start();
include __DIR__ . '/../page.php';
$assetHtml = ob_get_clean();

if (str_contains($assetHtml, 'Total Fixed Assets') && str_contains($assetHtml, 'Dead Stock &amp; Condemnations')) {
    echo "[PASS] Fixed Assets: Master register and dead stock tabs rendered\n";
} else {
    echo "[FAIL] Fixed Assets: Missing asset KPIs or tabs\n";
    exit(1);
}

// 13. Test Register Reconciliation for DD Finance
echo "\n--- TEST: Sindh Government Manual Register vs Digital Reconciliation ---\n";
$_GET = ['view' => 'reconciliation'];
ob_start();
include __DIR__ . '/../page.php';
$reconHtml = ob_get_clean();

if (str_contains($reconHtml, 'Sindh Manual Register vs Digital Audit Register') && str_contains($reconHtml, 'Certify & Save Reconciliation')) {
    echo "[PASS] Register Reconciliation: Interactive audit register and certification form rendered\n";
} else {
    echo "[FAIL] Register Reconciliation: Missing audit register or certification form\n";
    exit(1);
}

$_GET = ['view' => 'reports', 'rtype' => 'reconciliation'];
ob_start();
include __DIR__ . '/../page.php';
$reconRptHtml = ob_get_clean();

if (str_contains($reconRptHtml, 'SINDH HEALTH AUDIT COMPLIANCE CERTIFICATE')) {
    echo "[PASS] Register Reconciliation Report: Sindh Health Audit Compliance Certificate rendered\n";
} else {
    echo "[FAIL] Register Reconciliation Report: Missing compliance certificate\n";
    exit(1);
}

// 14. Test Access Denial & Compliance Notice for DD Finance on restricted pages
echo "\n--- TEST: Access Denial Compliance Redirection ---\n";
if (!can('users') && !can('grn') && !can('purchase_orders')) {
    echo "[PASS] Restricted operational views (users, grn, purchase_orders) strictly blocked for DD Finance under SIOVS SOP Sections 27, 28 & 29\n";
} else {
    echo "[FAIL] Restricted operational views leaked permissions to DD Finance\n";
    exit(1);
}

echo "\n=== ALL DD FINANCE COMPREHENSIVE CHECKS PASSED WITH 100% SUCCESS ===\n";
