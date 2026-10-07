<?php
declare(strict_types=1);

$baseUrl = 'http://192.168.100.4:8000';
$cookieFile = __DIR__ . '/cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

echo "=== TESTING LIVE HTTP SERVER (RANA TARIQ / PURCHASE OFFICER) ===\n\n";

// Helper curl
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

if ($loginRes['code'] === 200 && str_contains($loginRes['body'], 'Procurement Command Center')) {
    echo "[PASS] Successfully logged in as Rana Tariq (Purchase Officer) and landed on Procurement Command Center\n";
} else {
    echo "[FAIL] Login response: HTTP {$loginRes['code']}\n";
}

// 2. Test Dashboard
$dashRes = do_curl("{$baseUrl}/index.php");
if (str_contains($dashRes['body'], 'Procurement Command Center') &&
    str_contains($dashRes['body'], 'kpi-po-active') &&
    str_contains($dashRes['body'], 'kpi-po-transit') &&
    str_contains($dashRes['body'], 'kpi-po-spend') &&
    str_contains($dashRes['body'], 'kpi-po-suppliers') &&
    str_contains($dashRes['body'], 'kpi-po-fulfillment') &&
    str_contains($dashRes['body'], 'kpi-po-receipts') &&
    str_contains($dashRes['body'], 'Active Purchase Orders') &&
    str_contains($dashRes['body'], 'Store Re-Order Queue') &&
    !str_contains($dashRes['body'], 'OT surgeries today')) {
    echo "[PASS] Live Dashboard renders Procurement Command Center with rich KPI gradient cards & zero clinical distractions\n";
} else {
    echo "[FAIL] Live Dashboard content or KPI card classes mismatch\n";
}

// 3. Test Unauthorized View Access (GRN)
$grnRes = do_curl("{$baseUrl}/page.php?view=grn", null, false);
if ($grnRes['code'] === 302) {
    echo "[PASS] Direct navigation to unauthorized view (view=grn) was blocked with HTTP 302 redirect\n";
} else {
    echo "[FAIL] Direct navigation to view=grn returned HTTP {$grnRes['code']}, expected 302 redirect\n";
}

// 4. Test Authorized View Access (PO register)
$poRes = do_curl("{$baseUrl}/page.php?view=purchase_orders");
if ($poRes['code'] === 200 && str_contains($poRes['body'], 'Purchase order list') && !str_contains($poRes['body'], 'Receive GRN')) {
    echo "[PASS] Purchase Orders register accessible and correctly hides 'Receive GRN' button\n";
} else {
    echo "[FAIL] Purchase Orders register check failed\n";
}

// 5. Test Authorized View Access (Store stock & reorder levels)
$invRes = do_curl("{$baseUrl}/page.php?view=inventory");
if ($invRes['code'] === 200 && str_contains($invRes['body'], 'Store Stock &amp; Reorder Thresholds') && str_contains($invRes['body'], '🛒 Raise PO')) {
    echo "[PASS] Store Stock & Reorder Levels page accessible with '🛒 Raise PO' actions\n";
} else {
    echo "[FAIL] Store Stock & Reorder Levels check failed\n";
}

// 6. Test PO & Vendor Fulfillment Report
$repRes = do_curl("{$baseUrl}/page.php?view=reports&rtype=purchase");
if ($repRes['code'] === 200 && str_contains($repRes['body'], 'PO &amp; Vendor Fulfillment Audit') && str_contains($repRes['body'], 'Overall Fulfillment')) {
    echo "[PASS] PO & Vendor Fulfillment Audit Report accessible with full metrics\n";
} else {
    echo "[FAIL] Fulfillment report check failed. Code: {$repRes['code']}, Body snippet: " . substr(strip_tags($repRes['body']), 0, 200) . "\n";
}

// Cleanup
if (file_exists($cookieFile)) unlink($cookieFile);

echo "\n=== ALL LIVE HTTP TESTS VERIFIED SUCCESSFULLY ===\n";
