<?php

function castaneas_bool_env($value) {
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function castaneas_json_env_array($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function castaneas_integrations_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'base_url' => getenv('CASTANEAS_BASE_URL') ?: '',
        'payment_simulate' => castaneas_bool_env(getenv('CASTANEAS_PAYMENT_SIMULATE') ?: ''),
        'up2pay' => [
            'site' => getenv('CASTANEAS_UP2PAY_SITE') ?: '',
            'rang' => getenv('CASTANEAS_UP2PAY_RANG') ?: '',
            'identifiant' => getenv('CASTANEAS_UP2PAY_IDENTIFIANT') ?: '',
            'hmac_key' => getenv('CASTANEAS_UP2PAY_HMAC_KEY') ?: '',
            'callback_secret' => getenv('CASTANEAS_UP2PAY_CALLBACK_SECRET') ?: '',
            'gateway_url' => getenv('CASTANEAS_UP2PAY_GATEWAY_URL') ?: 'https://recette-tpeweb.e-transactions.fr/php/',
            'currency' => getenv('CASTANEAS_UP2PAY_CURRENCY') ?: '978',
            'language' => getenv('CASTANEAS_UP2PAY_LANGUAGE') ?: 'FRA',
            'hash_algo' => getenv('CASTANEAS_UP2PAY_HASH_ALGO') ?: 'SHA512',
        ],
        'sucrine' => [
            'api_key' => getenv('CASTANEAS_SUCRINE_API_KEY') ?: '',
            'base_url' => getenv('CASTANEAS_SUCRINE_BASE_URL') ?: 'https://app.sucrine.club/api',
            'order_source' => getenv('CASTANEAS_SUCRINE_ORDER_SOURCE') ?: 'castaneas',
            'skip_precise_supply_check' => !getenv('CASTANEAS_SUCRINE_ENFORCE_STOCK'),
            'delivery_point' => getenv('CASTANEAS_SUCRINE_DELIVERY_POINT') ?: '',
            'delivery_point_home' => getenv('CASTANEAS_SUCRINE_DELIVERY_POINT_HOME') ?: '',
            'delivery_point_relay' => getenv('CASTANEAS_SUCRINE_DELIVERY_POINT_RELAY') ?: '',
            'delivery_points' => castaneas_json_env_array(getenv('CASTANEAS_SUCRINE_DELIVERY_POINTS') ?: ''),
        ],
        'sendcloud' => [
            'public_key' => getenv('CASTANEAS_SENDCLOUD_PUBLIC_KEY') ?: '',
            'secret_key' => getenv('CASTANEAS_SENDCLOUD_SECRET_KEY') ?: '',
            'base_url' => getenv('CASTANEAS_SENDCLOUD_BASE_URL') ?: 'https://panel.sendcloud.sc/api/v2',
            'sender_address' => getenv('CASTANEAS_SENDCLOUD_SENDER_ADDRESS') ?: '',
            'ca_bundle' => getenv('CASTANEAS_SENDCLOUD_CA_BUNDLE') ?: '',
            'skip_ssl_verify' => castaneas_bool_env(getenv('CASTANEAS_SENDCLOUD_SKIP_SSL_VERIFY') ?: ''),
            'shipping_method_id' => getenv('CASTANEAS_SENDCLOUD_SHIPPING_METHOD_ID') ?: '',
            'shipping_method_name' => getenv('CASTANEAS_SENDCLOUD_SHIPPING_METHOD_NAME') ?: '',
            'request_label' => castaneas_bool_env(getenv('CASTANEAS_SENDCLOUD_REQUEST_LABEL') ?: ''),
            'apply_shipping_rules' => !getenv('CASTANEAS_SENDCLOUD_DISABLE_RULES'),
        ],
    ];

    $configFiles = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'castaneas-config' . DIRECTORY_SEPARATOR . 'integrations.php',
        __DIR__ . '/integrations.local.php',
    ];

    foreach ($configFiles as $configFile) {
        if (!is_file($configFile)) {
            continue;
        }

        $loaded = require $configFile;
        if (!is_array($loaded)) {
            continue;
        }

        $config = array_replace_recursive($config, $loaded);
    }

    return $config;
}

function castaneas_base_url() {
    $config = castaneas_integrations_config();
    if ($config['base_url'] !== '') {
        return rtrim($config['base_url'], '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function castaneas_url($path) {
    return castaneas_base_url() . '/' . ltrim($path, '/');
}

function castaneas_up2pay_config() {
    $config = castaneas_integrations_config();

    return $config['up2pay'];
}

function castaneas_up2pay_is_ready() {
    $config = castaneas_up2pay_config();

    return $config['site'] !== ''
        && $config['rang'] !== ''
        && $config['identifiant'] !== ''
        && $config['hmac_key'] !== ''
        && $config['gateway_url'] !== '';
}

function castaneas_payment_simulate() {
    $config = castaneas_integrations_config();

    return !empty($config['payment_simulate']);
}

function castaneas_sucrine_config() {
    $config = castaneas_integrations_config();

    return $config['sucrine'];
}

function castaneas_sucrine_is_ready() {
    $config = castaneas_sucrine_config();

    return $config['api_key'] !== '';
}

function castaneas_sendcloud_config() {
    $config = castaneas_integrations_config();

    return $config['sendcloud'];
}

function castaneas_sendcloud_is_ready() {
    $config = castaneas_sendcloud_config();

    return $config['public_key'] !== ''
        && $config['secret_key'] !== ''
        && $config['base_url'] !== '';
}