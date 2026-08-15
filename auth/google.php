<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
start_session();

$clientId = getenv('GOOGLE_CLIENT_ID');
$redirectUri = getenv('GOOGLE_REDIRECT_URI');
if (!$redirectUri) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/auth/google_callback.php';
}
$scope = 'openid email profile';

if (empty($clientId)) {
    flash_message('error', 'Google login is not configured. Please use email/password instead.');
    redirect('/auth/login.php');
}

$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

$params = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => $scope,
    'state'         => $_SESSION['oauth_state'],
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
