<?php
require __DIR__ . '/config/bootstrap.php';
if (is_logged_in()) { header('Location: index.php'); exit; }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = "Active"');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['password_hash'] === null || password_verify($password, (string) $row['password_hash']))) {
        $_SESSION['siovs_user'] = $row;
        $pdo->prepare('INSERT OR REPLACE INTO user_sessions (session_id,user_id,ip_address,user_agent,created_at) VALUES(?,?,?,?,CURRENT_TIMESTAMP)')
            ->execute([session_id(), $row['id'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
        log_activity($pdo, 'login', 'user', (int)$row['id']);
        header('Location: index.php');
        exit;
    }
    log_activity($pdo, 'login_failed', 'user', null, ['email' => $email]);
    $error = 'Invalid email or password. See demo credentials below.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · SIOVS Store Management</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/system.css">
    <link rel="stylesheet" href="assets/green-theme.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="brand">
        <img class="brand-logo login-logo" src="https://siovs.edu.pk/wp-content/uploads/2022/09/logo-main.png" alt="SIOVS logo">
        <span><strong>SIOVS</strong><small>Hyderabad · Operations Hub</small></span>
    </div>
    <p class="eyebrow">SINDH INSTITUTE OF OPHTHALMOLOGY &amp; VISUAL SCIENCES</p>
    <h1>Welcome back</h1>
    <p class="login-copy">Sign in to manage the complete store-to-patient flow.</p>
    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <label>Email address<input type="email" name="email" value="ayaz@siovs.edu.pk" required autocomplete="username"></label>
        <label>Password<input type="password" name="password" value="admin123" required autocomplete="current-password"></label>
        <button class="button button-primary" type="submit">Sign in to SIOVS →</button>
    </form>
    <div class="demo-credentials">
        <p class="eyebrow">DEMO CREDENTIALS</p>
        <table>
            <tr><th>Role</th><th>Email</th><th>Password</th></tr>
            <tr><td>Admin</td><td>ayaz@siovs.edu.pk</td><td>admin123</td></tr>
            <tr><td>Store Officer</td><td>imran@siovs.edu.pk</td><td>store123</td></tr>
            <tr><td>Purchase Officer</td><td>rana@siovs.edu.pk</td><td>po123</td></tr>
            <tr><td>Dept. Incharge</td><td>sara@siovs.edu.pk</td><td>dept123</td></tr>
            <tr><td>DD Finance</td><td>zahid@siovs.edu.pk</td><td>fin123</td></tr>
            <tr><td>Internal Audit</td><td>audit@siovs.edu.pk</td><td>audit123</td></tr>
        </table>
    </div>
    <a class="site-link" href="https://siovs.edu.pk" target="_blank" rel="noopener">Visit siovs.edu.pk ↗</a>
</div>
</body>
</html>
