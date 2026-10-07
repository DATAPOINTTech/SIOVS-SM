<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

echo "=== VERIFYING SLIDE-OVER MODAL DRAWER ASSETS & CSS ===\n\n";

$css = file_get_contents(__DIR__ . '/../assets/system.css');
if (str_contains($css, '.drawer-backdrop') && str_contains($css, '.system-grid > .form-card') && str_contains($css, 'transform: translateX(105%)')) {
    echo "[PASS] assets/system.css contains modern slide-over modal drawer styling & full-width layout\n";
} else {
    echo "[FAIL] assets/system.css missing drawer styling\n";
    exit(1);
}

$js = file_get_contents(__DIR__ . '/../assets/app.js');
if (str_contains($js, 'initDrawerSystem') && str_contains($js, 'window.openDrawer') && str_contains($js, 'window.closeDrawer')) {
    echo "[PASS] assets/app.js contains initDrawerSystem(), openDrawer(), and closeDrawer() APIs\n";
} else {
    echo "[FAIL] assets/app.js missing drawer JavaScript APIs\n";
    exit(1);
}

$header = file_get_contents(__DIR__ . '/../partials/header.php');
if (str_contains($header, 'system.css?v=') && str_contains($header, 'style.css?v=')) {
    echo "[PASS] partials/header.php includes automatic cache-busting timestamps for instant browser refresh\n";
} else {
    echo "[FAIL] partials/header.php missing cache-busting timestamps\n";
    exit(1);
}

echo "\n=== ALL MODAL DRAWER CHECKS PASSED WITH 100% SUCCESS ===\n";
