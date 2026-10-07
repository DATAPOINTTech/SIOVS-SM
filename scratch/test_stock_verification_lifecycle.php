<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== VERIFICATION SUITE: STOCK VERIFICATION DRAFT, SUBMIT, EDIT, VOID & LOCKING ===\n\n";

// 1. Identify Store Officer Imran Ali
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = "imran@siovs.edu.pk"');
$stmt->execute();
$storeUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$storeUser) {
    echo "[FAIL] Store Officer not found\n";
    exit(1);
}
$_SESSION['siovs_user'] = $storeUser;

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

// Pick two items to use for testing
$testItems = $pdo->query('SELECT * FROM items ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
if (count($testItems) < 2) {
    echo "[FAIL] Need at least 2 items in database for verification tests\n";
    exit(1);
}
$it1 = $testItems[0];
$it2 = $testItems[1];
$initialStock1 = (int)$it1['quantity'];
$initialStock2 = (int)$it2['quantity'];

echo "[PASS] Test environment ready with items {$it1['code']} (stock: {$initialStock1}) and {$it2['code']} (stock: {$initialStock2})\n";

// -------------------------------------------------------------------------------------------------
// TEST 1: Save Physical Stock Verification as Draft
// -------------------------------------------------------------------------------------------------
echo "\n--- TEST 1: Saving as Draft (Staged counts, 0 adjustments) ---\n";
$draftPhys1 = $initialStock1 + 5; // discrepancy +5
$draftPhys2 = $initialStock2 - 3; // discrepancy -3

execute_page_post([
    'action' => 'verification',
    'submission_mode' => 'draft',
    'verification_date' => date('Y-m-d'),
    'committee_members' => 'Imran Ali (Store), Audit Rep, Nursing Supdt.',
    'remarks' => 'Draft quarterly count test',
    'generate_adjustments' => '1',
    'item_id' => [$it1['id'], $it2['id']],
    'system_qty' => [$initialStock1, $initialStock2],
    'physical_qty' => [$draftPhys1, $draftPhys2],
    'explanation' => ['Unrecorded excess found', 'Damaged ampoules discarded']
], $storeUser);

// Check created verification
$draftVer = $pdo->query('SELECT * FROM stock_verifications WHERE remarks = "Draft quarterly count test" ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$draftVer || $draftVer['status'] !== 'Draft') {
    echo "[FAIL] Draft verification not created with status 'Draft'\n";
    exit(1);
}
echo "[PASS] Verification {$draftVer['ver_number']} created with status 'Draft'\n";

// Check verification items
$vItems = $pdo->prepare('SELECT * FROM stock_verification_items WHERE verification_id = ? ORDER BY id ASC');
$vItems->execute([$draftVer['id']]);
$itemsList = $vItems->fetchAll(PDO::FETCH_ASSOC);
if (count($itemsList) !== 2 || (int)$itemsList[0]['physical_quantity'] !== $draftPhys1) {
    echo "[FAIL] Verification items not staged properly in stock_verification_items\n";
    exit(1);
}
echo "[PASS] Staged physical quantities stored properly (item 1: {$draftPhys1}, item 2: {$draftPhys2})\n";

// Check that ZERO stock adjustments were created for Draft
$adjCount = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE verification_id = ' . (int)$draftVer['id'])->fetchColumn();
if ($adjCount !== 0) {
    echo "[FAIL] Draft verification generated {$adjCount} adjustments, expected 0!\n";
    exit(1);
}
echo "[PASS] Zero stock adjustments generated for Draft verification\n";

// Check that store stock in items table remained strictly unchanged
$curStock1 = (int)$pdo->query('SELECT quantity FROM items WHERE id = ' . (int)$it1['id'])->fetchColumn();
if ($curStock1 !== $initialStock1) {
    echo "[FAIL] Store stock changed unexpectedly during draft save!\n";
    exit(1);
}
echo "[PASS] Store inventory unchanged during Draft save ({$curStock1} units)\n";

// -------------------------------------------------------------------------------------------------
// TEST 2: UI Rendering (Edit Drawer & View Details Drawer)
// -------------------------------------------------------------------------------------------------
echo "\n--- TEST 2: UI Rendering of Edit & View Details Drawers ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['view' => 'verification', 'edit_ver' => $draftVer['id']];
ob_start();
include __DIR__ . '/../page.php';
$editHtml = ob_get_clean();

if (str_contains($editHtml, 'Edit Physical Stock Verification: ' . $draftVer['ver_number']) &&
    str_contains($editHtml, 'name="submission_mode" value="submitted"') &&
    str_contains($editHtml, 'value="' . $draftPhys1 . '"')) {
    echo "[PASS] Populated edit form rendered for verification {$draftVer['ver_number']}\n";
} else {
    echo "[FAIL] Edit form failed to render populated values\n";
    exit(1);
}

$_GET = ['view' => 'verification', 'view_ver' => $draftVer['id']];
ob_start();
include __DIR__ . '/../page.php';
$viewHtml = ob_get_clean();

if (str_contains($viewHtml, 'Audit Details: ' . $draftVer['ver_number']) &&
    str_contains($viewHtml, 'Unrecorded excess found') &&
    str_contains($viewHtml, 'Damaged ampoules discarded')) {
    echo "[PASS] View details drawer rendered with discrepancy breakdown\n";
} else {
    echo "[FAIL] View details drawer failed to render\n";
    exit(1);
}

// -------------------------------------------------------------------------------------------------
// TEST 3: Edit & Finalize/Submit Verification (Generate Pending Adjustments)
// -------------------------------------------------------------------------------------------------
echo "\n--- TEST 3: Editing and Finalizing Verification (Generating Pending Adjustments) ---\n";
$subPhys1 = $initialStock1 + 10;
$subPhys2 = $initialStock2;

execute_page_post([
    'action' => 'verification',
    'verification_id' => $draftVer['id'],
    'submission_mode' => 'submitted',
    'verification_date' => date('Y-m-d'),
    'committee_members' => 'Imran Ali, DD Finance Rep, External Auditor',
    'remarks' => 'Final verified physical audit',
    'generate_adjustments' => '1',
    'item_id' => [$it1['id'], $it2['id']],
    'system_qty' => [$initialStock1, $initialStock2],
    'physical_qty' => [$subPhys1, $subPhys2],
    'explanation' => ['Re-counted warehouse shelf A, confirmed 10 excess units', 'Recount matched system']
], $storeUser);

$subVer = $pdo->query('SELECT * FROM stock_verifications WHERE id = ' . (int)$draftVer['id'])->fetch(PDO::FETCH_ASSOC);
if (!$subVer || $subVer['status'] !== 'Submitted') {
    echo "[FAIL] Verification status not updated to 'Submitted'\n";
    exit(1);
}
echo "[PASS] Verification {$subVer['ver_number']} successfully finalized with status 'Submitted'\n";

// Check that adjustments were created for item 1 (+10) and NOT item 2 (0 diff)
$adjs = $pdo->query('SELECT * FROM stock_adjustments WHERE verification_id = ' . (int)$subVer['id'])->fetchAll(PDO::FETCH_ASSOC);
if (count($adjs) !== 1) {
    echo "[FAIL] Expected 1 adjustment for variance, found " . count($adjs) . "\n";
    exit(1);
}
$adjRow = $adjs[0];
if ((int)$adjRow['quantity'] !== 10 || $adjRow['status'] !== 'Pending') {
    echo "[FAIL] Adjustment quantity or status invalid (qty: {$adjRow['quantity']}, status: {$adjRow['status']})\n";
    exit(1);
}
echo "[PASS] Pending adjustment {$adjRow['adj_number']} generated with quantity +10 linked to verification\n";

// -------------------------------------------------------------------------------------------------
// TEST 4: Edit Submitted Verification Before DD Finance Approval (Recalculate Adjustments)
// -------------------------------------------------------------------------------------------------
echo "\n--- TEST 4: Editing Submitted Verification (Recalculating Pending Adjustments) ---\n";
// Suppose Store Officer discovers a recount mistake: item 1 excess is actually +7 (not +10)
$corrPhys1 = $initialStock1 + 7;

execute_page_post([
    'action' => 'verification',
    'verification_id' => $subVer['id'],
    'submission_mode' => 'submitted',
    'verification_date' => date('Y-m-d'),
    'committee_members' => 'Imran Ali, DD Finance Rep, External Auditor',
    'remarks' => 'Corrected physical count after shelf recount',
    'generate_adjustments' => '1',
    'item_id' => [$it1['id'], $it2['id']],
    'system_qty' => [$initialStock1, $initialStock2],
    'physical_qty' => [$corrPhys1, $subPhys2],
    'explanation' => ['Recounted shelf A accurately (+7 excess)', 'Matched']
], $storeUser);

// Check updated pending adjustments
$updatedAdjs = $pdo->query('SELECT * FROM stock_adjustments WHERE verification_id = ' . (int)$subVer['id'])->fetchAll(PDO::FETCH_ASSOC);
if (count($updatedAdjs) !== 1 || (int)$updatedAdjs[0]['quantity'] !== 7) {
    echo "[FAIL] Expected 1 pending adjustment with recalculated quantity 7, found: " . json_encode($updatedAdjs) . "\n";
    exit(1);
}
echo "[PASS] Pending adjustments cleanly recalculated to +7 units (old pending adjustment replaced)\n";

// -------------------------------------------------------------------------------------------------
// TEST 5: Void Verification and Cancel Linked Pending Adjustments
// -------------------------------------------------------------------------------------------------
echo "\n--- TEST 5: Voiding Verification and Auto-Cancelling Pending Adjustments ---\n";
execute_page_post([
    'action' => 'void_verification',
    'verification_id' => $subVer['id']
], $storeUser);

$voidedVer = $pdo->query('SELECT * FROM stock_verifications WHERE id = ' . (int)$subVer['id'])->fetch(PDO::FETCH_ASSOC);
if ($voidedVer['status'] !== 'Voided') {
    echo "[FAIL] Verification status is not 'Voided'\n";
    exit(1);
}
echo "[PASS] Verification status successfully updated to 'Voided'\n";

$cancelledAdjs = $pdo->query('SELECT * FROM stock_adjustments WHERE verification_id = ' . (int)$subVer['id'])->fetchAll(PDO::FETCH_ASSOC);
foreach ($cancelledAdjs as $ca) {
    if ($ca['status'] !== 'Cancelled') {
        echo "[FAIL] Linked adjustment {$ca['adj_number']} status is {$ca['status']}, expected 'Cancelled'\n";
        exit(1);
    }
}
echo "[PASS] All linked pending adjustments successfully marked as 'Cancelled'\n";

// -------------------------------------------------------------------------------------------------
// TEST 6: Locking Protection When DD Finance Approves Adjustments
// -------------------------------------------------------------------------------------------------
echo "\n--- TEST 6: Strict Locking Enforcement When Adjustments Are Approved ---\n";
execute_page_post([
    'action' => 'verification',
    'submission_mode' => 'submitted',
    'verification_date' => date('Y-m-d'),
    'committee_members' => 'Board of Survey',
    'remarks' => 'Audit to test DD Finance locking',
    'generate_adjustments' => '1',
    'item_id' => [$it1['id']],
    'system_qty' => [$initialStock1],
    'physical_qty' => [$initialStock1 + 4],
    'explanation' => ['Excess found']
], $storeUser);

$lockVer = $pdo->query('SELECT * FROM stock_verifications WHERE remarks = "Audit to test DD Finance locking" ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$lockAdj = $pdo->query('SELECT * FROM stock_adjustments WHERE verification_id = ' . (int)$lockVer['id'])->fetch(PDO::FETCH_ASSOC);

if (!$lockVer || !$lockAdj) {
    echo "[FAIL] Failed to create test verification for locking test\n";
    exit(1);
}

// Simulate DD Finance approval of the adjustment
$pdo->prepare('UPDATE stock_adjustments SET status = "Approved", authorizing_officer = "DD Finance" WHERE id = ?')
    ->execute([$lockAdj['id']]);
echo "[PASS] Simulated DD Finance approval of adjustment {$lockAdj['adj_number']}\n";

// Now attempt to edit this locked verification
execute_page_post([
    'action' => 'verification',
    'verification_id' => $lockVer['id'],
    'submission_mode' => 'submitted',
    'verification_date' => date('Y-m-d'),
    'committee_members' => 'Malicious or Accidental Modification',
    'remarks' => 'Tampered remarks',
    'generate_adjustments' => '1',
    'item_id' => [$it1['id']],
    'system_qty' => [$initialStock1],
    'physical_qty' => [$initialStock1 + 20],
    'explanation' => ['Tampered explanation']
], $storeUser);

// Verify the record was NOT modified
$unmodifiedVer = $pdo->query('SELECT * FROM stock_verifications WHERE id = ' . (int)$lockVer['id'])->fetch(PDO::FETCH_ASSOC);
if ($unmodifiedVer['remarks'] === 'Tampered remarks') {
    echo "[FAIL] Locked verification was improperly modified!\n";
    exit(1);
}
echo "[PASS] Edit attempt on locked verification strictly rejected\n";

// Attempt to void this locked verification
execute_page_post([
    'action' => 'void_verification',
    'verification_id' => $lockVer['id']
], $storeUser);

$stillApprovedVer = $pdo->query('SELECT * FROM stock_verifications WHERE id = ' . (int)$lockVer['id'])->fetch(PDO::FETCH_ASSOC);
if ($stillApprovedVer['status'] === 'Voided') {
    echo "[FAIL] Locked verification was improperly voided!\n";
    exit(1);
}
echo "[PASS] Void attempt on locked verification strictly rejected\n";

// Clean up test records
$pdo->exec('DELETE FROM stock_adjustments WHERE verification_id IN (' . (int)$draftVer['id'] . ',' . (int)$lockVer['id'] . ')');
$pdo->exec('DELETE FROM stock_verification_items WHERE verification_id IN (' . (int)$draftVer['id'] . ',' . (int)$lockVer['id'] . ')');
$pdo->exec('DELETE FROM stock_verifications WHERE id IN (' . (int)$draftVer['id'] . ',' . (int)$lockVer['id'] . ')');

echo "\n=== ALL PHYSICAL STOCK VERIFICATION LIFECYCLE TESTS PASSED WITH 100% SUCCESS ===\n";
