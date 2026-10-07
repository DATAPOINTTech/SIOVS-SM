<?php
declare(strict_types=1);

$baseUrl = 'http://192.168.100.4:8000';
$cookieFile = __DIR__ . '/cookie_nav.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

echo "=== TESTING NAVIGATION & HEADER CONSISTENCY ACROSS PAGES ===\n\n";

function do_curl(string $url, ?array $postData = null, bool $follow = true): array {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => (string)$body];
}

// 1. Login as Purchase Officer
$loginRes = do_curl("{$baseUrl}/login.php", [
    'email' => 'rana@siovs.edu.pk',
    'password' => 'po123'
]);

$pages = [
    'Dashboard' => "{$baseUrl}/index.php",
    'Purchase Orders' => "{$baseUrl}/page.php?view=purchase_orders",
    'Suppliers' => "{$baseUrl}/page.php?view=suppliers",
    'Inventory' => "{$baseUrl}/page.php?view=inventory",
    'Reports' => "{$baseUrl}/page.php?view=reports&rtype=purchase",
];

foreach ($pages as $pageName => $url) {
    $res = do_curl($url);
    $html = $res['body'];

    assert_true(str_contains($html, '＋ New Purchase Order'), "{$pageName} contains '+ New Purchase Order' header button");
    assert_true(str_contains($html, 'id="alerts-btn"'), "{$pageName} contains alerts-btn");
    assert_true(str_contains($html, 'id="alerts-dropdown"'), "{$pageName} contains alerts-dropdown");
    assert_true(str_contains($html, 'id="spotlight-btn"'), "{$pageName} contains spotlight-btn");
    assert_true(str_contains($html, 'id="spotlight"'), "{$pageName} contains spotlight modal in footer");
    assert_true(str_contains($html, 'Procurement Dashboard'), "{$pageName} contains 'Procurement Dashboard' menu link");
    assert_true(str_contains($html, 'Store Stock &amp; Reorder Levels') || str_contains($html, 'Store Stock & Reorder Levels'), "{$pageName} contains 'Store Stock & Reorder Levels' menu link");
    assert_true(str_contains($html, 'Procurement Reports'), "{$pageName} contains 'Procurement Reports' menu link");
    assert_true(!str_contains($html, '<span class="h-nav-icon">▥</span> Reports</a>'), "{$pageName} does NOT show generic 'Reports' link for Purchase Officer");
}

echo "\n=== TESTING STORE OFFICER CONSISTENCY ===\n\n";

if (file_exists($cookieFile)) unlink($cookieFile);
$storeLogin = do_curl("{$baseUrl}/login.php", [
    'email' => 'imran@siovs.edu.pk',
    'password' => 'store123'
]);

$storePages = [
    'Store Dashboard' => "{$baseUrl}/index.php",
    'Store GRN Page' => "{$baseUrl}/page.php?view=grn",
    'Store Inventory' => "{$baseUrl}/page.php?view=inventory",
];

foreach ($storePages as $name => $url) {
    $res = do_curl($url);
    $html = $res['body'];
    assert_true(str_contains($html, 'id="alerts-btn"'), "{$name} contains alerts-btn");
    assert_true(str_contains($html, 'id="spotlight-btn"'), "{$name} contains spotlight-btn");
    assert_true(str_contains($html, 'GRN'), "{$name} contains GRN menu item");
    assert_true(!str_contains($html, 'Procurement Dashboard'), "{$name} correctly does NOT show Purchase Officer menu");
}

echo "\n=== ALL NAVIGATION CONSISTENCY TESTS PASSED 100% ===\n";

function assert_true(bool $cond, string $msg): void {
    if ($cond) {
        echo "[PASS] {$msg}\n";
    } else {
        echo "[FAIL] {$msg}\n";
        exit(1);
    }
}
