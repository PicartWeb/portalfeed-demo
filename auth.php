<?php
// auth.php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require __DIR__ . '/db.php';

$userId = (int)$_SESSION['user_id'];

/* --- CHECK ACTIVE BAN --- */
$banStmt = $pdo->prepare("
    SELECT reason, expires_at
    FROM user_bans
    WHERE user_id = :uid AND active = 1
    ORDER BY created_at DESC
    LIMIT 1
");
$banStmt->execute([':uid' => $userId]);
$ban = $banStmt->fetch();

if ($ban) {

    $expired = $ban['expires_at'] && strtotime($ban['expires_at']) < time();

    if ($expired) {
        // auto-unban
        $pdo->prepare("UPDATE user_bans SET active = 0 WHERE user_id = :uid")
            ->execute([':uid' => $userId]);
    } else {
        // still banned → kill session + show message
        session_destroy();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Access Denied</title>
            <meta charset="UTF-8">
            <style>
                body {
                    background:#020313;
                    color:#f5f5ff;
                    font-family:system-ui,-apple-system,sans-serif;
                    display:flex;
                    justify-content:center;
                    align-items:center;
                    min-height:100vh;
                }
                .box {
                    background:#07041c;
                    padding:1.5rem 1.7rem;
                    border-radius:16px;
                    max-width:400px;
                    text-align:center;
                    border:1px solid rgba(255,255,255,.15);
                }
                a {
                    color:#0ff0fc;
                }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>Account Suspended</h2>
                <p><strong>Reason:</strong> <?= htmlspecialchars($ban['reason']) ?></p>

                <?php if ($ban['expires_at']): ?>
                    <p><strong>Until:</strong> <?= htmlspecialchars($ban['expires_at']) ?></p>
                <?php else: ?>
                    <p>This suspension is permanent.</p>
                <?php endif; ?>

                <p style="font-size:.85rem;color:#a1a6d9;">
                    If you believe this is a mistake, please contact support or an administrator.
                </p>

                <a href="index.php">Back to login</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
