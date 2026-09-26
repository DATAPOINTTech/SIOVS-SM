<?php
require __DIR__ . '/config/bootstrap.php';
if (is_logged_in()) {
    log_activity($pdo, 'logout', 'user', (int)(current_user()['id'] ?? 0));
    $pdo->prepare('UPDATE user_sessions SET ended_at=CURRENT_TIMESTAMP, last_seen_at=CURRENT_TIMESTAMP WHERE session_id=? AND ended_at IS NULL')
        ->execute([session_id()]);
}
session_destroy();
header('Location: login.php');
exit;
