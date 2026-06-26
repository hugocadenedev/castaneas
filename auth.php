<?php

require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function castaneas_auth_start_session() {
    static $started = false;
    if ($started) {
        return;
    }

    session_name('castaneas_customer');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
    $started = true;
}

function castaneas_auth_response($status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function castaneas_auth_body() {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function castaneas_auth_normalize_email($value) {
    return strtolower(trim((string) $value));
}

function castaneas_auth_user_payload(array $user) {
    return [
        'id' => (int) ($user['id'] ?? 0),
        'email' => (string) ($user['email'] ?? ''),
        'prenom' => (string) ($user['first_name'] ?? ''),
        'nom' => (string) ($user['last_name'] ?? ''),
    ];
}

function castaneas_auth_db() {
    $pdo = castaneas_db();
    if (!$pdo) {
        castaneas_auth_response(503, [
            'ok' => false,
            'code' => 'auth_storage_unavailable',
            'message' => 'La base de donnees clients est indisponible.',
        ]);
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS customer_accounts (' .
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,' .
        'email VARCHAR(190) NOT NULL,' .
        'password_hash VARCHAR(255) NOT NULL,' .
        'first_name VARCHAR(120) NOT NULL,' .
        'last_name VARCHAR(120) NOT NULL,' .
        'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
        'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
        'last_login_at TIMESTAMP NULL DEFAULT NULL,' .
        'UNIQUE KEY uniq_customer_accounts_email (email)' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    return $pdo;
}

function castaneas_auth_find_user_by_email(PDO $pdo, $email) {
    $stmt = $pdo->prepare('SELECT * FROM customer_accounts WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function castaneas_auth_store_session(array $user) {
    castaneas_auth_start_session();
    session_regenerate_id(true);
    $_SESSION['castaneas_customer'] = castaneas_auth_user_payload($user);
}

function castaneas_auth_current_user() {
    castaneas_auth_start_session();
    $user = $_SESSION['castaneas_customer'] ?? null;

    return is_array($user) ? $user : null;
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'session')));
$body = castaneas_auth_body();

if ($action === 'session') {
    $user = castaneas_auth_current_user();
    castaneas_auth_response(200, [
        'ok' => true,
        'loggedIn' => $user !== null,
        'user' => $user,
    ]);
}

if ($action === 'logout') {
    castaneas_auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    castaneas_auth_response(200, [
        'ok' => true,
        'loggedIn' => false,
        'user' => null,
    ]);
}

$pdo = castaneas_auth_db();

if ($action === 'register') {
    $prenom = trim((string) ($body['prenom'] ?? ''));
    $nom = trim((string) ($body['nom'] ?? ''));
    $email = castaneas_auth_normalize_email($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if ($prenom === '' || $nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        castaneas_auth_response(422, [
            'ok' => false,
            'code' => 'invalid_register_payload',
            'message' => 'Informations de creation de compte invalides.',
        ]);
    }

    if (castaneas_auth_find_user_by_email($pdo, $email)) {
        castaneas_auth_response(409, [
            'ok' => false,
            'code' => 'account_exists',
            'message' => 'Un compte existe deja avec cet e-mail.',
        ]);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO customer_accounts (email, password_hash, first_name, last_name) ' .
        'VALUES (:email, :password_hash, :first_name, :last_name)'
    );
    $stmt->execute([
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'first_name' => $prenom,
        'last_name' => $nom,
    ]);

    $user = castaneas_auth_find_user_by_email($pdo, $email);
    castaneas_auth_store_session($user);

    castaneas_auth_response(201, [
        'ok' => true,
        'loggedIn' => true,
        'user' => castaneas_auth_user_payload($user),
    ]);
}

if ($action === 'login') {
    $email = castaneas_auth_normalize_email($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        castaneas_auth_response(422, [
            'ok' => false,
            'code' => 'invalid_login_payload',
            'message' => 'Informations de connexion invalides.',
        ]);
    }

    $user = castaneas_auth_find_user_by_email($pdo, $email);
    if (!$user || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        castaneas_auth_response(401, [
            'ok' => false,
            'code' => 'invalid_credentials',
            'message' => 'E-mail ou mot de passe incorrect.',
        ]);
    }

    $pdo->prepare('UPDATE customer_accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['id' => $user['id']]);
    $user['last_login_at'] = gmdate('c');

    castaneas_auth_store_session($user);

    castaneas_auth_response(200, [
        'ok' => true,
        'loggedIn' => true,
        'user' => castaneas_auth_user_payload($user),
    ]);
}

castaneas_auth_response(404, [
    'ok' => false,
    'code' => 'unknown_action',
    'message' => 'Action inconnue.',
]);
