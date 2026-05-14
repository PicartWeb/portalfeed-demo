<?php
// notify.php
// Small helper to create notifications from anywhere.

/**
 * @param PDO    $pdo
 * @param int    $toUser
 * @param int    $fromUser
 * @param string $type      'like' | 'comment' | 'follow' | 'message'
 * @param int    $refId     post_id, comment_id, conversation_id, etc.
 * @param string $message   short text like "liked your post"
 */
function create_notification(PDO $pdo, int $toUser, int $fromUser, string $type, int $refId, string $message): void
{
    if ($toUser === $fromUser) {
        return; // don't notify yourself
    }

    $allowed = ['like','comment','follow','message'];
    if (!in_array($type, $allowed, true)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (to_user_id, from_user_id, type, ref_id, message)
        VALUES (:to, :from, :type, :ref, :msg)
    ");
    $stmt->execute([
        ':to'   => $toUser,
        ':from' => $fromUser,
        ':type' => $type,
        ':ref'  => $refId,
        ':msg'  => $message,
    ]);
}
