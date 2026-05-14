<?php
// forgot_password.php
session_start();
// Real flow would: verify email, create token, email link.
$_SESSION['auth_success_register'] = 'If this email exists, a reset link would be sent. (Stub endpoint – extend it later.)';
header('Location: index.php');
exit;
