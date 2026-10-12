<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_login();
log_activity($pdo, 'page_view', 'dashboard');

/* ── Dashboard KPIs ──────────────────────────────────────────────────────── */
$u = current_user();
$isPurchaseOfficer = (($u['role'] ?? '') === 'purchase_officer');
$isStoreOfficer    = (($u['role'] ?? '') === 'store_officer');
$isDDFinance       = (($u['role'] ?? '') === 'dd_finance');
$today = date('Y-m-d');

if ($isPurchaseOfficer) {
    // ── Purchase Officer Specific Metrics (Sections 3, 29, 31 of SIOVS Requirements) ──
    $criticalStock      = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity = 0')->fetchColumn();
    $nearExpiry30       = 0;
    $pendingAdj         = 0;
    $pendingGRNs        = 0;
    $poDraftCount       = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Draft"')->fetchColumn();
    $poApprovedCount    = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Approved"')->fetchColumn();
    $poPartialCount     = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Partially Received"')->fetchColumn();
    $poDeliveredCount   = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Delivered"')->fetchColumn();
    $poAwaitingDelivery = $poApprovedCount + $poPartialCount;
    $totalPOs           = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
    $openPOs            = $poDraftCount + $poAwaitingDelivery;

    $poOverdueCount     = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE expected_delivery IS NOT NULL AND expected_delivery != '' AND expected_delivery < '{$today}' AND status IN ('Approved', 'Partially Received')")->fetchColumn();
    $totalPOValue       = (float)$pdo->query('SELECT COALESCE(SUM(total_value),0) FROM purchase_orders WHERE status != "Cancelled"')->fetchColumn();
    $totalSuppliers     = (int)$pdo->query('SELECT COUNT(*) FROM suppliers WHERE status = "Active"')->fetchColumn();

    // Critical Reorder Queue: items in store below safety threshold
    $reorderItems = $pdo->query('
        SELECT i.id, i.code, i.name, i.category, i.unit, i.quantity, i.reorder_level, i.min_level, i.lead_time_days,
               s.name AS supplier_name
        FROM items i
        LEFT JOIN suppliers s ON s.id = i.supplier_id
        WHERE i.quantity <= i.reorder_level
        ORDER BY (i.quantity - i.reorder_level) ASC, i.quantity ASC
        LIMIT 8
    ')->fetchAll(PDO::FETCH_ASSOC);
    $reorderCount = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level')->fetchColumn();

    // Active Purchase Orders for Live Delivery Tracking
    $recentPOs = $pdo->query('
        SELECT po.*, s.name as supplier_name, s.phone as supplier_phone,
               (SELECT COUNT(*) FROM po_items pi WHERE pi.po_id = po.id) as item_count,
               (SELECT COALESCE(SUM(quantity_ordered),0) FROM po_items pi WHERE pi.po_id = po.id) as qty_ordered,
               (SELECT COALESCE(SUM(gi.quantity_accepted),0) FROM grns g JOIN grn_items gi ON gi.grn_id = g.id WHERE g.po_id = po.id) as qty_received
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        ORDER BY po.id DESC
        LIMIT 10
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Recent Store Receipts (GRNs against POs)
    $recentGRNs = $pdo->query('
        SELECT g.*, po.po_number, s.name as supplier_name,
               (SELECT COUNT(*) FROM grn_items gi WHERE gi.grn_id = g.id) as item_count,
               (SELECT COALESCE(SUM(gi.quantity_accepted),0) FROM grn_items gi WHERE gi.grn_id = g.id) as total_accepted,
               (SELECT COALESCE(SUM(gi.quantity_rejected),0) FROM grn_items gi WHERE gi.grn_id = g.id) as total_rejected
        FROM grns g
        LEFT JOIN purchase_orders po ON po.id = g.po_id
        LEFT JOIN suppliers s ON s.id = g.supplier_id
        ORDER BY g.id DESC
        LIMIT 6
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Fulfillment Rate
    $totalOrderedAll = (int)$pdo->query('SELECT COALESCE(SUM(quantity_ordered),0) FROM po_items pi JOIN purchase_orders po ON po.id = pi.po_id WHERE po.status != "Cancelled"')->fetchColumn();
    $totalReceivedAll = (int)$pdo->query('SELECT COALESCE(SUM(gi.quantity_accepted),0) FROM grn_items gi')->fetchColumn();
    $fulfillmentRate = $totalOrderedAll > 0 ? min(100, round(($totalReceivedAll / $totalOrderedAll) * 100, 1)) : 100.0;
} elseif ($isStoreOfficer) {
    // ── Store Officer Specific Metrics (Sections 4, 6, 8, 14, 29, 31) ──
    $criticalStock = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity = 0')->fetchColumn();
    $lowStock      = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level AND quantity > 0')->fetchColumn();
    $nearExpiry30  = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}','+30 days')")->fetchColumn();
    $expiredItems  = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE expiry_date IS NOT NULL AND expiry_date < '{$today}'")->fetchColumn();
    $totalItems    = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $activeBatches = (int)$pdo->query('SELECT COUNT(*) FROM item_batches WHERE quantity > 0')->fetchColumn();

    $pendingGRNs   = (int)$pdo->query('SELECT COUNT(*) FROM grns WHERE inspection_status = "Pending"')->fetchColumn();
    $incomingPOs   = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status IN ("Approved", "Partially Received")')->fetchColumn();
    $pendingReqs   = (int)$pdo->query('SELECT COUNT(*) FROM requisitions WHERE status = "Approved"')->fetchColumn();
    $pendingReturns= (int)$pdo->query('SELECT COUNT(*) FROM department_returns WHERE status = "Pending"')->fetchColumn();
    $pendingAdj    = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();

    // Store Stock Valuation (PKR)
    $stockValue = (float)$pdo->query(
        "SELECT COALESCE(SUM(i.quantity * COALESCE(
            (SELECT g.rate FROM grn_items g WHERE g.item_id=i.id AND g.rate>0 ORDER BY g.id DESC LIMIT 1),
            0
        )),0) FROM items i"
    )->fetchColumn();

    // Incoming Deliveries awaiting GRN
    $incomingPOList = $pdo->query('
        SELECT po.*, s.name as supplier_name, s.phone as supplier_phone,
               (SELECT COUNT(*) FROM po_items pi WHERE pi.po_id = po.id) as item_count,
               (SELECT COALESCE(SUM(quantity_ordered),0) FROM po_items pi WHERE pi.po_id = po.id) as qty_ordered,
               (SELECT COALESCE(SUM(gi.quantity_accepted),0) FROM grns g JOIN grn_items gi ON gi.grn_id = g.id WHERE g.po_id = po.id) as qty_received
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.status IN ("Approved", "Partially Received")
        ORDER BY po.id DESC
        LIMIT 6
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Requisitions awaiting Store Issue (status = Approved)
    $approvedReqList = $pdo->query('
        SELECT r.*, d.name AS dept_name,
               (SELECT COUNT(*) FROM requisition_items ri WHERE ri.req_id = r.id) as item_count,
               (SELECT COALESCE(SUM(required_quantity),0) FROM requisition_items ri WHERE ri.req_id = r.id) as total_qty
        FROM requisitions r
        LEFT JOIN departments d ON d.id = r.department_id
        WHERE r.status = "Approved"
        ORDER BY r.id DESC
        LIMIT 6
    ')->fetchAll(PDO::FETCH_ASSOC);

    // FEFO Watchlist (Earliest expiring batches)
    $fefoBatches = $pdo->query("
        SELECT ib.batch_number, ib.expiry_date, ib.quantity, i.name as item_name, i.code as item_code, i.unit,
               CAST(julianday(ib.expiry_date) - julianday('now') AS INTEGER) as days_left
        FROM item_batches ib
        JOIN items i ON i.id = ib.item_id
        WHERE ib.expiry_date IS NOT NULL AND ib.expiry_date >= '{$today}' AND ib.quantity > 0
        ORDER BY ib.expiry_date ASC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recent store transactions
    $recentTx = $pdo->query('SELECT * FROM transactions ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);

    // Low stock items
    $lowStockItems = $pdo->query('
        SELECT name, code, quantity, reorder_level, status, unit FROM items
        WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 6
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Pending department returns
    $pendingReturnList = $pdo->query('
        SELECT ret.*, d.name as dept_name,
               (SELECT COUNT(*) FROM department_return_items ri WHERE ri.return_id = ret.id) as item_count
        FROM department_returns ret
        LEFT JOIN departments d ON d.id = ret.department_id
        WHERE ret.status = "Pending"
        ORDER BY ret.id DESC
        LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isDDFinance) {
    // ── DD Finance Specific Metrics (Financial Oversight & Audits) ──
    $stockValue = (float)$pdo->query(
        "SELECT COALESCE(SUM(i.quantity * COALESCE(
            (SELECT g.rate FROM grn_items g WHERE g.item_id=i.id AND g.rate>0 ORDER BY g.id DESC LIMIT 1),
            0
        )),0) FROM items i"
    )->fetchColumn();
    $totalAssetVal = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM assets WHERE status != 'Condemned'")->fetchColumn();

    $pendingAdj = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();
    $pendingAdjVal = (float)$pdo->query('SELECT COALESCE(SUM(value),0) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();
    $pendingDeletions = (int)$pdo->query('SELECT COUNT(*) FROM deletion_requests WHERE status = "Pending"')->fetchColumn();
    $pendingVer = (int)$pdo->query('SELECT COUNT(*) FROM stock_verifications WHERE status = "Submitted"')->fetchColumn();
    $openPOs = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ("Delivered","Cancelled")')->fetchColumn();
    $poCommittedVal = (float)$pdo->query('SELECT COALESCE(SUM(total_value),0) FROM purchase_orders WHERE status IN ("Approved","Partially Received")')->fetchColumn();

    // Pending stock adjustments list for quick approval/rejection
    $pendingAdjList = $pdo->query('
        SELECT sa.*, i.name AS item_name, i.code AS item_code, i.unit AS item_unit, u.name AS requester_name
        FROM stock_adjustments sa
        JOIN items i ON i.id = sa.item_id
        LEFT JOIN users u ON u.id = sa.requesting_user_id
        WHERE sa.status = "Pending"
        ORDER BY sa.id DESC
        LIMIT 10
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Pending deletion requests list
    $pendingDelList = $pdo->query('
        SELECT d.*, u.name AS requester_name
        FROM deletion_requests d
        LEFT JOIN users u ON u.id = d.requested_by
        WHERE d.status = "Pending"
        ORDER BY d.id DESC
        LIMIT 6
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Recent submitted physical verifications with discrepancies
    $recentAudits = $pdo->query('
        SELECT v.*, u.name AS creator_name,
               (SELECT COUNT(*) FROM stock_verification_items WHERE verification_id = v.id) AS item_count,
               (SELECT COUNT(*) FROM stock_verification_items WHERE verification_id = v.id AND difference != 0) AS discrepancy_count
        FROM stock_verifications v
        LEFT JOIN users u ON u.id = v.created_by
        ORDER BY v.id DESC
        LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Inventory KPIs
    $totalItems   = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $lowStock     = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level AND quantity > 0')->fetchColumn();
    $criticalStock= (int) $pdo->query('SELECT COUNT(*) FROM items WHERE quantity = 0')->fetchColumn();
    $expiredItems = (int) $pdo->query("SELECT COUNT(*) FROM items WHERE expiry_date IS NOT NULL AND expiry_date < '{$today}'")->fetchColumn();
    $nearExpiry30 = (int) $pdo->query("SELECT COUNT(*) FROM items WHERE expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}','+30 days')")->fetchColumn();

    // Pending items
    $pendingGRNs  = (int) $pdo->query('SELECT COUNT(*) FROM grns WHERE inspection_status = "Pending"')->fetchColumn();
    $pendingReqs  = (int) $pdo->query('SELECT COUNT(*) FROM requisitions WHERE status IN ("Draft","Approved")')->fetchColumn();
    $pendingAdj   = (int) $pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();

    // Transaction counts
    $totalPOs     = (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn();
    $openPOs      = (int) $pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ("Delivered","Cancelled")')->fetchColumn();
    $totalAssets  = (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn();
    $assetsRepair = (int) $pdo->query('SELECT COUNT(*) FROM assets WHERE status = "Under repair"')->fetchColumn();

    // Near-expiry assets (AMC/warranty)
    $assetWarrantyExpiring = (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE warranty_until IS NOT NULL AND warranty_until >= '{$today}' AND warranty_until <= date('{$today}','+30 days')")->fetchColumn();

    // Today's consumption
    $todayOPD = (int) $pdo->query("SELECT COUNT(DISTINCT patient_mr) FROM consumption_records WHERE type='OPD' AND date(created_at)='{$today}'")->fetchColumn();
    $todayOT  = (int) $pdo->query("SELECT COUNT(DISTINCT surgery_number) FROM consumption_records WHERE type='OT' AND date(created_at)='{$today}'")->fetchColumn();

    // Stock value (estimated from last received rate × quantity)
    $stockValue = (float) $pdo->query(
        "SELECT COALESCE(SUM(i.quantity * COALESCE(
            (SELECT g.rate FROM grn_items g WHERE g.item_id=i.id AND g.rate>0 ORDER BY g.id DESC LIMIT 1),
            0
        )),0) FROM items i"
    )->fetchColumn();

    // Recent transactions
    $recentTx = $pdo->query(
        'SELECT * FROM transactions ORDER BY id DESC LIMIT 6'
    )->fetchAll(PDO::FETCH_ASSOC);

    // Near-expiry items list
    $expiryItems = $pdo->query(
        "SELECT name, code, quantity, expiry_date,
                CAST(julianday(expiry_date) - julianday('now') AS INTEGER) as days_left
         FROM items
         WHERE expiry_date IS NOT NULL AND expiry_date >= '{$today}'
         ORDER BY expiry_date ASC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Low-stock items list
    $lowStockItems = $pdo->query(
        'SELECT name, code, quantity, reorder_level, status FROM items
         WHERE quantity <= reorder_level ORDER BY quantity ASC LIMIT 5'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ── Sparkline: last 7 days transaction counts ──────────────────────────────
$sparkRows = $pdo->query(
    "SELECT date(created_at) as day, COUNT(*) as cnt
     FROM transactions
     WHERE created_at >= date('now','-6 days')
     GROUP BY date(created_at)
     ORDER BY day ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$sparkMap = [];
foreach ($sparkRows as $r) { $sparkMap[$r['day']] = (int)$r['cnt']; }
$sparkDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $sparkDays[] = $sparkMap[$d] ?? 0;
}
$sparkJson = json_encode($sparkDays);

// ── Pending-actions list for the alert banner ──────────────────────────────
$pendingActions = [];
if ($isPurchaseOfficer) {
    if ($poOverdueCount > 0) $pendingActions[] = ['label' => "{$poOverdueCount} purchase order" . ($poOverdueCount > 1 ? 's' : '') . ' overdue for delivery', 'link' => page_link('purchase_orders'), 'perm' => 'purchase_orders'];
    if ($poDraftCount > 0)   $pendingActions[] = ['label' => "{$poDraftCount} draft purchase order" . ($poDraftCount > 1 ? 's' : '') . ' awaiting authorization', 'link' => page_link('purchase_orders'), 'perm' => 'purchase_orders'];
    if ($reorderCount > 0)   $pendingActions[] = ['label' => "{$reorderCount} store item" . ($reorderCount > 1 ? 's' : '') . ' below reorder level', 'link' => page_link('inventory'), 'perm' => 'inventory'];
} elseif ($isStoreOfficer) {
    if ($incomingPOs > 0)    $pendingActions[] = ['label' => "{$incomingPOs} purchase order" . ($incomingPOs > 1 ? 's' : '') . ' arrived · Ready for GRN', 'link' => page_link('purchase_orders'), 'perm' => 'purchase_orders'];
    if ($pendingGRNs > 0)    $pendingActions[] = ['label' => "{$pendingGRNs} GRN" . ($pendingGRNs > 1 ? 's' : '') . ' pending physical inspection', 'link' => page_link('grn'), 'perm' => 'grn'];
    if ($pendingReqs > 0)    $pendingActions[] = ['label' => "{$pendingReqs} requisition" . ($pendingReqs > 1 ? 's' : '') . ' approved · Awaiting store issue', 'link' => page_link('requisitions'), 'perm' => 'requisitions'];
    if ($pendingReturns > 0) $pendingActions[] = ['label' => "{$pendingReturns} department return" . ($pendingReturns > 1 ? 's' : '') . ' awaiting verification', 'link' => page_link('returns'), 'perm' => 'returns'];
    if ($nearExpiry30 > 0)   $pendingActions[] = ['label' => "{$nearExpiry30} item" . ($nearExpiry30 > 1 ? 's' : '') . ' expiring in 30 days · Review FEFO', 'link' => page_link('batches'), 'perm' => 'batches'];
    if ($criticalStock > 0)  $pendingActions[] = ['label' => "{$criticalStock} item" . ($criticalStock > 1 ? 's' : '') . ' out of stock in Main Store', 'link' => page_link('inventory'), 'perm' => 'inventory'];
} elseif ($isDDFinance) {
    if ($pendingAdj > 0)       $pendingActions[] = ['label' => "{$pendingAdj} stock adjustment" . ($pendingAdj > 1 ? 's' : '') . ' awaiting financial authorization', 'link' => page_link('adjustments'), 'perm' => 'adjustments'];
    if ($pendingDeletions > 0) $pendingActions[] = ['label' => "{$pendingDeletions} deletion request" . ($pendingDeletions > 1 ? 's' : '') . ' pending audit authorization', 'link' => page_link('deletions'), 'perm' => 'deletions'];
    if ($pendingVer > 0)       $pendingActions[] = ['label' => "{$pendingVer} physical audit" . ($pendingVer > 1 ? 's' : '') . ' submitted for review', 'link' => page_link('verification'), 'perm' => 'verification'];
} else {
    if ($pendingGRNs > 0) $pendingActions[] = ['label' => "{$pendingGRNs} GRN" . ($pendingGRNs > 1 ? 's' : '') . ' pending inspection',         'link' => page_link('grn'),          'perm' => 'grn'];
    if ($pendingReqs > 0) $pendingActions[] = ['label' => "{$pendingReqs} requisition" . ($pendingReqs > 1 ? 's' : '') . ' awaiting action',     'link' => page_link('requisitions'), 'perm' => 'requisitions'];
    if ($pendingAdj  > 0) $pendingActions[] = ['label' => "{$pendingAdj} stock adjustment" . ($pendingAdj > 1 ? 's' : '') . ' pending approval', 'link' => page_link('adjustments'),  'perm' => 'adjustments'];
}
$current = 'dashboard';
$title   = $isPurchaseOfficer ? 'Procurement Dashboard' : ($isStoreOfficer ? 'Store Operations Dashboard' : ($isDDFinance ? 'Finance Command Center' : 'Dashboard'));
require __DIR__ . '/partials/header.php';
?>
<section class="page-content" id="dashboard">
<?php if ($isPurchaseOfficer): ?>
    <!-- ═════════════════════════════════════════════════════════════════
         PROCUREMENT COMMAND CENTER (PURCHASE OFFICER DASHBOARD)
         Conforms to SIOVS Requirements Sections 3, 29 & 31
         ═════════════════════════════════════════════════════════════════ -->

    <!-- ── Hero Banner ─────────────────────────────────────────────── -->
    <div class="hero hero-expanded" style="background:linear-gradient(135deg, #064e3b 0%, #065f46 45%, #047857 100%)">
        <div class="hero-right">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap">
                <p class="eyebrow" id="hero-date" style="margin:0"><?= strtoupper(date('l, j F Y')) ?></p>
                <span style="background:rgba(255,255,255,0.18);color:#e8f37c;font-size:11px;font-weight:700;letter-spacing:1px;padding:3px 10px;border-radius:12px;text-transform:uppercase">Department of Procurement &amp; Supply Chain</span>
            </div>
            <h1 class="hero-greeting">Procurement Command Center</h1>
            <p class="hero-copy" style="font-size:13px;max-width:720px;line-height:1.45">
                Logged in as <b><?= e($u['name'] ?? 'Purchase Officer') ?></b>. Manage vendor orders, track delivery pipelines, and monitor Store replenishment triggers.
                <span id="live-clock" style="font-weight:700;color:#e8f37c;font-size:14px;margin-left:8px"></span>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                <a href="page.php?view=purchase_orders#add-po" class="button" style="background:#10b981;color:#fff;border:none;font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">＋ Create Purchase Order</a>
                <a href="page.php?view=suppliers#add-supplier" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">⊞ Add Supplier</a>
                <a href="#reorder-queue" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">⚠️ Store Reorder Triggers (<?= $reorderCount ?>)</a>
                <a href="page.php?view=reports&rtype=purchase" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">▥ PO Fulfillment Report</a>
            </div>
        </div>
        <div class="hero-decoration"><span></span><span></span><span></span></div>
    </div>

    <!-- ── Regulatory Compliance Banner (Section 3 & 29) ─────────── -->
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;border-radius:8px;padding:12px 16px;margin:1rem 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
            <span style="font-size:24px">⚖️</span>
            <div style="font-size:12px;color:#166534;line-height:1.45">
                <strong style="font-size:13px;display:block;margin-bottom:2px">SIOVS SOP Section 3 &amp; 29 Segregation of Duties:</strong>
                The Purchase Section creates and issues Purchase Orders (POs) and monitors vendor delivery commitments.
                Physical receiving, inspection, and inventory balance posting are strictly performed by the Store Section via Goods Receipt Notes (GRN).
            </div>
        </div>
        <span style="font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:6px;white-space:nowrap;border:1px solid #86efac">
            ✓ Sindh Health Audit Compliant
        </span>
    </div>

    <!-- ── KPI Row 1: Procurement & Delivery Pipeline ─────────────── -->
    <div class="section-heading">
        <div><p class="eyebrow">ORDER PIPELINE</p><h2>Procurement &amp; Delivery Tracking</h2></div>
        <a href="page.php?view=purchase_orders" class="text-button" style="font-size:12px">View Full Purchase Register →</a>
    </div>
    <div class="metric-grid four-col" style="grid-template-columns:repeat(4, 1fr);gap:14px;margin-bottom:1.25rem">
        <!-- Card 1: Active POs -->
        <article class="metric-card kpi-card kpi-po-active" data-link="page.php?view=purchase_orders" style="background:linear-gradient(135deg, #3730a3 0%, #4f46e5 55%, #6366f1 100%);box-shadow:0 10px 28px rgba(79,70,229,.35);cursor:pointer;--delay:0">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Purchase orders clipboard -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="35" y="20" width="90" height="90" rx="8" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".35"/>
                    <rect x="60" y="13" width="40" height="18" rx="5" fill="white" opacity=".35"/>
                    <rect x="68" y="10" width="24" height="12" rx="3" fill="white" opacity=".5"/>
                    <line x1="50" y1="48" x2="115" y2="48" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <line x1="50" y1="62" x2="115" y2="62" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <line x1="50" y1="76" x2="95" y2="76" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <rect x="42" y="42" width="8" height="8" rx="2" fill="white" opacity=".5"/>
                    <path d="M43.5 46 L45 47.5 L48 44" stroke="white" stroke-width="1.8" stroke-linecap="round" opacity=".9"/>
                    <rect x="42" y="56" width="8" height="8" rx="2" fill="white" opacity=".5"/>
                    <path d="M43.5 60 L45 61.5 L48 58" stroke="white" stroke-width="1.8" stroke-linecap="round" opacity=".9"/>
                    <rect x="42" y="70" width="8" height="8" rx="2" fill="white" opacity=".35"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Active Purchase Orders</span>
                    <span class="trend up"><?= $poApprovedCount ?> Approved</span>
                </div>
                <strong><?= $totalPOs ?></strong>
                <small><?= $poDraftCount ?> Draft · <?= $poAwaitingDelivery ?> In Pipeline</small>
            </div>
        </article>

        <!-- Card 2: Deliveries In-Transit -->
        <article class="metric-card kpi-card kpi-po-transit" data-link="page.php?view=purchase_orders" style="background:linear-gradient(135deg, #0369a1 0%, #0284c7 55%, #38bdf8 100%);box-shadow:0 10px 28px rgba(2,132,199,.35);cursor:pointer;--delay:1">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Delivery truck in transit -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="25" y="42" width="68" height="42" rx="5" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <path d="M93 52 L116 52 L127 68 L127 84 L93 84 Z" fill="white" opacity=".28" stroke="white" stroke-width="2" opacity=".45"/>
                    <rect x="99" y="57" width="14" height="12" rx="2" fill="white" opacity=".4"/>
                    <circle cx="45" cy="84" r="9" fill="white" opacity=".5" stroke="white" stroke-width="2" opacity=".7"/>
                    <circle cx="80" cy="84" r="9" fill="white" opacity=".5" stroke="white" stroke-width="2" opacity=".7"/>
                    <circle cx="115" cy="84" r="9" fill="white" opacity=".5" stroke="white" stroke-width="2" opacity=".7"/>
                    <line x1="10" y1="52" x2="20" y2="52" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".5"/>
                    <line x1="6" y1="62" x2="18" y2="62" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".4"/>
                    <line x1="12" y1="72" x2="20" y2="72" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".5"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Awaiting Delivery (In-Transit)</span>
                    <span class="trend neutral"><?= $poPartialCount > 0 ? "{$poPartialCount} Partial" : 'Awaiting Store' ?></span>
                </div>
                <strong><?= $poAwaitingDelivery ?></strong>
                <small>Dispatched by vendors, pending Store GRN receipt</small>
            </div>
        </article>

        <!-- Card 3: Overdue Deliveries -->
        <article class="metric-card kpi-card <?= $poOverdueCount > 0 ? 'kpi-po-overdue-warn' : 'kpi-po-overdue-ok' ?>" data-link="page.php?view=purchase_orders" style="background:<?= $poOverdueCount > 0 ? 'linear-gradient(135deg, #991b1b 0%, #dc2626 55%, #ef4444 100%)' : 'linear-gradient(135deg, #15803d 0%, #16a34a 55%, #22c55e 100%)' ?>;box-shadow:<?= $poOverdueCount > 0 ? '0 10px 28px rgba(220,38,38,.35)' : '0 10px 28px rgba(22,163,74,.35)' ?>;cursor:pointer;--delay:2">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Calendar / Clock alert -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="40" y="32" width="80" height="68" rx="8" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".4"/>
                    <rect x="40" y="32" width="80" height="20" rx="8" fill="white" opacity=".35"/>
                    <line x1="58" y1="24" x2="58" y2="34" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".7"/>
                    <line x1="102" y1="24" x2="102" y2="34" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".7"/>
                    <path d="M80 60 L92 82 L68 82 Z" fill="white" opacity=".4" stroke="white" stroke-width="2" opacity=".6"/>
                    <line x1="80" y1="68" x2="80" y2="74" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".9"/>
                    <circle cx="80" cy="78" r="1.5" fill="white" opacity=".95"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Overdue Deliveries</span>
                    <span class="trend <?= $poOverdueCount > 0 ? 'down' : 'up' ?>"><?= $poOverdueCount > 0 ? 'Follow-up Needed' : 'On Track' ?></span>
                </div>
                <strong><?= $poOverdueCount ?></strong>
                <small><?= $poOverdueCount > 0 ? 'Missed delivery deadline · Contact supplier' : 'Zero supplier delivery delays' ?></small>
            </div>
        </article>

        <!-- Card 4: Committed Spend -->
        <article class="metric-card kpi-card kpi-po-spend" data-link="page.php?view=purchase_orders" style="background:linear-gradient(135deg, #065f46 0%, #059669 55%, #10b981 100%);box-shadow:0 10px 28px rgba(5,150,105,.35);cursor:pointer;--delay:3">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Currency coins & bill -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <ellipse cx="65" cy="85" rx="30" ry="12" fill="white" opacity=".22" stroke="white" stroke-width="2" opacity=".4"/>
                    <ellipse cx="65" cy="72" rx="30" ry="12" fill="white" opacity=".28" stroke="white" stroke-width="2" opacity=".4"/>
                    <ellipse cx="65" cy="59" rx="30" ry="12" fill="white" opacity=".35" stroke="white" stroke-width="2" opacity=".5"/>
                    <rect x="85" y="30" width="55" height="35" rx="4" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".35"/>
                    <circle cx="112" cy="47" r="8" stroke="white" stroke-width="2" opacity=".45"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Committed Spend</span>
                    <span class="trend up">PKR</span>
                </div>
                <strong style="font-size:1.45rem">Rs. <?= number_format($totalPOValue, 0) ?></strong>
                <small>Total purchase commitment across all active POs</small>
            </div>
        </article>
    </div>

    <!-- ── KPI Row 2: Supply Chain Health & Store Reorder ─────────── -->
    <div class="section-heading" style="margin-top:.4rem">
        <div><p class="eyebrow">STORE SAFETY STOCK &amp; VENDORS</p><h2>Supply Chain Health</h2></div>
    </div>
    <div class="metric-grid four-col" style="grid-template-columns:repeat(4, 1fr);gap:14px;margin-bottom:1.5rem">
        <!-- Card 5: Suppliers -->
        <article class="metric-card kpi-card kpi-po-suppliers" data-link="page.php?view=suppliers" style="background:linear-gradient(135deg, #581c87 0%, #7e22ce 55%, #a855f7 100%);box-shadow:0 10px 28px rgba(126,34,206,.35);cursor:pointer;--delay:0">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Factory supplier building -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M30 92 L30 50 L55 65 L55 50 L80 65 L80 40 L115 40 L115 92 Z" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <rect x="42" y="74" width="14" height="18" rx="2" fill="white" opacity=".35"/>
                    <rect x="88" y="55" width="14" height="12" rx="2" fill="white" opacity=".35"/>
                    <rect x="88" y="74" width="14" height="12" rx="2" fill="white" opacity=".35"/>
                    <circle cx="125" cy="35" r="15" fill="white" opacity=".25" stroke="white" stroke-width="2" opacity=".4"/>
                    <path d="M125 26 L127.5 32 L134 32.5 L129 36.5 L131 43 L125 39 L119 43 L121 36.5 L116 32.5 L122.5 32 Z" fill="white" opacity=".8"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Qualified Suppliers</span>
                    <span class="trend up">Active</span>
                </div>
                <strong><?= $totalSuppliers ?></strong>
                <small>Registered pharmaceutical &amp; consumable vendors</small>
            </div>
        </article>

        <!-- Card 6: Reorder Triggers -->
        <article class="metric-card kpi-card <?= $reorderCount > 0 ? 'kpi-lowstock-warn' : 'kpi-lowstock-ok' ?>" data-link="#reorder-queue" style="background:<?= $reorderCount > 0 ? 'linear-gradient(135deg, #b85e10 0%, #e07a25 55%, #f0993c 100%)' : 'linear-gradient(135deg, #177761 0%, #22a37a 55%, #1db88a 100%)' ?>;box-shadow:<?= $reorderCount > 0 ? '0 10px 28px rgba(184,94,16,.35)' : '0 10px 28px rgba(23,119,97,.35)' ?>;cursor:pointer;--delay:1">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Bottle stock alert -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="48" y="38" width="48" height="64" rx="10" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <rect x="60" y="24" width="24" height="16" rx="4" fill="white" opacity=".3"/>
                    <rect x="56" y="16" width="32" height="12" rx="3" fill="white" opacity=".45"/>
                    <circle cx="120" cy="40" r="14" fill="white" opacity=".25" stroke="white" stroke-width="2" opacity=".5"/>
                    <path d="M120 32 L120 42" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".8"/>
                    <circle cx="120" cy="47" r="1.5" fill="white" opacity=".8"/>
                    <rect x="52" y="78" width="40" height="20" rx="6" fill="white" opacity=".35"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Store Re-Order Triggers</span>
                    <span class="trend <?= $reorderCount > 0 ? 'down' : 'up' ?>"><?= $criticalStock > 0 ? "{$criticalStock} Out of Stock" : 'Below Min' ?></span>
                </div>
                <strong><?= $reorderCount ?></strong>
                <small>Store safety stock breached · Needs Purchase Order</small>
            </div>
        </article>

        <!-- Card 7: Fulfillment Rate -->
        <article class="metric-card kpi-card kpi-po-fulfillment" data-link="page.php?view=reports&rtype=purchase" style="background:linear-gradient(135deg, #0f766e 0%, #0d9488 55%, #14b8a6 100%);box-shadow:0 10px 28px rgba(13,148,136,.35);cursor:pointer;--delay:2">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Verified shield check -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M80 25 C105 25 125 35 125 55 C125 80 100 100 80 105 C60 100 35 80 35 55 C35 35 55 25 80 25 Z" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <circle cx="80" cy="62" r="22" fill="white" opacity=".25"/>
                    <path d="M70 62 L77 69 L91 55" stroke="white" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" opacity=".85"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">PO Fulfillment Rate</span>
                    <span class="trend up"><?= $poDeliveredCount ?> Delivered</span>
                </div>
                <strong><?= $fulfillmentRate ?>%</strong>
                <small>Goods verified &amp; accepted into Main Store custody</small>
            </div>
        </article>

        <!-- Card 8: Store GRN Receipts Audit -->
        <article class="metric-card kpi-card kpi-po-receipts" data-link="page.php?view=reports&rtype=purchase" style="background:linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #3b82f6 100%);box-shadow:0 10px 28px rgba(37,99,235,.35);cursor:pointer;--delay:3">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Inward consignment box -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M40 50 L80 30 L120 50 L80 70 Z" fill="white" opacity=".3" stroke="white" stroke-width="2" opacity=".5"/>
                    <path d="M40 50 L40 90 L80 110 L80 70 Z" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <path d="M120 50 L120 90 L80 110 L80 70 Z" fill="white" opacity=".25" stroke="white" stroke-width="2" opacity=".45"/>
                    <path d="M80 15 L80 45 M70 35 L80 45 L90 35" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".7"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Store Inward GRN Receipts</span>
                    <span class="trend neutral">Store Audit</span>
                </div>
                <strong><?= count($recentGRNs) ?></strong>
                <small>Recent physical consignments accepted by Store</small>
            </div>
        </article>
    </div>

    <!-- ── Panel 1: Active Purchase Orders & Shipment Pipeline ───── -->
    <div class="dashboard-grid lower-grid" style="margin-top:.6rem">
        <section class="panel table-panel" style="grid-column:1/-1">
            <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div>
                    <p class="eyebrow">SUPPLIER SHIPMENTS</p>
                    <h2>Active Purchase Orders &amp; Delivery Tracking</h2>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <a class="button button-primary" href="page.php?view=purchase_orders#add-po" style="font-size:12px;padding:6px 12px">＋ New Purchase Order</a>
                    <a class="text-button" href="page.php?view=purchase_orders">View All POs →</a>
                </div>
            </div>
            <div class="table-wrap">
                <?php if (empty($recentPOs)): ?>
                <div class="empty-state">
                    <span class="empty-icon">📦</span>
                    <p>No purchase orders recorded yet.</p>
                    <small>Create your first PO using the button above.</small>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Order Date</th>
                            <th>Supplier &amp; Contact</th>
                            <th>Items / Qty</th>
                            <th>Expected Delivery</th>
                            <th>Committed Value (PKR)</th>
                            <th>Order Status</th>
                            <th>Store Inward Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentPOs as $po): ?>
                    <?php
                    $isOverdue = (!empty($po['expected_delivery']) && $po['expected_delivery'] < date('Y-m-d') && in_array($po['status'], ['Approved', 'Partially Received'], true));
                    ?>
                    <tr>
                        <td>
                            <b><?= e($po['po_number']) ?></b><br>
                            <small class="muted"><?= e($po['purchase_reference'] ?: 'Direct PO') ?></small>
                        </td>
                        <td><?= e(date('d M Y', strtotime($po['po_date']))) ?></td>
                        <td>
                            <strong><?= e($po['supplier_name'] ?: 'Unknown') ?></strong>
                            <?php if (!empty($po['supplier_phone'])): ?><br><small class="muted">📞 <?= e($po['supplier_phone']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <b><?= (int)($po['qty_ordered'] ?? 0) ?> units</b><br>
                            <small class="muted"><?= (int)($po['item_count'] ?? 0) ?> items</small>
                        </td>
                        <td>
                            <?php if ($isOverdue): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700">⚠️ OVERDUE</span><br>
                                <b style="color:#b91c1c;font-size:12px"><?= e(date('d M Y', strtotime($po['expected_delivery']))) ?></b>
                            <?php elseif (!empty($po['expected_delivery'])): ?>
                                <?= e(date('d M Y', strtotime($po['expected_delivery']))) ?>
                            <?php else: ?>
                                <span class="muted">Not scheduled</span>
                            <?php endif; ?>
                        </td>
                        <td><b>Rs. <?= number_format((float)$po['total_value'], 2) ?></b></td>
                        <td><?= pill($po['status']) ?></td>
                        <td>
                            <?php if ($po['status'] === 'Delivered'): ?>
                                <span style="color:#15803d;font-size:12px;font-weight:600">✓ Received in Store (GRN Posted)</span>
                            <?php elseif ($po['status'] === 'Partially Received'): ?>
                                <span style="color:#b45309;font-size:12px;font-weight:600">⏳ Partially Received (<?= (int)$po['qty_received'] ?>/<?= (int)$po['qty_ordered'] ?>)</span>
                            <?php elseif ($po['status'] === 'Approved'): ?>
                                <span style="color:#0284c7;font-size:12px;font-weight:600">🚚 Dispatched / Awaiting Delivery</span>
                            <?php else: ?>
                                <span class="muted" style="font-size:12px">Draft · Not authorized</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($po['status'] === 'Draft'): ?>
                            <form method="post" action="page.php?view=purchase_orders" style="display:inline">
                                <input type="hidden" name="action" value="approve_po">
                                <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                                <button class="button button-primary" type="submit" style="padding:4px 10px;font-size:11px">Approve PO</button>
                            </form>
                            <?php else: ?>
                            <a href="page.php?view=purchase_orders" class="button button-light" style="padding:4px 10px;font-size:11px;text-decoration:none">View PO</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- ── Panel 2: Store Reorder Queue (Immediate PO Triggers) ──── -->
    <div class="dashboard-grid lower-grid" style="margin-top:1.25rem" id="reorder-queue">
        <section class="panel table-panel" style="grid-column:1/-1;border-top:3px solid #f97316">
            <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div>
                    <p class="eyebrow" style="color:#c2410c">SOP SECTION 3 · STORE REPLENISHMENT</p>
                    <h2>Store Re-Order Queue (Safety Thresholds Breached)</h2>
                    <small class="muted">Items flagged by Main Store where stock is at or below minimum threshold. Raise Purchase Orders to replenish stock.</small>
                </div>
                <a href="page.php?view=purchase_orders#add-po" class="button button-primary" style="background:#ea580c;border-color:#ea580c;font-size:12px;padding:6px 14px">
                    🛒 Raise PO for Low Stock
                </a>
            </div>
            <div class="table-wrap">
                <?php if (empty($reorderItems)): ?>
                <div class="empty-state">
                    <span class="empty-icon">✓</span>
                    <p>All items in Main Store are currently above minimum safety stock levels.</p>
                    <small>No emergency reorders required right now.</small>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Item Code / Name</th>
                            <th>Category</th>
                            <th>Current Store Balance</th>
                            <th>Reorder Level</th>
                            <th>Min / Safety Level</th>
                            <th>Stock Deficit</th>
                            <th>Lead Time</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reorderItems as $item): ?>
                    <?php
                    $isZero = ((int)$item['quantity'] === 0);
                    $deficit = max(0, (int)$item['reorder_level'] - (int)$item['quantity']);
                    ?>
                    <tr style="<?= $isZero ? 'background:#fff1f2' : '' ?>">
                        <td>
                            <b><?= e($item['code']) ?></b><br>
                            <span style="font-weight:600;color:#1e293b"><?= e($item['name']) ?></span>
                        </td>
                        <td><?= e($item['category']) ?></td>
                        <td>
                            <strong style="color:<?= $isZero ? '#dc2626' : '#b45309' ?>;font-size:14px">
                                <?= (int)$item['quantity'] ?> <?= e($item['unit']) ?>
                            </strong>
                            <?php if ($isZero): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;margin-left:4px">OUT OF STOCK</span>
                            <?php endif; ?>
                        </td>
                        <td><b><?= (int)$item['reorder_level'] ?></b> <?= e($item['unit']) ?></td>
                        <td><?= (int)($item['min_level'] ?? 0) ?> <?= e($item['unit']) ?></td>
                        <td>
                            <span style="color:#dc2626;font-weight:700">Need +<?= $deficit ?> <?= e($item['unit']) ?></span>
                        </td>
                        <td><?= (int)($item['lead_time_days'] ?? 7) ?> days</td>
                        <td>
                            <a href="page.php?view=purchase_orders#add-po" class="button button-primary" style="padding:4px 10px;font-size:11px;background:#ea580c;border-color:#ea580c;text-decoration:none">
                                🛒 Raise PO
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- ── Panel 3: Store Inward GRN Custody Receipts (Read-Only) ── -->
    <div class="dashboard-grid lower-grid" style="margin-top:1.25rem">
        <section class="panel table-panel" style="grid-column:1/-1">
            <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div>
                    <p class="eyebrow">STORE CUSTODY VERIFICATION</p>
                    <h2>Store Inward Consignment Ledger (Recent GRNs)</h2>
                    <small class="muted">Material physically inspected, accepted, and posted into Store stock by Store Officers against Purchase Orders.</small>
                </div>
                <a href="page.php?view=reports&rtype=purchase" class="text-button" style="font-size:12px">Full PO vs GRN Report →</a>
            </div>
            <div class="table-wrap">
                <?php if (empty($recentGRNs)): ?>
                <div class="empty-state">
                    <span class="empty-icon">📋</span>
                    <p>No Goods Receipt Notes recorded by Store yet.</p>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>GRN Number</th>
                            <th>PO Reference</th>
                            <th>Supplier</th>
                            <th>Challan / DC No.</th>
                            <th>Accepted Qty</th>
                            <th>Rejected Qty</th>
                            <th>Received Date</th>
                            <th>Inspection Status</th>
                            <th>Custody Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentGRNs as $grn): ?>
                    <tr>
                        <td><b><?= e($grn['grn_number']) ?></b></td>
                        <td>
                            <?php if (!empty($grn['po_number'])): ?>
                                <b style="color:var(--brand-primary,#00897b)"><?= e($grn['po_number']) ?></b>
                            <?php else: ?>
                                <span class="muted">Direct Receipt</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($grn['supplier_name'] ?: '—') ?></td>
                        <td><?= e($grn['challan_number'] ?: '—') ?></td>
                        <td style="color:#15803d;font-weight:700">+<?= (int)$grn['total_accepted'] ?></td>
                        <td style="color:<?= (int)$grn['total_rejected'] > 0 ? '#dc2626' : '#64748b' ?>;font-weight:<?= (int)$grn['total_rejected'] > 0 ? '700' : 'normal' ?>">
                            <?= (int)$grn['total_rejected'] ?>
                        </td>
                        <td><?= e(date('d M Y', strtotime($grn['received_date']))) ?></td>
                        <td><?= pill($grn['inspection_status']) ?></td>
                        <td>
                            <span class="badge" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">
                                Posted to Main Store Stock
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

<?php elseif ($isStoreOfficer): ?>
    <!-- ═════════════════════════════════════════════════════════════════
         STORE OPERATIONS COMMAND CENTER (STORE OFFICER DASHBOARD)
         Conforms to SIOVS Requirements Sections 4, 6, 8, 14, 29 & 31
         ═════════════════════════════════════════════════════════════════ -->

    <!-- ── Hero Banner ─────────────────────────────────────────────── -->
    <div class="hero hero-expanded" style="background:linear-gradient(135deg, #064e3b 0%, #047857 50%, #059669 100%)">
        <div class="hero-right">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap">
                <p class="eyebrow" id="hero-date" style="margin:0"><?= strtoupper(date('l, j F Y')) ?></p>
                <span style="background:rgba(255,255,255,0.18);color:#e8f37c;font-size:11px;font-weight:700;letter-spacing:1px;padding:3px 10px;border-radius:12px;text-transform:uppercase">Central Store &amp; Inventory Management Section</span>
            </div>
            <h1 class="hero-greeting">Store Operations Command Center</h1>
            <p class="hero-copy" style="font-size:13px;max-width:720px;line-height:1.45">
                Logged in as <b><?= e($u['name'] ?? 'Store Officer') ?></b>. Central Store custody, physical receipt inspection (GRN), FEFO department issuance, and batch lifecycle tracking.
                <span id="live-clock" style="font-weight:700;color:#e8f37c;font-size:14px;margin-left:8px"></span>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                <a href="page.php?view=grn#add-grn" class="button" style="background:#10b981;color:#fff;border:none;font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">📦 Generate GRN</a>
                <a href="page.php?view=requisitions" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">⌁ Department Requisitions<?php if (!empty($pendingReqs)): ?> <b style="background:#059669;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px"><?= $pendingReqs ?></b><?php endif; ?></a>
                <a href="page.php?view=returns" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">↻ Verify Returns<?php if (!empty($pendingReturns)): ?> <b style="background:#6366f1;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px"><?= $pendingReturns ?></b><?php endif; ?></a>
                <a href="page.php?view=batches&filter=30" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">◷ Near Expiry (<?= $nearExpiry30 ?>)</a>
                <a href="page.php?view=verification" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">✓ Stock Verification</a>
                <a href="page.php?view=reports&rtype=stock" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">▥ Stock Ledger</a>
            </div>
        </div>
        <div class="hero-decoration"><span></span><span></span><span></span></div>
    </div>

    <!-- ── Regulatory Compliance Banner (Section 4, 6, 8 & 29) ───────── -->
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-left:4px solid #059669;border-radius:8px;padding:12px 16px;margin:1rem 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
            <span style="font-size:24px">🏢</span>
            <div style="font-size:12px;color:#065f46;line-height:1.45">
                <strong style="font-size:13px;display:block;margin-bottom:2px">SIOVS SOP Section 4, 6, 8 &amp; 29 Store Custody &amp; Accountability:</strong>
                The Central Store Officer holds physical custody of stock. Stock is incremented strictly via inspected GRNs against POs and Delivery Challans. Internal issues to OPD, IPD, and OT are released strictly against approved departmental requisitions using First-Expiry-First-Out (FEFO) batch allocation.
            </div>
        </div>
        <span style="font-size:11px;font-weight:700;background:#d1fae5;color:#047857;padding:4px 10px;border-radius:6px;white-space:nowrap;border:1px solid #6ee7b7">
            ✓ Physical Custody &amp; Audit Compliant
        </span>
    </div>

    <!-- ── KPI Row 1: Central Store Operations ───────────────────────── -->
    <div class="section-heading">
        <div><p class="eyebrow">STORE OPERATIONS</p><h2>Inventory at a glance</h2></div>
        <a href="page.php?view=reports&rtype=stock" class="text-button" style="font-size:12px">View Full Stock Ledger →</a>
    </div>
    <div class="metric-grid four-col" style="grid-template-columns:repeat(4, 1fr);gap:14px;margin-bottom:1.25rem">
        <!-- Card 1: Main Store Stock Valuation -->
        <article class="metric-card kpi-card" data-link="page.php?view=inventory" style="background:linear-gradient(135deg, #064e3b 0%, #065f46 55%, #047857 100%);box-shadow:0 10px 28px rgba(6,78,59,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="30" y="25" width="100" height="75" rx="6" fill="white" opacity=".18" stroke="white" stroke-width="2"/>
                    <line x1="45" y1="50" x2="115" y2="50" stroke="white" stroke-width="2" opacity=".4"/>
                    <line x1="45" y1="70" x2="95" y2="70" stroke="white" stroke-width="2" opacity=".4"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Main Store Valuation</span><span class="trend up">PKR</span></div>
                <strong style="font-size:22px">Rs. <?= number_format($stockValue, 0) ?></strong>
                <small><?= $totalItems ?> items · <?= $activeBatches ?> active batches</small>
            </div>
        </article>

        <!-- Card 2: Incoming POs Awaiting GRN -->
        <article class="metric-card kpi-card" data-link="page.php?view=purchase_orders" style="background:linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #3b82f6 100%);box-shadow:0 10px 28px rgba(37,99,235,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M40 75L55 45H105L120 75H40Z" fill="white" opacity=".2" stroke="white" stroke-width="2"/>
                    <circle cx="60" cy="85" r="8" fill="white" opacity=".4"/>
                    <circle cx="100" cy="85" r="8" fill="white" opacity=".4"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Incoming Deliveries</span><?php if ($incomingPOs > 0): ?><span class="badge" style="background:rgba(255,255,255,.2);color:#fff"><?= $incomingPOs ?> Awaiting</span><?php endif; ?></div>
                <strong style="font-size:22px"><?= $incomingPOs ?></strong>
                <small><?= $pendingGRNs ?> GRN pending inspection</small>
            </div>
        </article>

        <!-- Card 3: Approved Requisitions Awaiting Issue -->
        <article class="metric-card kpi-card" data-link="page.php?view=requisitions" style="background:linear-gradient(135deg, #134e4a 0%, #0d9488 55%, #14b8a6 100%);box-shadow:0 10px 28px rgba(13,148,136,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="40" y="30" width="80" height="60" rx="4" fill="white" opacity=".2" stroke="white" stroke-width="2"/>
                    <path d="M55 60L70 75L105 45" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".5"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Requisitions to Issue</span><?php if ($pendingReqs > 0): ?><span class="badge" style="background:rgba(255,255,255,.2);color:#fff"><?= $pendingReqs ?> Ready</span><?php endif; ?></div>
                <strong style="font-size:22px"><?= $pendingReqs ?></strong>
                <small><?= $pendingReturns ?> department returns to verify</small>
            </div>
        </article>

        <!-- Card 4: FEFO Expiry & Stock Safety -->
        <article class="metric-card kpi-card" data-link="page.php?view=batches" style="background:linear-gradient(135deg, #78350f 0%, #d97706 55%, #f59e0b 100%);box-shadow:0 10px 28px rgba(217,119,6,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="80" cy="60" r="30" stroke="white" stroke-width="2" opacity=".3"/>
                    <line x1="80" y1="40" x2="80" y2="60" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".6"/>
                    <line x1="80" y1="60" x2="95" y2="60" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".6"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Near Expiry (&le;30d)</span><?php if ($nearExpiry30 > 0): ?><span class="badge" style="background:#dc2626;color:#fff"><?= $nearExpiry30 ?> Urgent</span><?php endif; ?></div>
                <strong style="font-size:22px"><?= $nearExpiry30 ?></strong>
                <small><?= $criticalStock ?> out of stock · <?= $lowStock ?> below reorder</small>
            </div>
        </article>
    </div>

    <!-- ── Two-Column Operational Command Center ────────────────────────── -->
    <div style="display:grid;grid-template-columns:1.15fr 0.85fr;gap:20px;align-items:start">
        <!-- LEFT COLUMN: Inward Deliveries & Outward Issues -->
        <div style="display:flex;flex-direction:column;gap:20px">

            <!-- Panel 1: Incoming Vendor Deliveries Awaiting GRN -->
            <section class="panel" id="incoming-deliveries">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <p class="eyebrow" style="color:var(--brand-primary,#00897b);margin-bottom:2px">INWARD MATERIAL</p>
                        <h2 style="font-size:15px;margin:0">Approved Purchase Orders Awaiting GRN Receiving</h2>
                    </div>
                    <a href="page.php?view=grn#add-grn" class="button button-primary" style="padding:4px 12px;font-size:11px;text-decoration:none">＋ Generate GRN</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($incomingPOList)): ?>
                    <div style="text-align:center;padding:2rem;color:#6b7280">
                        <span style="font-size:2rem;display:block;margin-bottom:.5rem">📦</span>
                        <strong>No incoming deliveries awaiting GRN receiving</strong>
                        <p style="font-size:12px;margin-top:4px">All approved purchase orders have been processed into Main Store stock.</p>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Expected Date</th>
                                <th>Ordered Qty</th>
                                <th>Value (PKR)</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($incomingPOList as $po):
                            $isOverdue = (!empty($po['expected_delivery']) && $po['expected_delivery'] < $today);
                        ?>
                        <tr>
                            <td>
                                <b><?= e($po['po_number']) ?></b><br>
                                <small class="muted"><?= e($po['purchase_reference'] ?: 'Direct Purchase') ?></small>
                            </td>
                            <td>
                                <strong><?= e($po['supplier_name'] ?: 'Unknown') ?></strong>
                                <?php if (!empty($po['supplier_phone'])): ?><br><small class="muted">📞 <?= e($po['supplier_phone']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isOverdue): ?>
                                    <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700">⚠️ OVERDUE</span><br>
                                    <b style="color:#b91c1c;font-size:12px"><?= e(date('d M Y', strtotime($po['expected_delivery']))) ?></b>
                                <?php elseif (!empty($po['expected_delivery'])): ?>
                                    <?= e(date('d M Y', strtotime($po['expected_delivery']))) ?>
                                <?php else: ?>
                                    <span class="muted">Not scheduled</span>
                                <?php endif; ?>
                            </td>
                            <td><b><?= (int)$po['qty_ordered'] ?></b> items</td>
                            <td><b>Rs. <?= number_format((float)$po['total_value'], 2) ?></b></td>
                            <td>
                                <a href="<?= page_link('grn') ?>&po_id=<?= $po['id'] ?>#add-grn" class="button button-primary" style="padding:4px 10px;font-size:11px;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                                    <span>📦</span> Generate GRN
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Panel 2: Department Requisitions Awaiting Store Issue -->
            <section class="panel" id="requisitions-issue">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <p class="eyebrow" style="color:#059669;margin-bottom:2px">OUTWARD MATERIAL (FEFO)</p>
                        <h2 style="font-size:15px;margin:0">Approved Department Requisitions Ready for Issue</h2>
                    </div>
                    <a href="page.php?view=requisitions" class="button button-light" style="padding:4px 12px;font-size:11px;text-decoration:none">View All Requisitions →</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($approvedReqList)): ?>
                    <div style="text-align:center;padding:2rem;color:#6b7280">
                        <span style="font-size:2rem;display:block;margin-bottom:.5rem">⌁</span>
                        <strong>No approved requisitions awaiting issue</strong>
                        <p style="font-size:12px;margin-top:4px">All department requests have been fulfilled and dispatched.</p>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Req. No</th>
                                <th>Department</th>
                                <th>Purpose</th>
                                <th>Requested Qty</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($approvedReqList as $req): ?>
                        <tr>
                            <td><b><?= e($req['req_number']) ?></b><br><small class="muted"><?= e(date('d M Y', strtotime($req['req_date']))) ?></small></td>
                            <td><b style="color:#065f46"><?= e($req['dept_name']) ?></b></td>
                            <td><?= e($req['purpose'] ?: 'Clinical consumption') ?></td>
                            <td><b><?= (int)$req['total_qty'] ?></b> items</td>
                            <td><span class="badge" style="background:#dbeafe;color:#1e40af;font-size:11px">Approved</span></td>
                            <td>
                                <form method="post" action="page.php?view=requisitions" style="display:inline">
                                    <input type="hidden" name="action" value="issue_requisition">
                                    <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                                    <button class="button button-primary" type="submit" style="padding:4px 10px;font-size:11px;background:#059669;border-color:#047857" onclick="return confirm('Confirm issue of items from Main Store to <?= e($req['dept_name']) ?> via FEFO?')">
                                        🚀 Issue (FEFO)
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <!-- RIGHT COLUMN: Quality / FEFO Watchlist & Ledger Feed -->
        <div style="display:flex;flex-direction:column;gap:20px">

            <!-- Panel 3: FEFO Batch Expiry Alert Watchlist -->
            <section class="panel" id="fefo-watchlist">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <p class="eyebrow" style="color:#d97706;margin-bottom:2px">FEFO QUALITY WATCH</p>
                        <h2 style="font-size:15px;margin:0">Active Batches Nearing Expiry</h2>
                    </div>
                    <a href="page.php?view=batches&filter=30" class="text-button" style="font-size:12px">View All Batches →</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($fefoBatches)): ?>
                    <div style="text-align:center;padding:2rem;color:#6b7280">
                        <span style="font-size:2rem;display:block;margin-bottom:.5rem">✓</span>
                        <strong>No near-expiry batches detected</strong>
                        <p style="font-size:12px;margin-top:4px">All items in Central Store have adequate shelf life.</p>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Item &amp; Batch</th>
                                <th>Expiry Date</th>
                                <th>Store Stock</th>
                                <th>FEFO Priority</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fefoBatches as $b):
                            $daysLeft = (int)$b['days_left'];
                            $badgeColor = ($daysLeft <= 30) ? 'background:#fee2e2;color:#991b1b;border:1px solid #f87171' : (($daysLeft <= 90) ? 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d' : 'background:#e0f2fe;color:#0369a1');
                        ?>
                        <tr>
                            <td>
                                <b><?= e($b['item_name']) ?></b><br>
                                <small class="muted">Batch: <code><?= e($b['batch_number'] ?: 'N/A') ?></code></small>
                            </td>
                            <td><?= e(date('d M Y', strtotime($b['expiry_date']))) ?></td>
                            <td><b><?= (int)$b['quantity'] ?></b> <?= e($b['unit']) ?></td>
                            <td>
                                <span class="badge" style="<?= $badgeColor ?>;font-size:10px;font-weight:700">
                                    <?= $daysLeft <= 0 ? 'EXPIRED' : "{$daysLeft}d left" ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Panel 4: Live Store Movement Ledger Activity -->
            <section class="panel" id="recent-store-activity">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <p class="eyebrow" style="color:var(--brand-primary,#00897b);margin-bottom:2px">AUDIT TRAIL</p>
                        <h2 style="font-size:15px;margin:0">Recent Store Movements</h2>
                    </div>
                    <a href="page.php?view=reports&rtype=stock" class="text-button" style="font-size:12px">Full Ledger →</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($recentTx)): ?>
                    <div style="text-align:center;padding:1.5rem;color:#6b7280">
                        <small>No recent stock movement entries recorded yet.</small>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Movement</th>
                                <th>Reference</th>
                                <th>Qty</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentTx as $t): ?>
                        <tr>
                            <td><?= pill($t['type']) ?></td>
                            <td>
                                <b><?= e($t['reference'] ?? '') ?></b><br>
                                <small class="muted"><?= e($t['party'] ?? '') ?></small>
                            </td>
                            <td><b><?= (int)($t['quantity'] ?? 0) ?></b></td>
                            <td><small><?= e(date('d M H:i', strtotime($t['created_at'] ?? 'now'))) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

<?php elseif ($isDDFinance): ?>
    <!-- ═════════════════════════════════════════════════════════════════
         FINANCE & AUDIT COMMAND CENTER (DD FINANCE DASHBOARD)
         Conforms to SIOVS Requirements Sections 27, 28, 29 & 31
         ═════════════════════════════════════════════════════════════════ -->

    <!-- ── Hero Banner ─────────────────────────────────────────────── -->
    <div class="hero hero-expanded" style="background:linear-gradient(135deg, #0f172a 0%, #0f766e 50%, #115e59 100%)">
        <div class="hero-right">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap">
                <p class="eyebrow" id="hero-date" style="margin:0"><?= strtoupper(date('l, j F Y')) ?></p>
                <span style="background:rgba(255,255,255,0.18);color:#5eead4;font-size:11px;font-weight:700;letter-spacing:1px;padding:3px 10px;border-radius:12px;text-transform:uppercase">DEPARTMENT OF FINANCE &amp;<br>INTERNAL AUDIT CONTROL</span>
            </div>
            <h1 class="hero-greeting">Finance &amp; Audit Command Center</h1>
            <p class="hero-copy" style="font-size:13px;max-width:720px;line-height:1.45">
                Logged in as <b><?= e($u['name'] ?? 'Deputy Director Finance') ?></b>. Financial authorizations, stock write-offs, deletion request audits, capital asset oversight, and statutory compliance.
                <span id="live-clock" style="font-weight:700;color:#5eead4;font-size:14px;margin-left:8px"></span>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                <a href="page.php?view=adjustments" class="button" style="background:#0d9488;color:#fff;border:none;font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">⚖️ Review Stock Adjustments<?php if ($pendingAdj > 0): ?> <b style="background:#f59e0b;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px"><?= $pendingAdj ?></b><?php endif; ?></a>
                <a href="page.php?view=deletions" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">⊘ Deletion Queue<?php if ($pendingDeletions > 0): ?> <b style="background:#dc2626;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px"><?= $pendingDeletions ?></b><?php endif; ?></a>
                <a href="page.php?view=verification" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">✓ Physical Audits<?php if ($pendingVer > 0): ?> <b style="background:#0284c7;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px"><?= $pendingVer ?></b><?php endif; ?></a>
                <a href="page.php?view=assets" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">◫ Fixed Assets</a>
                <a href="page.php?view=reports&rtype=valuation" class="button button-light" style="font-size:12px;padding:6px 14px;border-radius:6px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">▥ Valuation Report</a>
            </div>
        </div>
        <div class="hero-decoration"><span></span><span></span><span></span></div>
    </div>

    <!-- ── Statutory Governance Banner ──────────────────────────────── -->
    <div style="background:#f0fdfa;border:1px solid #99f6e4;border-left:4px solid #0d9488;border-radius:8px;padding:12px 16px;margin:1rem 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
            <span style="font-size:24px">⚖️</span>
            <div style="font-size:12px;color:#134e4a;line-height:1.45">
                <strong style="font-size:13px;display:block;margin-bottom:2px">SIOVS SOP Section 27, 28 &amp; 29 Financial Controls:</strong>
                All inventory write-offs, physical verification shortages/excesses, and record deletion rollbacks require explicit authorization by the Deputy Director Finance. The stock ledger is updated only upon executive approval.
            </div>
        </div>
        <span style="font-size:11px;font-weight:700;background:#ccfbf1;color:#0f766e;padding:4px 10px;border-radius:6px;white-space:nowrap;border:1px solid #5eead4">
            ✓ Statutory Financial Control
        </span>
    </div>

    <!-- ── KPI Row: Financial Valuation & Authorization Exposure ───── -->
    <div class="section-heading">
        <div><p class="eyebrow">FINANCIAL EXPOSURE &amp; CAPITALIZATION</p><h2>Hospital Inventory &amp; Asset Valuation</h2></div>
        <a href="page.php?view=reports&rtype=valuation" class="text-button" style="font-size:12px">View Full Financial Report →</a>
    </div>
    <div class="metric-grid four-col" style="grid-template-columns:repeat(4, 1fr);gap:14px;margin-bottom:1.25rem">
        <!-- Card 1: Central Store Valuation -->
        <article class="metric-card kpi-card" data-link="page.php?view=reports&rtype=valuation" style="background:linear-gradient(135deg, #0f766e 0%, #0d9488 55%, #14b8a6 100%);box-shadow:0 10px 28px rgba(13,148,136,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="30" y="25" width="100" height="75" rx="6" fill="white" opacity=".18" stroke="white" stroke-width="2"/>
                    <line x1="45" y1="50" x2="115" y2="50" stroke="white" stroke-width="2" opacity=".4"/>
                    <line x1="45" y1="70" x2="95" y2="70" stroke="white" stroke-width="2" opacity=".4"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Central Store Valuation</span><span class="trend up">PKR</span></div>
                <strong style="font-size:22px">Rs. <?= number_format($stockValue, 0) ?></strong>
                <small>Liquid inventory at weighted cost</small>
            </div>
        </article>

        <!-- Card 2: Fixed Assets Capitalization -->
        <article class="metric-card kpi-card" data-link="page.php?view=assets" style="background:linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #3b82f6 100%);box-shadow:0 10px 28px rgba(37,99,235,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="40" y="30" width="80" height="60" rx="4" fill="white" opacity=".2" stroke="white" stroke-width="2"/>
                    <circle cx="80" cy="60" r="16" fill="white" opacity=".3"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Fixed Asset Capitalization</span><span class="trend up">PKR</span></div>
                <strong style="font-size:22px">Rs. <?= number_format($totalAssetVal, 0) ?></strong>
                <small>Hospital ophthalmic &amp; biomed assets</small>
            </div>
        </article>

        <!-- Card 3: Pending Stock Adjustments -->
        <article class="metric-card kpi-card" data-link="page.php?view=adjustments" style="background:linear-gradient(135deg, #78350f 0%, #d97706 55%, #f59e0b 100%);box-shadow:0 10px 28px rgba(217,119,6,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="80" cy="60" r="30" stroke="white" stroke-width="2" opacity=".3"/>
                    <line x1="80" y1="40" x2="80" y2="60" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".6"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Pending Adjustments</span><?php if ($pendingAdj > 0): ?><span class="badge" style="background:#dc2626;color:#fff"><?= $pendingAdj ?> Action</span><?php endif; ?></div>
                <strong style="font-size:22px"><?= $pendingAdj ?></strong>
                <small>Write-offs &amp; audit discrepancy items</small>
            </div>
        </article>

        <!-- Card 4: Deletion & Rollback Requests -->
        <article class="metric-card kpi-card" data-link="page.php?view=deletions" style="background:linear-gradient(135deg, #831843 0%, #be185d 55%, #ec4899 100%);box-shadow:0 10px 28px rgba(190,24,93,.35);cursor:pointer">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="80" cy="60" r="28" stroke="white" stroke-width="2" opacity=".4"/>
                    <line x1="60" y1="40" x2="100" y2="80" stroke="white" stroke-width="3" opacity=".6"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Deletion Requests</span><?php if ($pendingDeletions > 0): ?><span class="badge" style="background:#dc2626;color:#fff"><?= $pendingDeletions ?> Pending</span><?php endif; ?></div>
                <strong style="font-size:22px"><?= $pendingDeletions ?></strong>
                <small>Restricted stock ledger reversals</small>
            </div>
        </article>
    </div>

    <!-- ── Action Panels Grid: 2 Columns ───────────────────────────── -->
    <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:1.25rem;margin-top:1.5rem">
        <!-- Panel 1: Stock Adjustments Authorization Queue -->
        <section class="panel" id="adj-queue">
            <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <p class="eyebrow" style="color:#d97706;margin-bottom:2px">ACTION REQUIRED</p>
                    <h2 style="font-size:15px;margin:0">Stock Adjustments Awaiting Authorization</h2>
                </div>
                <a href="page.php?view=adjustments" class="text-button" style="font-size:12px">View All Adjustments →</a>
            </div>
            <div class="table-wrap">
                <?php if (empty($pendingAdjList)): ?>
                <div style="text-align:center;padding:2.5rem;color:#6b7280">
                    <span style="font-size:2rem;display:block;margin-bottom:.5rem">✓</span>
                    <strong>Zero Pending Adjustments</strong>
                    <p style="font-size:12px;margin-top:4px">All store adjustments and audit variances have been processed.</p>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Adjustment # / Item</th>
                            <th>Qty / Direction</th>
                            <th>Reason &amp; Requester</th>
                            <th style="text-align:right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingAdjList as $adj): ?>
                    <tr>
                        <td>
                            <b><?= e($adj['adj_number']) ?></b><br>
                            <span class="muted" style="font-size:11px"><?= e($adj['item_code']) ?> — <?= e($adj['item_name']) ?></span>
                        </td>
                        <td>
                            <strong style="color:<?= $adj['quantity'] < 0 ? '#dc2626' : '#16a34a' ?>">
                                <?= $adj['quantity'] > 0 ? '+' : '' ?><?= (int)$adj['quantity'] ?> <?= e($adj['item_unit']) ?>
                            </strong>
                        </td>
                        <td>
                            <b><?= e($adj['reason']) ?></b><br>
                            <small class="muted">By: <?= e($adj['requester_name'] ?? 'Store Staff') ?></small>
                        </td>
                        <td style="text-align:right">
                            <a href="page.php?view=adjustments" class="button button-primary" style="padding:4px 10px;font-size:11px;background:#0d9488;border-color:#0f766e;text-decoration:none" title="Review and authorize on Stock Adjustments register">Review →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>

        <!-- Right Side: Deletions & Audits -->
        <div style="display:flex;flex-direction:column;gap:1.25rem">
            <!-- Panel 2: Deletion Requests Queue -->
            <section class="panel" id="del-queue">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <p class="eyebrow" style="color:#be185d;margin-bottom:2px">AUDIT COMPLIANCE</p>
                        <h2 style="font-size:15px;margin:0">Pending Deletion Requests</h2>
                    </div>
                    <a href="page.php?view=deletions" class="text-button" style="font-size:12px">View Queue →</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($pendingDelList)): ?>
                    <div style="text-align:center;padding:1.5rem;color:#6b7280">
                        <small>No pending deletion requests requiring review.</small>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Request / Target</th>
                                <th>Reason</th>
                                <th style="text-align:right">Decision</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pendingDelList as $del): ?>
                        <tr>
                            <td>
                                <b><?= e($del['request_no']) ?></b><br>
                                <small class="muted"><?= e($del['section']) ?> · <code><?= e($del['reference_no']) ?></code></small>
                            </td>
                            <td>
                                <b><?= e($del['reason']) ?></b><br>
                                <small class="muted">By: <?= e($del['requester_name'] ?? 'Staff') ?></small>
                            </td>
                            <td style="text-align:right">
                                <a href="page.php?view=deletions" class="button button-light" style="padding:3px 8px;font-size:11px">Review →</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Panel 3: Physical Stocktaking Audits -->
            <section class="panel" id="audits-queue">
                <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <p class="eyebrow" style="color:#0284c7;margin-bottom:2px">BOARD OF SURVEY</p>
                        <h2 style="font-size:15px;margin:0">Recent Physical Verifications</h2>
                    </div>
                    <a href="page.php?view=verification" class="text-button" style="font-size:12px">Verifications Register →</a>
                </div>
                <div class="table-wrap">
                    <?php if (empty($recentAudits)): ?>
                    <div style="text-align:center;padding:1.5rem;color:#6b7280">
                        <small>No physical stock verifications conducted yet.</small>
                    </div>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Audit # / Date</th>
                                <th>Status</th>
                                <th>Variances</th>
                                <th style="text-align:right">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentAudits as $a): ?>
                        <tr>
                            <td>
                                <b><?= e($a['ver_number']) ?></b><br>
                                <small class="muted"><?= e(date('d M Y', strtotime($a['verification_date']))) ?></small>
                            </td>
                            <td><?= pill($a['status']) ?></td>
                            <td>
                                <?php if ((int)$a['discrepancy_count'] === 0): ?>
                                    <span style="color:#166534;font-size:11px;font-weight:600">✓ 0 Variances</span>
                                <?php else: ?>
                                    <span style="color:#b91c1c;font-size:11px;font-weight:700">⚠️ <?= (int)$a['discrepancy_count'] ?> Variances</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right">
                                <a href="page.php?view=verification&view_ver=<?= $a['id'] ?>#view-ver-details" class="button button-light" style="padding:3px 8px;font-size:11px">👁️ View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

<?php else: ?>
    <!-- ── Hero banner ───────────────────────────────────────────────── -->
    <div class="hero hero-expanded">
        <div class="hero-right">
            <p class="eyebrow" id="hero-date"><?= strtoupper(date('l, j F Y')) ?></p>
            <h1 class="hero-greeting" id="hero-greeting">Good day, <?= e($firstName) ?></h1>
            <p class="hero-copy">Your complete view from purchase to patient.
                <span id="live-clock" style="font-weight:700;color:#e8f37c;font-size:15px"></span>
            </p>
        </div>
        <div class="hero-decoration"><span></span><span></span><span></span></div>
    </div>

    <!-- ── KPI Row 1: Inventory ──────────────────────────────────────── -->
    <div class="section-heading">
        <div><p class="eyebrow">LIVE SNAPSHOT</p><h2>Inventory at a glance</h2></div>
        <button class="button button-light" id="refresh-kpi" title="Refresh KPIs" style="font-size:11px;padding:7px 13px">↻ Refresh</button>
    </div>
    <div class="metric-grid" id="kpi-row-1">

        <!-- Card 1: Inventory / Stock units -->
        <article class="metric-card kpi-card kpi-inventory" data-link="<?= page_link('inventory') ?>" data-spark='<?= $sparkJson ?>' style="--delay:0">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Warehouse shelves with boxes -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Shelves -->
                    <rect x="10" y="95" width="140" height="5" rx="2" fill="white" opacity=".25"/>
                    <rect x="10" y="60" width="140" height="5" rx="2" fill="white" opacity=".25"/>
                    <rect x="10" y="25" width="140" height="5" rx="2" fill="white" opacity=".25"/>
                    <!-- Vertical supports -->
                    <rect x="10" y="25" width="5" height="75" rx="2" fill="white" opacity=".18"/>
                    <rect x="145" y="25" width="5" height="75" rx="2" fill="white" opacity=".18"/>
                    <!-- Bottom row boxes -->
                    <rect x="18" y="72" width="28" height="23" rx="3" fill="white" opacity=".35"/>
                    <rect x="20" y="74" width="24" height="5" rx="1" fill="white" opacity=".3"/>
                    <rect x="50" y="72" width="28" height="23" rx="3" fill="white" opacity=".28"/>
                    <rect x="82" y="72" width="28" height="23" rx="3" fill="white" opacity=".35"/>
                    <rect x="114" y="72" width="28" height="23" rx="3" fill="white" opacity=".28"/>
                    <!-- Middle row boxes -->
                    <rect x="18" y="37" width="22" height="22" rx="3" fill="white" opacity=".28"/>
                    <rect x="44" y="37" width="22" height="22" rx="3" fill="white" opacity=".35"/>
                    <rect x="44" y="39" width="22" height="5" rx="1" fill="white" opacity=".3"/>
                    <rect x="70" y="37" width="22" height="22" rx="3" fill="white" opacity=".22"/>
                    <rect x="96" y="37" width="22" height="22" rx="3" fill="white" opacity=".35"/>
                    <rect x="122" y="37" width="22" height="22" rx="3" fill="white" opacity=".28"/>
                    <!-- Top row -->
                    <rect x="18" y="10" width="18" height="14" rx="3" fill="white" opacity=".22"/>
                    <rect x="40" y="10" width="18" height="14" rx="3" fill="white" opacity=".3"/>
                    <rect x="62" y="10" width="18" height="14" rx="3" fill="white" opacity=".22"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Available stock units</span>
                    <span class="trend up">↗ <?= $totalItems ?> items</span>
                </div>
                <strong><?= number_format((int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM items')->fetchColumn()) ?></strong>
                <small>Across <?= $totalItems ?> item codes</small>
            </div>
            <div class="sparkline" id="spark-0"></div>
        </article>

        <!-- Card 2: Low / reorder stock -->
        <article class="metric-card kpi-card <?= $lowStock > 0 ? 'kpi-lowstock-warn' : 'kpi-lowstock-ok' ?>" data-link="<?= page_link('inventory') ?>" style="--delay:1">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Medicine bottle with low liquid -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Bottle body -->
                    <rect x="55" y="35" width="50" height="72" rx="12" fill="white" opacity=".2"/>
                    <rect x="55" y="35" width="50" height="72" rx="12" stroke="white" stroke-width="2.5" opacity=".4"/>
                    <!-- Bottle neck -->
                    <rect x="67" y="18" width="26" height="22" rx="5" fill="white" opacity=".25"/>
                    <rect x="67" y="18" width="26" height="22" rx="5" stroke="white" stroke-width="2" opacity=".4"/>
                    <!-- Cap -->
                    <rect x="63" y="10" width="34" height="14" rx="4" fill="white" opacity=".45"/>
                    <!-- Low level indicator -->
                    <rect x="57" y="82" width="46" height="23" rx="10" fill="white" opacity=".35"/>
                    <!-- Down arrow -->
                    <path d="M80 52 L80 72 M74 66 L80 72 L86 66" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" opacity=".6"/>
                    <!-- Warning marks -->
                    <circle cx="125" cy="30" r="14" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <path d="M125 24 L125 32" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".7"/>
                    <circle cx="125" cy="36" r="2" fill="white" opacity=".7"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Low / reorder stock</span>
                    <span class="trend <?= $lowStock > 0 ? 'down' : 'up' ?>"><?= $lowStock > 0 ? 'Needs action' : 'All OK' ?></span>
                </div>
                <strong><?= $lowStock ?></strong>
                <small><?= $criticalStock ?> items at zero stock</small>
            </div>
            <div class="sparkline" id="spark-1"></div>
        </article>

        <!-- Card 3: Near-expiry -->
        <article class="metric-card kpi-card <?= ($nearExpiry30 + $expiredItems) > 0 ? 'kpi-expiry-warn' : 'kpi-expiry-ok' ?>" data-link="<?= page_link('batches') ?>" style="--delay:2">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Capsule pill + clock/calendar -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Large capsule -->
                    <rect x="22" y="48" width="82" height="34" rx="17" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".35"/>
                    <!-- Capsule divider -->
                    <line x1="63" y1="48" x2="63" y2="82" stroke="white" stroke-width="2" opacity=".4"/>
                    <!-- Left half fill -->
                    <path d="M22 65 Q22 48 39 48 L63 48 L63 82 L39 82 Q22 82 22 65Z" fill="white" opacity=".3"/>
                    <!-- Small pills -->
                    <ellipse cx="130" cy="55" rx="14" ry="8" fill="white" opacity=".2" stroke="white" stroke-width="1.5" opacity=".35"/>
                    <line x1="130" y1="47" x2="130" y2="63" stroke="white" stroke-width="1.5" opacity=".35"/>
                    <ellipse cx="130" cy="80" rx="14" ry="8" fill="white" opacity=".25" stroke="white" stroke-width="1.5" opacity=".35"/>
                    <!-- Clock face -->
                    <circle cx="38" cy="25" r="16" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".4"/>
                    <!-- Clock hands -->
                    <line x1="38" y1="25" x2="38" y2="14" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".7"/>
                    <line x1="38" y1="25" x2="46" y2="25" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".7"/>
                    <!-- Clock tick marks -->
                    <line x1="38" y1="11" x2="38" y2="14" stroke="white" stroke-width="2" opacity=".5"/>
                    <line x1="38" y1="36" x2="38" y2="39" stroke="white" stroke-width="2" opacity=".5"/>
                    <line x1="24" y1="25" x2="27" y2="25" stroke="white" stroke-width="2" opacity=".5"/>
                    <line x1="49" y1="25" x2="52" y2="25" stroke="white" stroke-width="2" opacity=".5"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Near-expiry (30 days)</span>
                    <span class="trend down"><?= $expiredItems ?> expired</span>
                </div>
                <strong><?= $nearExpiry30 ?></strong>
                <small>Review FEFO allocation</small>
            </div>
            <div class="sparkline" id="spark-2"></div>
        </article>

        <!-- Card 4: Purchase orders -->
        <article class="metric-card kpi-card kpi-purchase" data-link="<?= page_link('purchase_orders') ?>" style="--delay:3">
            <div class="kpi-illustration" aria-hidden="true">
                <!-- Clipboard with order list -->
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Clipboard board -->
                    <rect x="35" y="20" width="90" height="90" rx="8" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".35"/>
                    <!-- Clipboard top clip -->
                    <rect x="60" y="13" width="40" height="18" rx="5" fill="white" opacity=".35"/>
                    <rect x="68" y="10" width="24" height="12" rx="3" fill="white" opacity=".5"/>
                    <!-- List lines -->
                    <line x1="50" y1="48" x2="115" y2="48" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <line x1="50" y1="62" x2="115" y2="62" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <line x1="50" y1="76" x2="95" y2="76" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <!-- Checkboxes -->
                    <rect x="42" y="42" width="8" height="8" rx="2" fill="white" opacity=".5"/>
                    <path d="M43.5 46 L45 47.5 L48 44" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>
                    <rect x="42" y="56" width="8" height="8" rx="2" fill="white" opacity=".5"/>
                    <path d="M43.5 60 L45 61.5 L48 58" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>
                    <rect x="42" y="70" width="8" height="8" rx="2" fill="white" opacity=".35"/>
                    <!-- Pen -->
                    <path d="M110 80 L125 65 L130 70 L115 85 Z" fill="white" opacity=".35"/>
                    <path d="M125 65 L129 62 L132 65 L130 70 Z" fill="white" opacity=".5"/>
                    <line x1="110" y1="80" x2="108" y2="88" stroke="white" stroke-width="1.5" stroke-linecap="round" opacity=".4"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top">
                    <span class="kpi-label">Purchase orders</span>
                    <span class="trend neutral"><?= $openPOs ?> open</span>
                </div>
                <strong><?= $totalPOs ?></strong>
                <small><?= $pendingGRNs ?> pending GRN<?= $pendingGRNs !== 1 ? 's' : '' ?></small>
            </div>
            <div class="sparkline" id="spark-3"></div>
        </article>

    </div>

    <!-- ── KPI Row 2: Operations ─────────────────────────────────────── -->
    <div class="section-heading" style="margin-top:.6rem">
        <div><p class="eyebrow">TODAY'S OPERATIONS</p><h2>Daily activity</h2></div>
    </div>
    <div class="metric-grid four-col" id="kpi-row-2">

        <!-- OPD -->
        <article class="metric-card kpi-card kpi-opd" data-link="<?= page_link('opd_consumption') ?>" style="--delay:0">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Stethoscope head -->
                    <circle cx="110" cy="85" r="22" fill="white" opacity=".18" stroke="white" stroke-width="2.5" opacity=".4"/>
                    <circle cx="110" cy="85" r="12" fill="white" opacity=".25"/>
                    <!-- Stethoscope tube -->
                    <path d="M88 85 Q72 85 72 68 Q72 50 88 50 L102 50" stroke="white" stroke-width="3" fill="none" stroke-linecap="round" opacity=".5"/>
                    <!-- Earpieces -->
                    <circle cx="102" cy="50" r="5" fill="white" opacity=".4"/>
                    <circle cx="102" cy="50" r="2" fill="white" opacity=".7"/>
                    <path d="M60 50 L50 40" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <path d="M84 50 L74 40" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <!-- Cross symbol -->
                    <path d="M30 25 L30 45 M20 35 L40 35" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".6"/>
                    <circle cx="30" cy="35" r="14" stroke="white" stroke-width="2" opacity=".3"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">OPD patients served</span><span class="trend neutral">Today</span></div>
                <strong><?= $todayOPD ?></strong>
                <small>Consumption entries today</small>
            </div>
        </article>

        <!-- OT / Surgery -->
        <article class="metric-card kpi-card kpi-ot" data-link="<?= page_link('ot_consumption') ?>" style="--delay:1">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Scalpel blade -->
                    <path d="M50 90 L110 30 L120 40 L60 100 Z" fill="white" opacity=".2" stroke="white" stroke-width="1.5" opacity=".4"/>
                    <path d="M110 30 L125 20 L130 30 L120 40 Z" fill="white" opacity=".45"/>
                    <!-- Surgical scissors -->
                    <path d="M30 70 Q45 55 50 40" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <path d="M40 75 Q55 60 60 45" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity=".4"/>
                    <circle cx="35" cy="73" r="6" stroke="white" stroke-width="2" fill="none" opacity=".4"/>
                    <circle cx="46" cy="78" r="6" stroke="white" stroke-width="2" fill="none" opacity=".4"/>
                    <!-- Medical cross -->
                    <path d="M120 80 L120 100 M110 90 L130 90" stroke="white" stroke-width="3" stroke-linecap="round" opacity=".6"/>
                    <circle cx="120" cy="90" r="14" stroke="white" stroke-width="2" opacity=".25"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">OT surgeries today</span><span class="trend neutral">Today</span></div>
                <strong><?= $todayOT ?></strong>
                <small>Consumption posted</small>
            </div>
        </article>

        <!-- Requisitions -->
        <article class="metric-card kpi-card kpi-requisitions" data-link="<?= page_link('requisitions') ?>" style="--delay:2">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Stack of documents -->
                    <rect x="42" y="38" width="72" height="72" rx="7" fill="white" opacity=".12" stroke="white" stroke-width="2" opacity=".25"/>
                    <rect x="36" y="32" width="72" height="72" rx="7" fill="white" opacity=".15" stroke="white" stroke-width="2" opacity=".3"/>
                    <rect x="30" y="26" width="72" height="72" rx="7" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".38"/>
                    <!-- Lines on top doc -->
                    <line x1="42" y1="44" x2="90" y2="44" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".55"/>
                    <line x1="42" y1="54" x2="86" y2="54" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".45"/>
                    <line x1="42" y1="64" x2="75" y2="64" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".35"/>
                    <line x1="42" y1="74" x2="80" y2="74" stroke="white" stroke-width="2" stroke-linecap="round" opacity=".4"/>
                    <!-- Stamp/seal -->
                    <circle cx="118" cy="88" r="18" fill="white" opacity=".2" stroke="white" stroke-width="2" opacity=".4"/>
                    <path d="M111 88 L116 93 L126 83" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity=".8"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Requisitions</span><span class="trend <?= $pendingReqs > 0 ? 'down' : 'up' ?>"><?= $pendingReqs ?> pending</span></div>
                <strong><?= (int)$pdo->query('SELECT COUNT(*) FROM requisitions')->fetchColumn() ?></strong>
                <small><?= $pendingReqs ?> awaiting action</small>
            </div>
        </article>

        <!-- Fixed Assets -->
        <article class="metric-card kpi-card kpi-assets" data-link="<?= page_link('assets') ?>" style="--delay:3">
            <div class="kpi-illustration" aria-hidden="true">
                <svg viewBox="0 0 160 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Building -->
                    <rect x="30" y="45" width="70" height="65" rx="4" fill="white" opacity=".18" stroke="white" stroke-width="2" opacity=".35"/>
                    <!-- Roof -->
                    <path d="M20 48 L65 18 L110 48" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity=".5"/>
                    <!-- Windows -->
                    <rect x="40" y="55" width="14" height="14" rx="2" fill="white" opacity=".4"/>
                    <rect x="60" y="55" width="14" height="14" rx="2" fill="white" opacity=".3"/>
                    <rect x="80" y="55" width="14" height="14" rx="2" fill="white" opacity=".4"/>
                    <rect x="40" y="75" width="14" height="14" rx="2" fill="white" opacity=".3"/>
                    <rect x="60" y="75" width="14" height="14" rx="2" fill="white" opacity=".4"/>
                    <rect x="80" y="75" width="14" height="14" rx="2" fill="white" opacity=".3"/>
                    <!-- Door -->
                    <rect x="55" y="95" width="20" height="20" rx="2" fill="white" opacity=".45"/>
                    <!-- Tag -->
                    <rect x="110" y="25" width="38" height="24" rx="5" fill="white" opacity=".3" stroke="white" stroke-width="1.5" opacity=".5"/>
                    <circle cx="116" cy="37" r="3" fill="white" opacity=".6"/>
                    <line x1="122" y1="33" x2="142" y2="33" stroke="white" stroke-width="1.5" stroke-linecap="round" opacity=".5"/>
                    <line x1="122" y1="40" x2="136" y2="40" stroke="white" stroke-width="1.5" stroke-linecap="round" opacity=".4"/>
                </svg>
            </div>
            <div class="kpi-body">
                <div class="metric-top"><span class="kpi-label">Fixed assets</span><?php if ($stockValue > 0): ?><span class="trend up" style="font-size:8px">₨ <?= number_format($stockValue, 0) ?></span><?php endif; ?></div>
                <strong><?= $totalAssets ?></strong>
                <small><?= $assetWarrantyExpiring ?> warranty expiring soon</small>
            </div>
        </article>

    </div>

    <!-- ── Recent transactions ───────────────────────────────────────── -->
    <div class="dashboard-grid lower-grid" style="margin-top:.6rem">
        <section class="panel table-panel" style="grid-column:1/-1">
            <div class="panel-heading">
                <div><p class="eyebrow">AUDIT TRAIL</p><h2>Recent activity</h2></div>
                <a class="text-button" href="<?= page_link('reports') ?>">Full history →</a>
            </div>
            <div class="table-wrap">
                <?php if (empty($recentTx)): ?>
                <div class="empty-state">
                    <span class="empty-icon">📋</span>
                    <p>No transactions recorded yet.</p>
                    <small>Activity will appear here once items are received or issued.</small>
                </div>
                <?php else: ?>
                <table>
                    <thead><tr><th>Activity</th><th>Reference</th><th>Party / Location</th><th>Qty</th><th>Status</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentTx as $row): ?>
                    <tr>
                        <td><span class="table-icon"><?= $row['type'] === 'Patient consumption' ? '✚' : ($row['type'] === 'Goods received' ? '▣' : '↗') ?></span><?= e($row['type']) ?></td>
                        <td><b><?= e($row['reference']) ?></b></td>
                        <td><?= e($row['party']) ?></td>
                        <td><?= (int)$row['quantity'] ?></td>
                        <td><?= pill($row['status']) ?></td>
                        <td class="muted"><span class="rel-time" data-ts="<?= e($row['created_at']) ?>" title="<?= e(date('d M Y, g:i A', strtotime($row['created_at']))) ?>"><?= date('g:i A', strtotime($row['created_at'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>
<?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
