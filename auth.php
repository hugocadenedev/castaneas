<?php

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/order-store.php';

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

function castaneas_auth_find_user_by_id(PDO $pdo, $id) {
    $stmt = $pdo->prepare('SELECT * FROM customer_accounts WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $id]);
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

function castaneas_auth_require_user(PDO $pdo) {
    $sessionUser = castaneas_auth_current_user();
    if (!$sessionUser || empty($sessionUser['email'])) {
        castaneas_auth_response(401, [
            'ok' => false,
            'code' => 'not_authenticated',
            'message' => 'Connexion requise.',
        ]);
    }

    $user = castaneas_auth_find_user_by_email($pdo, castaneas_auth_normalize_email($sessionUser['email']));
    if (!$user) {
        castaneas_auth_response(401, [
            'ok' => false,
            'code' => 'account_not_found',
            'message' => 'Compte introuvable.',
        ]);
    }

    return $user;
}

function castaneas_auth_order_status_label($status) {
    $labels = [
        'pending_payment' => 'En attente de paiement',
        'paid' => 'Payee',
        'processing' => 'En preparation',
        'payment_failed' => 'Paiement echoue',
        'payment_refused' => 'Paiement refuse',
        'cancelled' => 'Annulee',
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

function castaneas_auth_order_summary(array $order) {
    $items = [];
    foreach (($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $items[] = [
            'name' => (string) ($item['name'] ?? 'Produit'),
            'qty' => (int) ($item['qty'] ?? 0),
            'price' => round((float) ($item['price'] ?? 0), 2),
            'image' => (string) ($item['image'] ?? ''),
        ];
    }

    return [
        'id' => (string) ($order['id'] ?? ''),
        'status' => (string) ($order['status'] ?? 'pending_payment'),
        'statusLabel' => castaneas_auth_order_status_label($order['status'] ?? ''),
        'total' => round((float) ($order['total'] ?? 0), 2),
        'subtotal' => round((float) ($order['subtotal'] ?? 0), 2),
        'createdAt' => (string) ($order['createdAt'] ?? ''),
        'paidAt' => (string) ($order['paidAt'] ?? ''),
        'items' => $items,
        'sendcloud' => [
            'trackingNumber' => $order['sendcloud']['trackingNumber'] ?? null,
            'labelUrl' => $order['sendcloud']['labelUrl'] ?? null,
        ],
        'billing' => [
            'prenom' => (string) ($order['billing']['prenom'] ?? ''),
            'nom' => (string) ($order['billing']['nom'] ?? ''),
            'email' => (string) ($order['billing']['email'] ?? ''),
            'tel' => (string) ($order['billing']['tel'] ?? ''),
            'adresse' => (string) ($order['billing']['adresse'] ?? ''),
            'complement' => (string) ($order['billing']['complement'] ?? ''),
            'cp' => (string) ($order['billing']['cp'] ?? ''),
            'ville' => (string) ($order['billing']['ville'] ?? ''),
            'pays' => (string) ($order['billing']['pays'] ?? ''),
        ],
        'shipping' => $order['shipping'] ?? [],
    ];
}

function castaneas_auth_customer_orders($email) {
    $email = castaneas_auth_normalize_email($email);
    $orders = [];

    foreach (castaneas_orders_all() as $order) {
        if (!is_array($order)) {
            continue;
        }
        $orderEmail = castaneas_auth_normalize_email($order['email'] ?? ($order['billing']['email'] ?? ''));
        if ($orderEmail !== '' && $orderEmail === $email) {
            $orders[] = castaneas_auth_order_summary($order);
        }
    }

    usort($orders, static function ($left, $right) {
        return strcmp((string) ($right['createdAt'] ?? ''), (string) ($left['createdAt'] ?? ''));
    });

    return $orders;
}

function castaneas_auth_latest_addresses(array $orders) {
    if (!$orders) {
        return ['billing' => null, 'shipping' => null];
    }

    $latest = $orders[0];
    $shipping = is_array($latest['shipping'] ?? null) ? $latest['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $servicePointAddress = is_array($servicePoint['address'] ?? null) ? $servicePoint['address'] : [];

    $shippingAddress = null;
    if ($servicePoint) {
        $shippingAddress = [
            'name' => (string) ($servicePoint['name'] ?? ''),
            'carrier' => (string) ($servicePoint['carrier']['name'] ?? ''),
            'address' => trim(implode(' ', array_filter([
                (string) ($servicePointAddress['street'] ?? ''),
                (string) ($servicePointAddress['houseNumber'] ?? ''),
            ]))),
            'zipcode' => (string) ($servicePointAddress['postalCode'] ?? ''),
            'city' => (string) ($servicePointAddress['city'] ?? ''),
            'country' => (string) ($servicePointAddress['countryCode'] ?? ''),
        ];
    } else {
        $billing = is_array($latest['billing'] ?? null) ? $latest['billing'] : [];
        $shippingAddress = [
            'name' => trim((string) (($billing['prenom'] ?? '') . ' ' . ($billing['nom'] ?? ''))),
            'carrier' => (string) ($shipping['carrier']['name'] ?? ''),
            'address' => (string) ($billing['adresse'] ?? ''),
            'addressExtra' => (string) ($billing['complement'] ?? ''),
            'zipcode' => (string) ($billing['cp'] ?? ''),
            'city' => (string) ($billing['ville'] ?? ''),
            'country' => (string) ($billing['pays'] ?? ''),
        ];
    }

    return [
        'billing' => $latest['billing'] ?? null,
        'shipping' => $shippingAddress,
    ];
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

if ($action === 'account') {
    $user = castaneas_auth_require_user($pdo);
    $orders = castaneas_auth_customer_orders($user['email'] ?? '');
    $totals = [
        'ordersCount' => count($orders),
        'lifetimeValue' => array_reduce($orders, static function ($sum, $order) {
            return $sum + (float) ($order['total'] ?? 0);
        }, 0.0),
    ];

    castaneas_auth_response(200, [
        'ok' => true,
        'user' => castaneas_auth_user_payload($user),
        'orders' => $orders,
        'stats' => $totals,
        'addresses' => castaneas_auth_latest_addresses($orders),
    ]);
}

if ($action === 'update_profile') {
    $user = castaneas_auth_require_user($pdo);
    $prenom = trim((string) ($body['prenom'] ?? ''));
    $nom = trim((string) ($body['nom'] ?? ''));

    if ($prenom === '' || $nom === '') {
        castaneas_auth_response(422, [
            'ok' => false,
            'code' => 'invalid_profile_payload',
            'message' => 'Prenom et nom requis.',
        ]);
    }

    $pdo->prepare('UPDATE customer_accounts SET first_name = :first_name, last_name = :last_name WHERE id = :id')
        ->execute([
            'first_name' => $prenom,
            'last_name' => $nom,
            'id' => $user['id'],
        ]);

    $updatedUser = castaneas_auth_find_user_by_id($pdo, $user['id']);
    castaneas_auth_store_session($updatedUser);

    castaneas_auth_response(200, [
        'ok' => true,
        'user' => castaneas_auth_user_payload($updatedUser),
        'message' => 'Profil mis a jour.',
    ]);
}

if ($action === 'change_password') {
    $user = castaneas_auth_require_user($pdo);
    $currentPassword = (string) ($body['currentPassword'] ?? '');
    $newPassword = (string) ($body['newPassword'] ?? '');

    if ($currentPassword === '' || strlen($newPassword) < 8) {
        castaneas_auth_response(422, [
            'ok' => false,
            'code' => 'invalid_password_payload',
            'message' => 'Mot de passe actuel requis et nouveau mot de passe de 8 caracteres minimum.',
        ]);
    }

    if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
        castaneas_auth_response(401, [
            'ok' => false,
            'code' => 'invalid_current_password',
            'message' => 'Mot de passe actuel incorrect.',
        ]);
    }

    $pdo->prepare('UPDATE customer_accounts SET password_hash = :password_hash WHERE id = :id')
        ->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $user['id'],
        ]);

    $updatedUser = castaneas_auth_find_user_by_id($pdo, $user['id']);
    castaneas_auth_store_session($updatedUser);

    castaneas_auth_response(200, [
        'ok' => true,
        'user' => castaneas_auth_user_payload($updatedUser),
        'message' => 'Mot de passe mis a jour.',
    ]);
}

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
