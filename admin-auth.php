<?php
// ============================================================
//  CASTANEAS — admin-auth.php
//  Session d'administration côté serveur pour le back-office.
//
//  GET  /admin-auth.php?action=status   → { loggedIn, admin, setupRequired }
//  POST /admin-auth.php?action=login    { username, password }
//  POST /admin-auth.php?action=setup    { username, password }  (uniquement si aucun compte)
//  POST /admin-auth.php?action=logout
// ============================================================

require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function castaneas_admin_auth_response($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function castaneas_admin_public(array $account) {
    return [
        'id' => (string) ($account['id'] ?? ''),
        'username' => (string) ($account['username'] ?? ''),
        'role' => (string) ($account['role'] ?? 'editor'),
    ];
}

function castaneas_admin_start_login(array $account) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        castaneas_admin_session_open();
    }
    session_regenerate_id(true);
    $_SESSION['castaneas_admin'] = castaneas_admin_public($account) + ['loginAt' => time()];
    session_write_close();
}

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'status') {
    $admin = castaneas_admin_current();
    castaneas_admin_auth_response(200, [
        'ok' => true,
        'loggedIn' => $admin !== null,
        'admin' => $admin ? castaneas_admin_public($admin) : null,
        'setupRequired' => !castaneas_admin_accounts(),
    ]);
}

if ($method !== 'POST' || ($_SERVER['HTTP_X_CASTANEAS_ADMIN'] ?? '') !== '1') {
    castaneas_admin_auth_response(405, ['ok' => false, 'error' => 'Requête non autorisée.']);
}

if ($action === 'logout') {
    if (!empty($_COOKIE[CASTANEAS_ADMIN_SESSION_NAME])) {
        castaneas_admin_session_open();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
    castaneas_admin_auth_response(200, ['ok' => true, 'loggedIn' => false]);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($action === 'login') {
    $needle = strtolower($username);
    $hash = castaneas_admin_password_hash($password);
    foreach (castaneas_admin_accounts() as $account) {
        $stored = trim((string) ($account['pass'] ?? ''));
        $accountUser = strtolower(trim((string) ($account['username'] ?? ($account['user'] ?? ''))));
        if ($needle !== '' && $accountUser === $needle && $stored !== '' && hash_equals($stored, $hash)) {
            $account['username'] = trim((string) ($account['username'] ?? ($account['user'] ?? '')));
            castaneas_admin_start_login($account);
            castaneas_admin_auth_response(200, ['ok' => true, 'loggedIn' => true, 'admin' => castaneas_admin_public($account)]);
        }
    }
    // Ralentit les essais de mot de passe en série
    sleep(1);
    castaneas_admin_auth_response(401, ['ok' => false, 'error' => 'Identifiant ou mot de passe incorrect.']);
}

if ($action === 'setup') {
    if (castaneas_admin_accounts()) {
        castaneas_admin_auth_response(409, ['ok' => false, 'error' => 'Un compte administrateur existe déjà.']);
    }
    if ($username === '' || strlen($password) < 8) {
        castaneas_admin_auth_response(400, ['ok' => false, 'error' => 'Choisissez un nom d\'utilisateur et un mot de passe (8 caractères min).']);
    }
    $now = gmdate('c');
    $account = [
        'id' => 'admin-' . round(microtime(true) * 1000),
        'username' => $username,
        'displayName' => $username,
        'role' => 'super_admin',
        'pass' => castaneas_admin_password_hash($password),
        'createdAt' => $now,
        'updatedAt' => $now,
    ];
    if (!castaneas_storage_write_raw('admin_accounts', json_encode([$account], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
        castaneas_admin_auth_response(500, ['ok' => false, 'error' => 'Impossible d\'enregistrer le compte.']);
    }
    castaneas_admin_start_login($account);
    castaneas_admin_auth_response(200, ['ok' => true, 'loggedIn' => true, 'admin' => castaneas_admin_public($account)]);
}

castaneas_admin_auth_response(400, ['ok' => false, 'error' => 'Action invalide.']);
