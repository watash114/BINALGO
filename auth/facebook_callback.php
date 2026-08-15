<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
start_session();

if (empty($_GET['code']) || empty($_GET['state']) || ($_GET['state'] !== ($_SESSION['oauth_state'] ?? ''))) {
    flash_message('error', 'Facebook login failed. Invalid state or code.');
    redirect('/auth/login.php');
}
unset($_SESSION['oauth_state']);

$appId       = getenv('FACEBOOK_APP_ID');
$appSecret   = getenv('FACEBOOK_APP_SECRET');
$redirectUri = getenv('FACEBOOK_REDIRECT_URI');
if (!$redirectUri) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/auth/facebook_callback.php';
}

if (empty($appId) || empty($appSecret)) {
    flash_message('error', 'Facebook login is not configured.');
    redirect('/auth/login.php');
}

$tokenParams = http_build_query([
    'client_id'     => $appId,
    'client_secret' => $appSecret,
    'redirect_uri'  => $redirectUri,
    'code'          => $_GET['code'],
]);

$ch = curl_init('https://graph.facebook.com/v19.0/oauth/access_token?' . $tokenParams);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$tokenResponse = curl_exec($ch);
curl_close($ch);

$token = json_decode($tokenResponse, true);
if (empty($token['access_token'])) {
    flash_message('error', 'Failed to authenticate with Facebook. Please try again.');
    redirect('/auth/login.php');
}

$ch = curl_init('https://graph.facebook.com/v19.0/me?fields=id,name,email,picture.type(large)&access_token=' . $token['access_token']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$userInfoResponse = curl_exec($ch);
curl_close($ch);

$userInfo = json_decode($userInfoResponse, true);
if (empty($userInfo['email'])) {
    flash_message('error', 'Could not retrieve your Facebook profile information. Please ensure your Facebook account has a verified email.');
    redirect('/auth/login.php');
}

$avatar = $userInfo['picture']['data']['url'] ?? '';
$name   = $userInfo['name'] ?? 'Facebook User';
$email  = $userInfo['email'];
$oid    = $userInfo['id'];

$result = oauth_login('facebook', $oid, $email, $name, $avatar);

if ($result['success']) {
    $role = $result['user']['role'] ?? 'tourist';
    redirect("/{$role}/index.php");
} else {
    flash_message('error', $result['message']);
    redirect('/auth/login.php');
}
