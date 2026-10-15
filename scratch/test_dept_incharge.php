<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

echo "======================================================================\n";
echo " TEST SUITE: DEPARTMENT INCHARGE ROLE (WHAT SHE HAS & DOES NOT HAVE)  \n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function check(bool $condition, string $msg): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $msg . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $msg . "\n";
        $failCount++;
    }
}

// 1. Fetch Dr. Sara Ahmed
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = "sara@siovs.edu.pk"');
$stmt->execute();
$sara = $stmt->fetch(PDO::FETCH_ASSOC);

check(!empty($sara) && $sara['role'] === 'dept_incharge', 'Found Dr. Sara Ahmed with role dept_incharge');

// Simulate logged-in session
$_SESSION['siovs_user'] = $sara;
$_SERVER['REQUEST_METHOD'] = 'GET';

// 2. Test WHAT SHE SHOULD HAVE (Permissions)
echo "\n--- 2. PERMISSIONS AUDIT: WHAT SHE SHOULD HAVE ---\n";
check(can('requisitions'), "Dept Incharge has 'requisitions' permission (Indent from Central Store)");
check(can('returns'), "Dept Incharge has 'returns' permission (Return unused/damaged stock to Store)");
check(can('emergency'), "Dept Incharge has 'emergency' permission (Point-of-care emergency dispensing)");
check(can('opd_consumption'), "Dept Incharge has 'opd_consumption' permission (OPD clinical dispensing)");
check(can('ipd_consumption'), "Dept Incharge has 'ipd_consumption' permission (IPD ward dispensing)");
check(can('ot_consumption'), "Dept Incharge has 'ot_consumption' permission (OT surgical dispensing)");
check(can('reports'), "Dept Incharge has 'reports' permission (Departmental consumption reporting)");

// 3. Test WHAT SHE MUST NOT HAVE (Strict Segregation of Duties)
echo "\n--- 3. PERMISSIONS AUDIT: WHAT SHE MUST NOT HAVE ---\n";
check(!can('purchase_orders'), "Dept Incharge BLOCKED from 'purchase_orders' (Purchase Officer exclusive)");
check(!can('grn'), "Dept Incharge BLOCKED from 'grn' (Store Officer physical receipt exclusive)");
check(!can('adjustments'), "Dept Incharge BLOCKED from 'adjustments' (DD Finance authorization exclusive)");
check(!can('verification'), "Dept Incharge BLOCKED from 'verification' (Annual audit board exclusive)");
check(!can('deletions'), "Dept Incharge BLOCKED from 'deletions' (Audit & Finance exclusive)");
check(!can('reconciliation'), "Dept Incharge BLOCKED from 'reconciliation' (Central Store ledger exclusive)");
check(!can('users'), "Dept Incharge BLOCKED from 'users' (Admin exclusive)");
check(!can('inventory'), "Dept Incharge BLOCKED from 'inventory' (Central Store Item Master exclusive)");
check(!can('assets'), "Dept Incharge BLOCKED from 'assets' (Store & Finance exclusive)");

// 4. Test Header Context & Badge Counters
echo "\n--- 4. HEADER CONTEXT & BADGE COUNTERS ---\n";
$ctx = get_header_context($pdo, $sara);
check($ctx['isDeptIncharge'] === true, "Header context identifies isDeptIncharge = true");
check($ctx['isPurchaseOfficer'] === false, "Header context isPurchaseOfficer = false");
check($ctx['isStoreOfficer'] === false, "Header context isStoreOfficer = false");
check($ctx['isDDFinance'] === false, "Header context isDDFinance = false");
check(isset($ctx['deptId']) && isset($ctx['deptName']), "Header context resolves user department: " . ($ctx['deptName'] ?? ''));
check(isset($ctx['subStoreItems']) && isset($ctx['subStoreUnits']), "Header context computes floor stock totals (Items: {$ctx['subStoreItems']}, Units: {$ctx['subStoreUnits']})");

// 5. Test Dashboard Rendering (index.php)
echo "\n--- 5. DASHBOARD RENDERING (index.php) ---\n";
ob_start();
include __DIR__ . '/../index.php';
$dashHtml = ob_get_clean();

check(str_contains($dashHtml, 'Clinical Operations &amp; Sub-Store Command Center') || str_contains($dashHtml, 'Clinical Operations & Sub-Store Command Center'), "Dashboard displays Clinical Operations & Sub-Store Command Center");
check(str_contains($dashHtml, 'Emergency Department') || str_contains($dashHtml, 'Dr. Sara Ahmed'), "Dashboard identifies Emergency Department & Dr. Sara Ahmed");
check(str_contains($dashHtml, 'Sub-Store Items') && str_contains($dashHtml, 'Floor Stock Balance'), "Dashboard displays Sub-Store Items & Floor Stock KPIs");
check(str_contains($dashHtml, 'Floor Stock Register'), "Dashboard renders Floor Stock Register table");
check(str_contains($dashHtml, 'Department Indents'), "Dashboard renders Department Indents table");
check(str_contains($dashHtml, 'Recent Point-of-Care Patient Dispensing'), "Dashboard renders Point-of-Care Patient Dispensing panel");
check(str_contains($dashHtml, 'Indent from Central Store'), "Dashboard provides 'Indent from Central Store' quick action");

// Ensure no leakage of other role command centers
check(!str_contains($dashHtml, 'Procurement Command Center'), "Dashboard does NOT leak Procurement Command Center");
check(!str_contains($dashHtml, 'Store Operations Command Center'), "Dashboard does NOT leak Store Operations Command Center");
check(!str_contains($dashHtml, 'Finance Command Center'), "Dashboard does NOT leak Finance Command Center");

// 6. Test Header Navigation Elements
echo "\n--- 6. HEADER NAVIGATION INTEGRITY ---\n";
check(str_contains($dashHtml, 'Clinical Dashboard'), "Nav contains 'Clinical Dashboard'");
check(str_contains($dashHtml, 'Department Requisitions'), "Nav contains 'Department Requisitions'");
check(str_contains($dashHtml, 'Sub-Store Stock &amp; Returns') || str_contains($dashHtml, 'Sub-Store Stock & Returns'), "Nav contains 'Sub-Store Stock & Returns'");
check(str_contains($dashHtml, 'Emergency'), "Nav contains 'Emergency'");
check(str_contains($dashHtml, 'OPD Dispensing'), "Nav contains 'OPD Dispensing'");
check(str_contains($dashHtml, 'IPD Ward Dispensing'), "Nav contains 'IPD Ward Dispensing'");
check(str_contains($dashHtml, 'OT Dispensing'), "Nav contains 'OT Dispensing'");
check(str_contains($dashHtml, 'Consumption Reports'), "Nav contains 'Consumption Reports'");

// Ensure no prohibited nav items leaked
check(!str_contains($dashHtml, 'Purchase Orders'), "Nav suppresses 'Purchase Orders'");
check(!str_contains($dashHtml, 'GRN Receipts'), "Nav suppresses 'GRN Receipts'");
check(!str_contains($dashHtml, 'Deletion Queue'), "Nav suppresses 'Deletion Queue'");
check(!str_contains($dashHtml, 'Register Reconciliation'), "Nav suppresses 'Register Reconciliation'");

// 7. Test Access Protection in page.php (Restricted vs Allowed)
echo "\n--- 7. ACCESS PROTECTION IN page.php ---\n";

// Test allowed view: requisitions
$_GET['view'] = 'requisitions';
ob_start();
include __DIR__ . '/../page.php';
$reqHtml = ob_get_clean();
check(str_contains($reqHtml, 'Departmental requisition register') && str_contains($reqHtml, 'New departmental requisition'), "page.php?view=requisitions accessible and renders indent register & form");

// Test allowed view: returns
$_GET['view'] = 'returns';
ob_start();
include __DIR__ . '/../page.php';
$retHtml = ob_get_clean();
check(str_contains($retHtml, 'Active Sub-Store Stock') || str_contains($retHtml, 'Returns Recorded'), "page.php?view=returns accessible and renders sub-store stock & return voucher form");

// Test allowed view: emergency
$_GET['view'] = 'emergency';
ob_start();
include __DIR__ . '/../page.php';
$emgHtml = ob_get_clean();
check(str_contains($emgHtml, 'Emergency') || str_contains($emgHtml, 'Trauma'), "page.php?view=emergency accessible and renders emergency dispensing module");

// Test allowed view: consumption reports
$_GET['view'] = 'reports';
$_GET['rtype'] = 'consumption';
ob_start();
include __DIR__ . '/../page.php';
$repHtml = ob_get_clean();
check(str_contains($repHtml, 'Total Dispensing Records') || str_contains($repHtml, 'Emergency Trauma Dispenses'), "page.php?view=reports&rtype=consumption accessible and renders clinical audit");

// 8. Test Backend Segregation of Duties: self-issue prevention
echo "\n--- 8. BACKEND ENFORCEMENT: PREVENT REQUISITION SELF-ISSUE ---\n";

function run_post_subprocess(array $post, array $user, array $get = ['view' => 'requisitions']): ?array {
    $runner = __DIR__ . '/isolated_post_runner.php';
    $payload = json_encode(['post' => $post, 'user' => $user, 'get' => $get]);
    $proc = proc_open('php ' . escapeshellarg($runner), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipes);
    if (!is_resource($proc)) return null;
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if ($err) echo "Subprocess STDERR: $err\n";
    $res = json_decode((string)$out, true);
    if ($res === null) echo "Subprocess STDOUT was: $out\n";
    return $res['flash'] ?? null;
}

// Attempt to post issue_requisition as Dr. Sara Ahmed
$flashMsg = run_post_subprocess(['action' => 'issue_requisition', 'req_id' => 1], $sara);
check(
    !empty($flashMsg) && ($flashMsg['type'] === 'error') && str_contains($flashMsg['message'], 'Unauthorized'),
    "Backend strictly blocked Dr. Sara Ahmed from executing issue_requisition: " . ($flashMsg['message'] ?? 'None')
);

// Verify Store Officer can still issue requisitions
$imran = $pdo->query('SELECT * FROM users WHERE role="store_officer" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$_SESSION['siovs_user'] = $imran;
check(can('store_officer'), "Store Officer retains authorized role");

echo "\n======================================================================\n";
echo " SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
