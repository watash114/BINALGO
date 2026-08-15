<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
start_session();

$appId       = getenv('FACEBOOK_APP_ID');
$redirectUri = getenv('FACEBOOK_REDIRECT_URI') ?: (rtrim(getenv('BASE_URL') ?: '', '/') . '/auth/facebook_callback.php');

if (empty($appId)) {
    flash_message('error', 'Facebook login is not configured. Please use email/password instead.');
    redirect('/auth/login.php');
}

$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

$params = http_build_query([
    'client_id'     => $appId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'email,public_profile',
    'state'         => $_SESSION['oauth_state'],
]);

header('Location: https://www.facebook.com/v19.0/dialog/oauth?' . $params);
exit;
