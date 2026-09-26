<?php
require_login();
$current = $current ?? 'dashboard';
$title   = $title   ?? 'SIOVS';
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$u = current_user();
$initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $u['name'] ?? 'U'), 0, 2)));
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
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/system.css">
    <link rel="stylesheet" href="assets/green-theme.css">
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
        <?php if (can('quick-entry')): ?>
        <a class="button button-primary" href="<?= page_link('quick-entry') ?>">＋ Quick entry</a>
        <?php endif; ?>

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
        </a>
        <?php endif; ?>
        <?php if (can('adjustments')): ?>
        <a class="h-nav-link <?= $current === 'adjustments' ? 'active' : '' ?>" href="<?= page_link('adjustments') ?>">
            <span class="h-nav-icon">⇌</span> Adjustments
        </a>
        <?php endif; ?>
        <?php if (can('verification')): ?>
        <a class="h-nav-link <?= $current === 'verification' ? 'active' : '' ?>" href="<?= page_link('verification') ?>">
            <span class="h-nav-icon">✓</span> Verification
        </a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('requisitions')): ?>
        <a class="h-nav-link <?= $current === 'requisitions' ? 'active' : '' ?>" href="<?= page_link('requisitions') ?>">
            <span class="h-nav-icon">⌁</span> Requisitions
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
        </a>
        <?php endif; ?>
        <?php if (can('grn')): ?>
        <a class="h-nav-link <?= $current === 'grn' ? 'active' : '' ?>" href="<?= page_link('grn') ?>">
            <span class="h-nav-icon">▣</span> GRN
        </a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('opd_consumption')): ?>
        <a class="h-nav-link <?= $current === 'opd_consumption' ? 'active' : '' ?>" href="<?= page_link('opd_consumption') ?>">
            <span class="h-nav-icon">✚</span> OPD
        </a>
        <?php endif; ?>
        <?php if (can('ipd_consumption')): ?>
        <a class="h-nav-link <?= $current === 'ipd_consumption' ? 'active' : '' ?>" href="<?= page_link('ipd_consumption') ?>">
            <span class="h-nav-icon">⊕</span> IPD
        </a>
        <?php endif; ?>
        <?php if (can('ot_consumption')): ?>
        <a class="h-nav-link <?= $current === 'ot_consumption' ? 'active' : '' ?>" href="<?= page_link('ot_consumption') ?>">
            <span class="h-nav-icon">⊗</span> OT
        </a>
        <?php endif; ?>
        <?php if (can('emergency')): ?>
        <a class="h-nav-link <?= $current === 'emergency' ? 'active' : '' ?>" href="<?= page_link('emergency') ?>">
            <span class="h-nav-icon">!</span> Emergency
        </a>
        <?php endif; ?>

        <span class="h-nav-sep"></span>

        <?php if (can('assets')): ?>
        <a class="h-nav-link <?= $current === 'assets' ? 'active' : '' ?>" href="<?= page_link('assets') ?>">
            <span class="h-nav-icon">▤</span> Assets
        </a>
        <?php endif; ?>
        <?php if (can('departments')): ?>
        <a class="h-nav-link <?= $current === 'departments' ? 'active' : '' ?>" href="<?= page_link('departments') ?>">
            <span class="h-nav-icon">⊟</span> Departments
        </a>
        <?php endif; ?>
        <?php if (can('returns')): ?>
        <a class="h-nav-link <?= $current === 'returns' ? 'active' : '' ?>" href="<?= page_link('returns') ?>">
            <span class="h-nav-icon">↻</span> Returns
        </a>
        <?php endif; ?>
        <?php if (can('users')): ?>
        <a class="h-nav-link <?= $current === 'users' ? 'active' : '' ?>" href="<?= page_link('users') ?>">
            <span class="h-nav-icon">♙</span> Users
        </a>
        <?php endif; ?>
        <?php if (can('quick-entry')): ?>
        <a class="h-nav-link <?= $current === 'quick-entry' ? 'active' : '' ?>" href="<?= page_link('quick-entry') ?>">
            <span class="h-nav-icon">＋</span> Quick Entry
        </a>
        <?php endif; ?>
    </div>
</nav>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════ -->
<main class="main h-main">
<?php if ($flash): ?>
<div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
