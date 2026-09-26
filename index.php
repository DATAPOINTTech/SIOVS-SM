<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
require_login();
log_activity($pdo, 'page_view', 'dashboard');

/* ── Dashboard KPIs ──────────────────────────────────────────────────────── */
$today = date('Y-m-d');

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
if ($pendingGRNs > 0) $pendingActions[] = ['label' => "{$pendingGRNs} GRN" . ($pendingGRNs > 1 ? 's' : '') . ' pending inspection',         'link' => page_link('grn'),          'perm' => 'grn'];
if ($pendingReqs > 0) $pendingActions[] = ['label' => "{$pendingReqs} requisition" . ($pendingReqs > 1 ? 's' : '') . ' awaiting action',     'link' => page_link('requisitions'), 'perm' => 'requisitions'];
if ($pendingAdj  > 0) $pendingActions[] = ['label' => "{$pendingAdj} stock adjustment" . ($pendingAdj > 1 ? 's' : '') . ' pending approval', 'link' => page_link('adjustments'),  'perm' => 'adjustments'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIOVS · Command Center</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/system.css">
    <link rel="stylesheet" href="assets/green-theme.css">
</head>
<body>
<?php
$current = 'dashboard';
$title   = 'Dashboard';
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$u        = current_user();
$initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $u['name'] ?? 'U'), 0, 2)));
$firstName = explode(' ', $u['name'] ?? 'there')[0];
?>
<div class="app-shell app-horizontal">

<!-- ══════════════════════════════════════════════════════
     TOP HEADER: Logo + Title + User actions
     ══════════════════════════════════════════════════════ -->
<header class="h-header">
    <!-- Brand -->
    <a class="h-brand" href="index.php">
        <img src="https://siovs.edu.pk/wp-content/uploads/2022/09/logo-main.png"
             alt="SIOVS logo" class="h-brand-logo">
        <div class="h-brand-text">
            <strong>SIOVS</strong>
            <small>STORE, INVENTORY &amp; ASSET MANAGEMENT SYSTEM</small>
        </div>
    </a>

    <!-- Right: alerts + search + user -->
    <div class="h-header-actions">

        <!-- Bell alerts -->
        <?php $alertCount = $nearExpiry30 + $criticalStock + $pendingAdj + $pendingGRNs; ?>
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
                </ul>
                <div class="alerts-dd-footer">
                    <a href="<?= page_link('reports') ?>">View full report →</a>
                </div>
            </div>
        </div>

        <!-- ── Spotlight search button ── -->
        <button class="icon-button" id="spotlight-btn" title="Search modules (Ctrl+K)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>

        <!-- User chip -->
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
        <a class="h-nav-link active" href="index.php">
            <span class="h-nav-icon">◈</span> Dashboard
        </a>
        <?php if (can('reports')): ?>
        <a class="h-nav-link" href="<?= page_link('reports') ?>">
            <span class="h-nav-icon">▥</span> Reports
        </a><?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('inventory')): ?>
        <a class="h-nav-link" href="<?= page_link('inventory') ?>">
            <span class="h-nav-icon">◫</span> Inventory
        </a><?php endif; ?>
        <?php if (can('batches')): ?>
        <a class="h-nav-link" href="<?= page_link('batches') ?>">
            <span class="h-nav-icon">◷</span> Batch &amp; Expiry
            <?php if ($nearExpiry30 > 0): ?><b class="h-nav-badge"><?= $nearExpiry30 ?></b><?php endif; ?>
        </a><?php endif; ?>
        <?php if (can('adjustments')): ?>
        <a class="h-nav-link" href="<?= page_link('adjustments') ?>">
            <span class="h-nav-icon">⇌</span> Adjustments
            <?php if ($pendingAdj > 0): ?><b class="h-nav-badge"><?= $pendingAdj ?></b><?php endif; ?>
        </a><?php endif; ?>
        <?php if (can('verification')): ?>
        <a class="h-nav-link" href="<?= page_link('verification') ?>">
            <span class="h-nav-icon">✓</span> Verification
        </a><?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('requisitions')): ?>
        <a class="h-nav-link" href="<?= page_link('requisitions') ?>">
            <span class="h-nav-icon">⌁</span> Requisitions
            <?php if ($pendingReqs > 0): ?><b class="h-nav-badge"><?= $pendingReqs ?></b><?php endif; ?>
        </a><?php endif; ?>
        <?php if (can('suppliers')): ?>
        <a class="h-nav-link" href="<?= page_link('suppliers') ?>">
            <span class="h-nav-icon">⊞</span> Suppliers
        </a><?php endif; ?>
        <?php if (can('purchase_orders')): ?>
        <a class="h-nav-link" href="<?= page_link('purchase_orders') ?>">
            <span class="h-nav-icon">↗</span> Purchase Orders
            <?php if ($openPOs > 0): ?><b class="h-nav-badge"><?= $openPOs ?></b><?php endif; ?>
        </a><?php endif; ?>
        <?php if (can('grn')): ?>
        <a class="h-nav-link" href="<?= page_link('grn') ?>">
            <span class="h-nav-icon">▣</span> GRN
            <?php if ($pendingGRNs > 0): ?><b class="h-nav-badge"><?= $pendingGRNs ?></b><?php endif; ?>
        </a><?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('opd_consumption')): ?>
        <a class="h-nav-link" href="<?= page_link('opd_consumption') ?>"><span class="h-nav-icon">✚</span> OPD</a><?php endif; ?>
        <?php if (can('ipd_consumption')): ?>
        <a class="h-nav-link" href="<?= page_link('ipd_consumption') ?>"><span class="h-nav-icon">⊕</span> IPD</a><?php endif; ?>
        <?php if (can('ot_consumption')): ?>
        <a class="h-nav-link" href="<?= page_link('ot_consumption') ?>"><span class="h-nav-icon">⊗</span> OT</a><?php endif; ?>
        <?php if (can('emergency')): ?>
        <a class="h-nav-link" href="<?= page_link('emergency') ?>"><span class="h-nav-icon">!</span> Emergency</a><?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('assets')): ?>
        <a class="h-nav-link" href="<?= page_link('assets') ?>"><span class="h-nav-icon">▤</span> Assets</a><?php endif; ?>
        <?php if (can('departments')): ?>
        <a class="h-nav-link" href="<?= page_link('departments') ?>"><span class="h-nav-icon">⊟</span> Departments</a><?php endif; ?>
        <?php if (can('returns')): ?>
        <a class="h-nav-link" href="<?= page_link('returns') ?>"><span class="h-nav-icon">↻</span> Returns</a><?php endif; ?>
        <?php if (can('users')): ?>
        <a class="h-nav-link" href="<?= page_link('users') ?>"><span class="h-nav-icon">♙</span> Users</a><?php endif; ?>
        <?php if (can('quick-entry')): ?>
        <a class="h-nav-link" href="<?= page_link('quick-entry') ?>"><span class="h-nav-icon">＋</span> Quick Entry</a><?php endif; ?>
    </div>
</nav>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════ -->
<main class="main h-main">
<?php if ($flash): ?>
<div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if (!empty($pendingActions)): ?>
<!-- ── Pending-actions banner ────────────────────────────────────────── -->
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

<section class="page-content" id="dashboard">

    <!-- ── Hero banner ───────────────────────────────────────────────── -->
    <div class="hero hero-expanded">
        <div class="hero-brand">
            <img src="https://siovs.edu.pk/wp-content/uploads/2022/09/logo-main.png"
                 alt="SIOVS logo" class="hero-logo">
            <div class="hero-system-name">
                <span class="hero-siovs">SIOVS</span>
                <span class="hero-full-title">STORE, INVENTORY &amp;<br>ASSET MANAGEMENT<br>SYSTEM</span>
            </div>
        </div>
        <div class="hero-divider"></div>
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
</section>
</main>
</div>

<!-- ── Ctrl+K Spotlight ──────────────────────────────────────────────────── -->
<div class="spotlight-backdrop" id="spotlight" role="dialog" aria-modal="true" aria-label="Module search">
    <div class="spotlight-box">
        <div class="spotlight-input-wrap">
            <span class="spotlight-icon">⌕</span>
            <input type="search" id="spotlight-input" placeholder="Jump to module…" autocomplete="off" spellcheck="false">
            <kbd class="spotlight-esc">Esc</kbd>
        </div>
        <ul class="spotlight-list" id="spotlight-list"></ul>
    </div>
</div>

<script>
// ── Inject sparkline data for JS to read ────────────────────────────────────
window.SIOVS_SPARK = <?= $sparkJson ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>
