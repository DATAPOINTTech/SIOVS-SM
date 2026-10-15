<?php
require_login();
$current = $current ?? 'dashboard';
$title   = $title   ?? 'SIOVS';
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$u = current_user();
$initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $u['name'] ?? 'U'), 0, 2)));
$firstName = explode(' ', $u['name'] ?? 'there')[0];

// Synchronized header context & badge counters across all pages
$headerCtx = get_header_context($pdo, $u);
extract($headerCtx);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · SIOVS SIMS</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
    <link rel="stylesheet" href="assets/system.css?v=<?= filemtime(__DIR__ . '/../assets/system.css') ?>">
    <link rel="stylesheet" href="assets/green-theme.css?v=<?= filemtime(__DIR__ . '/../assets/green-theme.css') ?>">
</head>
<body>
<div class="app-shell app-horizontal">

<!-- ══════════════════════════════════════════════════════
     TOP HEADER: Logo + Title + User actions
     ══════════════════════════════════════════════════════ -->
<header class="h-header">
    <a class="h-brand" href="index.php">
        <img src="https://siovs.edu.pk/wp-content/uploads/2022/09/logo-main.png"
             alt="SIOVS logo" class="h-brand-logo">
        <div class="h-brand-text">
            <strong>SIOVS</strong>
            <small>STORE, INVENTORY &amp; ASSET MANAGEMENT SYSTEM</small>
        </div>
    </a>

    <div class="h-header-actions">
        <?php if ($isPurchaseOfficer): ?>
        <a class="button button-primary" href="page.php?view=purchase_orders#add-po" style="font-size:12px;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px">＋ New Purchase Order</a>
        <?php elseif ($isStoreOfficer): ?>
        <a class="button button-primary" href="page.php?view=grn#add-grn" style="font-size:12px;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px">📦 Generate GRN</a>
        <?php elseif (!empty($isDDFinance)): ?>
        <a class="button button-primary" href="page.php?view=adjustments" style="font-size:12px;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px;background:#0f766e;border-color:#0f766e">⚖️ Review Adjustments</a>
        <?php elseif (can('quick-entry')): ?>
        <a class="button button-primary" href="<?= page_link('quick-entry') ?>">＋ Quick entry</a>
        <?php endif; ?>

        <!-- Bell alerts -->
        <div class="alerts-wrap" id="alerts-wrap">
            <button class="icon-button alerts-btn" id="alerts-btn" title="Alerts" aria-haspopup="true" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <?php if ($alertCount > 0): ?>
                <i class="alert-badge"><?= $alertCount ?></i>
                <?php endif; ?>
            </button>

            <!-- Dropdown panel -->
            <div class="alerts-dropdown" id="alerts-dropdown" role="dialog" aria-label="Alerts">
                <div class="alerts-dd-header">
                    <strong>Alerts</strong>
                    <span class="alerts-dd-count"><?= $alertCount ?> active</span>
                </div>
                <ul class="alerts-dd-list">
                    <?php if ($isPurchaseOfficer): ?>
                        <?php if ($poOverdueCount > 0): ?>
                        <li class="ad-item ad-red">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $poOverdueCount ?> purchase order<?= $poOverdueCount > 1 ? 's' : '' ?> overdue</b>
                                <small>Delivery deadline missed by vendor</small>
                            </div>
                            <a href="<?= page_link('purchase_orders') ?>" class="ad-action">Track →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($reorderCount > 0): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $reorderCount ?> store item<?= $reorderCount > 1 ? 's' : '' ?> low on stock</b>
                                <small>Safety stock breached · Raise PO</small>
                            </div>
                            <a href="<?= page_link('inventory') ?>" class="ad-action">Reorder →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($poDraftCount > 0): ?>
                        <li class="ad-item ad-blue">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $poDraftCount ?> draft purchase order<?= $poDraftCount > 1 ? 's' : '' ?></b>
                                <small>Awaiting authorization &amp; dispatch</small>
                            </div>
                            <a href="<?= page_link('purchase_orders') ?>" class="ad-action">Review →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($alertCount === 0): ?>
                        <li class="ad-item ad-ok">
                            <span class="ad-dot"></span>
                            <div><b>All clear</b><small>No overdue POs or stock alerts</small></div>
                        </li>
                        <?php endif; ?>
                    <?php elseif ($isStoreOfficer): ?>
                        <?php if (!empty($incomingPOs)): ?>
                        <li class="ad-item ad-blue">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $incomingPOs ?> purchase order<?= $incomingPOs > 1 ? 's' : '' ?> arrived</b>
                                <small>Approved POs ready for GRN receipt</small>
                            </div>
                            <a href="<?= page_link('purchase_orders') ?>" class="ad-action">Receive →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($pendingGRNs > 0): ?>
                        <li class="ad-item ad-blue">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingGRNs ?> GRN<?= $pendingGRNs > 1 ? 's' : '' ?> pending inspection</b>
                                <small>Goods received, verify &amp; accept</small>
                            </div>
                            <a href="<?= page_link('grn') ?>" class="ad-action">Inspect →</a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($pendingReqs)): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingReqs ?> requisition<?= $pendingReqs > 1 ? 's' : '' ?> approved</b>
                                <small>Awaiting store issue &amp; FEFO dispatch</small>
                            </div>
                            <a href="<?= page_link('requisitions') ?>" class="ad-action">Issue →</a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($pendingReturns)): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingReturns ?> department return<?= $pendingReturns > 1 ? 's' : '' ?></b>
                                <small>Awaiting store verification</small>
                            </div>
                            <a href="<?= page_link('returns') ?>" class="ad-action">Verify →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($criticalStock > 0): ?>
                        <li class="ad-item ad-red">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $criticalStock ?> item<?= $criticalStock > 1 ? 's' : '' ?> out of stock</b>
                                <small>Stock depleted in Central Store</small>
                            </div>
                            <a href="<?= page_link('inventory') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($nearExpiry30 > 0): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $nearExpiry30 ?> item<?= $nearExpiry30 > 1 ? 's' : '' ?> expiring in 30 days</b>
                                <small>Review FEFO priority dispatch</small>
                            </div>
                            <a href="<?= page_link('batches') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($alertCount === 0): ?>
                        <li class="ad-item ad-ok">
                            <span class="ad-dot"></span>
                            <div><b>All clear</b><small>No pending receipts or stock alerts</small></div>
                        </li>
                        <?php endif; ?>
                    <?php elseif (!empty($isDDFinance)): ?>
                        <?php if (!empty($pendingAdj)): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingAdj ?> stock adjustment<?= $pendingAdj > 1 ? 's' : '' ?> awaiting authorization</b>
                                <small>Variances &amp; write-offs requiring financial signoff</small>
                            </div>
                            <a href="<?= page_link('adjustments') ?>" class="ad-action">Review →</a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($pendingDeletions)): ?>
                        <li class="ad-item ad-red">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingDeletions ?> deletion request<?= $pendingDeletions > 1 ? 's' : '' ?></b>
                                <small>Audited reversal requests awaiting decision</small>
                            </div>
                            <a href="<?= page_link('deletions') ?>" class="ad-action">Audit →</a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($pendingVer)): ?>
                        <li class="ad-item ad-blue">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingVer ?> physical stock audit<?= $pendingVer > 1 ? 's' : '' ?> submitted</b>
                                <small>Board of Survey counts awaiting review</small>
                            </div>
                            <a href="<?= page_link('verification') ?>" class="ad-action">Inspect →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($alertCount === 0): ?>
                        <li class="ad-item ad-ok">
                            <span class="ad-dot"></span>
                            <div><b>All clear</b><small>No pending financial authorizations</small></div>
                        </li>
                        <?php endif; ?>
                    <?php elseif (!empty($isDeptIncharge)): ?>
                        <?php if (!empty($deptPendingReqs)): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $deptPendingReqs ?> requisition<?= $deptPendingReqs > 1 ? 's' : '' ?> awaiting store issue</b>
                                <small>Submitted to Central Store for dispatch</small>
                            </div>
                            <a href="<?= page_link('requisitions') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($subStoreExpiring)): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $subStoreExpiring ?> sub-store item<?= $subStoreExpiring > 1 ? 's' : '' ?> expiring in 30 days</b>
                                <small>Dispense promptly or initiate return voucher</small>
                            </div>
                            <a href="<?= page_link('returns') ?>" class="ad-action">Return →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($alertCount === 0): ?>
                        <li class="ad-item ad-ok">
                            <span class="ad-dot"></span>
                            <div><b>All clear</b><small>No pending requisitions or expiring sub-store stock</small></div>
                        </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($criticalStock > 0): ?>
                        <li class="ad-item ad-red">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $criticalStock ?> item<?= $criticalStock > 1 ? 's' : '' ?> out of stock</b>
                                <small>Immediate reorder required</small>
                            </div>
                            <a href="<?= page_link('inventory') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($nearExpiry30 > 0): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $nearExpiry30 ?> item<?= $nearExpiry30 > 1 ? 's' : '' ?> expiring in 30 days</b>
                                <small>Review FEFO allocation</small>
                            </div>
                            <a href="<?= page_link('batches') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($pendingAdj > 0): ?>
                        <li class="ad-item ad-amber">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingAdj ?> stock adjustment<?= $pendingAdj > 1 ? 's' : '' ?> pending</b>
                                <small>Awaiting approval</small>
                            </div>
                            <a href="<?= page_link('adjustments') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($pendingGRNs > 0): ?>
                        <li class="ad-item ad-blue">
                            <span class="ad-dot"></span>
                            <div>
                                <b><?= $pendingGRNs ?> GRN<?= $pendingGRNs > 1 ? 's' : '' ?> pending inspection</b>
                                <small>Goods received, not yet inspected</small>
                            </div>
                            <a href="<?= page_link('grn') ?>" class="ad-action">View →</a>
                        </li>
                        <?php endif; ?>
                        <?php if ($alertCount === 0): ?>
                        <li class="ad-item ad-ok">
                            <span class="ad-dot"></span>
                            <div><b>All clear</b><small>No active alerts right now</small></div>
                        </li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <div class="alerts-dd-footer">
                    <a href="<?= $isPurchaseOfficer ? page_link('reports') . '&rtype=purchase' : ($isStoreOfficer ? page_link('reports') . '&rtype=stock' : (!empty($isDDFinance) ? page_link('reports') . '&rtype=valuation' : (!empty($isDeptIncharge) ? page_link('reports') . '&rtype=consumption' : page_link('reports')))) ?>">View full report →</a>
                </div>
            </div>
        </div>

        <!-- Spotlight search button -->
        <button class="icon-button" id="spotlight-btn" title="Search modules (Ctrl+K)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>

        <div class="h-user-chip">
            <span class="avatar"><?= e($initials) ?></span>
            <div class="h-user-info">
                <strong><?= e($u['name'] ?? '') ?></strong>
                <small><?= e(role_label($u['role'] ?? '')) ?></small>
            </div>
            <a class="logout" href="logout.php" title="Sign out">↪</a>
        </div>
    </div>
</header>

<!-- ══════════════════════════════════════════════════════
     HORIZONTAL NAV BAR
     ══════════════════════════════════════════════════════ -->
<nav class="h-nav">
    <div class="h-nav-inner">
        <?php if ($isPurchaseOfficer): ?>
        <a class="h-nav-link <?= $current === 'dashboard' ? 'active' : '' ?>" href="index.php">
            <span class="h-nav-icon">◈</span> Procurement Dashboard
        </a>
        <a class="h-nav-link <?= $current === 'purchase_orders' ? 'active' : '' ?>" href="<?= page_link('purchase_orders') ?>">
            <span class="h-nav-icon">↗</span> Purchase Orders
            <?php if (!empty($poAwaitingDelivery)): ?><b class="h-nav-badge" style="background:#0284c7"><?= $poAwaitingDelivery ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'suppliers' ? 'active' : '' ?>" href="<?= page_link('suppliers') ?>">
            <span class="h-nav-icon">⊞</span> Suppliers
        </a>
        <a class="h-nav-link <?= $current === 'inventory' ? 'active' : '' ?>" href="<?= page_link('inventory') ?>">
            <span class="h-nav-icon">◫</span> Store Stock &amp; Reorder Levels
            <?php if (!empty($reorderCount)): ?><b class="h-nav-badge" style="background:#dc2626"><?= $reorderCount ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'reports' ? 'active' : '' ?>" href="<?= page_link('reports') ?>&rtype=purchase">
            <span class="h-nav-icon">▥</span> Procurement Reports
        </a>
        <?php elseif ($isStoreOfficer): ?>
        <a class="h-nav-link <?= $current === 'dashboard' ? 'active' : '' ?>" href="index.php">
            <span class="h-nav-icon">◈</span> Store Dashboard
        </a>
        <a class="h-nav-link <?= $current === 'grn' ? 'active' : '' ?>" href="<?= page_link('grn') ?>">
            <span class="h-nav-icon">▣</span> GRN Receipts
            <?php if (!empty($pendingGRNs)): ?><b class="h-nav-badge" style="background:#0284c7"><?= $pendingGRNs ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'requisitions' ? 'active' : '' ?>" href="<?= page_link('requisitions') ?>">
            <span class="h-nav-icon">⌁</span> Requisitions &amp; Issue
            <?php if (!empty($pendingReqs)): ?><b class="h-nav-badge" style="background:#059669"><?= $pendingReqs ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'inventory' ? 'active' : '' ?>" href="<?= page_link('inventory') ?>">
            <span class="h-nav-icon">◫</span> Inventory &amp; Ledger
            <?php if (!empty($lowStock)): ?><b class="h-nav-badge" style="background:#dc2626"><?= $lowStock ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'batches' ? 'active' : '' ?>" href="<?= page_link('batches') ?>">
            <span class="h-nav-icon">◷</span> Batch &amp; Expiry (FEFO)
            <?php if (!empty($nearExpiry30)): ?><b class="h-nav-badge" style="background:#d97706"><?= $nearExpiry30 ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'returns' ? 'active' : '' ?>" href="<?= page_link('returns') ?>">
            <span class="h-nav-icon">↻</span> Returns
            <?php if (!empty($pendingReturns)): ?><b class="h-nav-badge" style="background:#6366f1"><?= $pendingReturns ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'verification' ? 'active' : '' ?>" href="<?= page_link('verification') ?>">
            <span class="h-nav-icon">✓</span> Verification
        </a>
        <a class="h-nav-link <?= $current === 'adjustments' ? 'active' : '' ?>" href="<?= page_link('adjustments') ?>">
            <span class="h-nav-icon">⇌</span> Adjustments
            <?php if (!empty($pendingAdj)): ?><b class="h-nav-badge"><?= $pendingAdj ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'reports' ? 'active' : '' ?>" href="<?= page_link('reports') ?>&rtype=stock">
            <span class="h-nav-icon">▥</span> Store Reports
        </a>
        <a class="h-nav-link <?= $current === 'purchase_orders' ? 'active' : '' ?>" href="<?= page_link('purchase_orders') ?>">
            <span class="h-nav-icon">↗</span> Incoming POs
            <?php if (!empty($incomingPOs)): ?><b class="h-nav-badge" style="background:#2563eb"><?= $incomingPOs ?></b><?php endif; ?>
        </a>
        <?php elseif (!empty($isDDFinance)): ?>
        <a class="h-nav-link <?= $current === 'dashboard' ? 'active' : '' ?>" href="index.php">
            <span class="h-nav-icon">◈</span> Finance Dashboard
        </a>
        <a class="h-nav-link <?= $current === 'adjustments' ? 'active' : '' ?>" href="<?= page_link('adjustments') ?>">
            <span class="h-nav-icon">⇌</span> Stock Adjustments
            <?php if (!empty($pendingAdj)): ?><b class="h-nav-badge" style="background:#d97706"><?= $pendingAdj ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'verification' ? 'active' : '' ?>" href="<?= page_link('verification') ?>">
            <span class="h-nav-icon">✓</span> Physical Audits
            <?php if (!empty($pendingVer)): ?><b class="h-nav-badge" style="background:#0284c7"><?= $pendingVer ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'deletions' ? 'active' : '' ?>" href="<?= page_link('deletions') ?>">
            <span class="h-nav-icon">⊘</span> Deletion Queue
            <?php if (!empty($pendingDeletions)): ?><b class="h-nav-badge" style="background:#dc2626"><?= $pendingDeletions ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'assets' ? 'active' : '' ?>" href="<?= page_link('assets') ?>">
            <span class="h-nav-icon">◫</span> Fixed Assets &amp; Equipment
        </a>
        <a class="h-nav-link <?= $current === 'reconciliation' ? 'active' : '' ?>" href="<?= page_link('reconciliation') ?>">
            <span class="h-nav-icon">⚖</span> Register Reconciliation
        </a>
        <a class="h-nav-link <?= ($current === 'reports' && (($_GET['rtype'] ?? '') === 'valuation' || empty($_GET['rtype']))) ? 'active' : '' ?>" href="<?= page_link('reports') ?>&rtype=valuation">
            <span class="h-nav-icon">▥</span> Financial &amp; Valuation
        </a>
        <a class="h-nav-link <?= ($current === 'reports' && ($_GET['rtype'] ?? '') === 'purchase') ? 'active' : '' ?>" href="<?= page_link('reports') ?>&rtype=purchase">
            <span class="h-nav-icon">↗</span> PO Commitments
        </a>
        <?php elseif (!empty($isDeptIncharge)): ?>
        <a class="h-nav-link <?= $current === 'dashboard' ? 'active' : '' ?>" href="index.php">
            <span class="h-nav-icon">◈</span> Clinical Dashboard
        </a>
        <a class="h-nav-link <?= $current === 'requisitions' ? 'active' : '' ?>" href="<?= page_link('requisitions') ?>">
            <span class="h-nav-icon">⌁</span> Department Requisitions
            <?php if (!empty($deptPendingReqs)): ?><b class="h-nav-badge" style="background:#059669"><?= $deptPendingReqs ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'returns' ? 'active' : '' ?>" href="<?= page_link('returns') ?>">
            <span class="h-nav-icon">◫</span> Sub-Store Stock &amp; Returns
            <?php if (!empty($subStoreExpiring)): ?><b class="h-nav-badge" style="background:#d97706"><?= $subStoreExpiring ?></b><?php endif; ?>
        </a>
        <a class="h-nav-link <?= $current === 'emergency' ? 'active' : '' ?>" href="<?= page_link('emergency') ?>">
            <span class="h-nav-icon">!</span> Emergency
        </a>
        <a class="h-nav-link <?= $current === 'opd_consumption' ? 'active' : '' ?>" href="<?= page_link('opd_consumption') ?>">
            <span class="h-nav-icon">✚</span> OPD Dispensing
        </a>
        <a class="h-nav-link <?= $current === 'ipd_consumption' ? 'active' : '' ?>" href="<?= page_link('ipd_consumption') ?>">
            <span class="h-nav-icon">⊕</span> IPD Ward Dispensing
        </a>
        <a class="h-nav-link <?= $current === 'ot_consumption' ? 'active' : '' ?>" href="<?= page_link('ot_consumption') ?>">
            <span class="h-nav-icon">⊗</span> OT Dispensing
        </a>
        <a class="h-nav-link <?= ($current === 'reports' && (($_GET['rtype'] ?? '') === 'consumption' || empty($_GET['rtype']))) ? 'active' : '' ?>" href="<?= page_link('reports') ?>&rtype=consumption">
            <span class="h-nav-icon">▥</span> Consumption Reports
        </a>
        <?php else: ?>
        <a class="h-nav-link <?= $current === 'dashboard' ? 'active' : '' ?>" href="index.php">
            <span class="h-nav-icon">◈</span> Dashboard
        </a>
        <?php if (can('reports')): ?>
        <a class="h-nav-link <?= $current === 'reports' ? 'active' : '' ?>" href="<?= page_link('reports') ?>">
            <span class="h-nav-icon">▥</span> Reports
        </a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('inventory')): ?>
        <a class="h-nav-link <?= $current === 'inventory' ? 'active' : '' ?>" href="<?= page_link('inventory') ?>">
            <span class="h-nav-icon">◫</span> Inventory
        </a>
        <?php endif; ?>
        <?php if (can('batches')): ?>
        <a class="h-nav-link <?= $current === 'batches' ? 'active' : '' ?>" href="<?= page_link('batches') ?>">
            <span class="h-nav-icon">◷</span> Batch &amp; Expiry
            <?php if (!empty($nearExpiry30)): ?><b class="h-nav-badge"><?= $nearExpiry30 ?></b><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (can('adjustments')): ?>
        <a class="h-nav-link <?= $current === 'adjustments' ? 'active' : '' ?>" href="<?= page_link('adjustments') ?>">
            <span class="h-nav-icon">⇌</span> Adjustments
            <?php if (!empty($pendingAdj)): ?><b class="h-nav-badge"><?= $pendingAdj ?></b><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (can('verification')): ?>
        <a class="h-nav-link <?= $current === 'verification' ? 'active' : '' ?>" href="<?= page_link('verification') ?>">
            <span class="h-nav-icon">✓</span> Verification
        </a>
        <?php endif; ?>
        <?php if (can('reconciliation')): ?>
        <a class="h-nav-link <?= $current === 'reconciliation' ? 'active' : '' ?>" href="<?= page_link('reconciliation') ?>">
            <span class="h-nav-icon">⚖</span> Reconciliation
        </a>
        <?php endif; ?>
        <?php if (can('deletions')): ?>
        <a class="h-nav-link <?= $current === 'deletions' ? 'active' : '' ?>" href="<?= page_link('deletions') ?>">
            <span class="h-nav-icon">✕</span> Deletion Approvals
        </a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('requisitions')): ?>
        <a class="h-nav-link <?= $current === 'requisitions' ? 'active' : '' ?>" href="<?= page_link('requisitions') ?>">
            <span class="h-nav-icon">⌁</span> Requisitions
            <?php if (!empty($pendingReqs)): ?><b class="h-nav-badge"><?= $pendingReqs ?></b><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (can('suppliers')): ?>
        <a class="h-nav-link <?= $current === 'suppliers' ? 'active' : '' ?>" href="<?= page_link('suppliers') ?>">
            <span class="h-nav-icon">⊞</span> Suppliers
        </a>
        <?php endif; ?>
        <?php if (can('purchase_orders')): ?>
        <a class="h-nav-link <?= $current === 'purchase_orders' ? 'active' : '' ?>" href="<?= page_link('purchase_orders') ?>">
            <span class="h-nav-icon">↗</span> Purchase Orders
            <?php if (!empty($openPOs)): ?><b class="h-nav-badge"><?= $openPOs ?></b><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (can('grn')): ?>
        <a class="h-nav-link <?= $current === 'grn' ? 'active' : '' ?>" href="<?= page_link('grn') ?>">
            <span class="h-nav-icon">▣</span> GRN
            <?php if (!empty($pendingGRNs)): ?><b class="h-nav-badge"><?= $pendingGRNs ?></b><?php endif; ?>
        </a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('opd_consumption')): ?>
        <a class="h-nav-link <?= $current === 'opd_consumption' ? 'active' : '' ?>" href="<?= page_link('opd_consumption') ?>"><span class="h-nav-icon">✚</span> OPD</a>
        <?php endif; ?>
        <?php if (can('ipd_consumption')): ?>
        <a class="h-nav-link <?= $current === 'ipd_consumption' ? 'active' : '' ?>" href="<?= page_link('ipd_consumption') ?>"><span class="h-nav-icon">⊕</span> IPD</a>
        <?php endif; ?>
        <?php if (can('ot_consumption')): ?>
        <a class="h-nav-link <?= $current === 'ot_consumption' ? 'active' : '' ?>" href="<?= page_link('ot_consumption') ?>"><span class="h-nav-icon">⊗</span> OT</a>
        <?php endif; ?>
        <?php if (can('emergency')): ?>
        <a class="h-nav-link <?= $current === 'emergency' ? 'active' : '' ?>" href="<?= page_link('emergency') ?>"><span class="h-nav-icon">!</span> Emergency</a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('assets')): ?>
        <a class="h-nav-link <?= $current === 'assets' ? 'active' : '' ?>" href="<?= page_link('assets') ?>"><span class="h-nav-icon">▤</span> Assets</a>
        <?php endif; ?>
        <?php if (can('departments')): ?>
        <a class="h-nav-link <?= $current === 'departments' ? 'active' : '' ?>" href="<?= page_link('departments') ?>"><span class="h-nav-icon">⊟</span> Departments</a>
        <?php endif; ?>
        <?php if (can('returns')): ?>
        <a class="h-nav-link <?= $current === 'returns' ? 'active' : '' ?>" href="<?= page_link('returns') ?>"><span class="h-nav-icon">↻</span> Returns</a>
        <?php endif; ?>
        <?php if (can('users')): ?>
        <a class="h-nav-link <?= $current === 'users' ? 'active' : '' ?>" href="<?= page_link('users') ?>"><span class="h-nav-icon">♙</span> Users</a>
        <?php endif; ?>
        <?php if (can('quick-entry')): ?>
        <a class="h-nav-link <?= $current === 'quick-entry' ? 'active' : '' ?>" href="<?= page_link('quick-entry') ?>"><span class="h-nav-icon">＋</span> Quick Entry</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</nav>

<!-- ── Pending-actions ticker / horizontal banner ─────────────── -->
<?php if (!empty($pendingActions)): ?>
<div class="pending-banner" id="pending-banner">
    <span class="pending-icon">⚑</span>
    <div class="pending-items">
        <?php foreach ($pendingActions as $pa): if (!can($pa['perm'])) continue; ?>
        <a href="<?= e($pa['link']) ?>" class="pending-link"><?= e($pa['label']) ?> →</a>
        <?php endforeach; ?>
    </div>
    <button class="pending-dismiss" onclick="this.closest('.pending-banner').style.display='none';sessionStorage.setItem('siovs_banner_dismissed','1')" title="Dismiss">×</button>
</div>
<script>if(sessionStorage.getItem('siovs_banner_dismissed')==='1'){var b=document.getElementById('pending-banner');if(b)b.style.display='none';}</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════ -->
<main class="main h-main">
<?php if ($flash): ?>
<div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
