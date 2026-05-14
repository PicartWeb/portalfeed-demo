<?php
// audit_helper.php
// Simple helper to log admin/staff actions

function audit_log(PDO $pdo, ?int $actorId, string $action, string $details = ''): void
{
    // if actorId is not passed, try from session
    if (!$actorId && isset($_SESSION['user_id'])) {
        $actorId = (int)$_SESSION['user_id'];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO audit_log (actor_id, action, details, ip)
        VALUES (:actor_id, :action, :details, :ip)
    ");
    $stmt->execute([
        ':actor_id' => $actorId,
        ':action'   => $action,
        ':details'  => $details,
        ':ip'       => $ip
    ]);
}
