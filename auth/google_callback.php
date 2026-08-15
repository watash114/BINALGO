<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
start_session();

if (empty($_GET['code']) || empty($_GET['state']) || ($_GET['state'] !== ($_SESSION['oauth_state'] ?? ''))) {
    flash_message('error', 'Google login failed. Invalid state or code.');
    redirect('/auth/login.php');
}
unset($_SESSION['oauth_state']);

$clientId     = getenv('GOOGLE_CLIENT_ID');
$clientSecret = getenv('GOOGLE_CLIENT_SECRET');
$redirectUri  = getenv('GOOGLE_REDIRECT_URI') ?: (rtrim(getenv('BASE_URL') ?: '', '/') . '/auth/google_callback.php');

if (empty($clientId) || empty($clientSecret)) {
    flash_message('error', 'Google login is not configured.');
    redirect('/auth/login.php');
}

$tokenData = [
    'code'          => $_GET['code'],
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
];

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($tokenData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$tokenResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$token = json_decode($tokenResponse, true);
if ($httpCode !== 200 || empty($token['access_token'])) {
    flash_message('error', 'Failed to authenticate with Google. Please try again.');
    redirect('/auth/login.php');
}

$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_TIMEOUT        => 30,
]);
$userInfoResponse = curl_exec($ch);
curl_close($ch);

$userInfo = json_decode($userInfoResponse, true);
if (empty($userInfo['email'])) {
    flash_message('error', 'Could not retrieve your Google profile information.');
    redirect('/auth/login.php');
}

$avatar = $userInfo['picture'] ?? '';
$name   = $userInfo['name'] ?? ($userInfo['given_name'] ?? 'Google User');
$email  = $userInfo['email'];
$oid    = $userInfo['sub'] ?? $userInfo['id'];

$result = oauth_login('google', $oid, $email, $name, $avatar);

if ($result['success']) {
    $role = $result['user']['role'] ?? 'tourist';
    redirect("/{$role}/index.php");
} else {
    flash_message('error', $result['message']);
    redirect('/auth/login.php');
}
