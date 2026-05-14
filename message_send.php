<?php
// message_send.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: index.php');
    exit;
}

$convId = (int)($_POST['conversation_id'] ?? 0);
$body   = trim($_POST['body'] ?? '');

if ($convId <= 0 || $body === '') {
    header('Location: messages.php');
    exit;
}

// check conversation and find other user
$stmt = $pdo->prepare("
  SELECT *,
         CASE WHEN user1_id = :me1 THEN user2_id ELSE user1_id END AS other_id
  FROM conversations
  WHERE id = :cid
    AND (user1_id = :me2 OR user2_id = :me3)
  LIMIT 1
");
$stmt->execute([
    ':cid' => $convId,
    ':me1' => $userId,
    ':me2' => $userId,
    ':me3' => $userId,
]);
$conv = $stmt->fetch();

if (!$conv) {
    header('Location: messages.php');
    exit;
}

$otherId = (int)$conv['other_id'];

// insert message
$ins = $pdo->prepare("
  INSERT INTO messages (conversation_id, sender_id, body)
  VALUES (:cid, :sid, :body)
");
$ins->execute([
    ':cid'  => $convId,
    ':sid'  => $userId,
    ':body' => $body,
]);

// notification for receiver
create_notification(
    $pdo,
    $otherId,
    $userId,
    'message',
    $convId,
    'sent you a message'
);

header('Location: messages.php?conversation_id=' . $convId);
exit;
