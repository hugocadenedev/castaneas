<?php

function castaneas_admin_token() {
    $token = getenv('CASTANEAS_ADMIN_TOKEN');
    if (is_string($token) && $token !== '') {
        return $token;
    }

    return 'cas_srv_9e4f2b8d3a7c1065';
}

function castaneas_allowed_keys() {
    return ['products', 'categories', 'orders', 'recipes', 'homepage', 'packagings', 'promo_codes', 'blog_posts', 'blog_categories', 'billing_settings', 'admin_accounts'];
}

function castaneas_storage_bool_env($value) {
    if (is_bool($value)) {
        return $value;
    }

    $value = strtolower(trim((string) $value));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function castaneas_storage_json_fallback_allowed() {
    return castaneas_storage_bool_env(getenv('CASTANEAS_ALLOW_JSON_FALLBACK') ?: '');
}

function castaneas_storage_requires_mysql($key) {
    return in_array($key, castaneas_allowed_keys(), true) && !castaneas_storage_json_fallback_allowed();
}

function castaneas_storage_key_status($key) {
    $requiresMysql = castaneas_storage_requires_mysql($key);
    $pdo = castaneas_db();
    $jsonAvailable = castaneas_json_read_raw($key) !== null;

    $status = [
        'key' => $key,
        'requires_mysql' => $requiresMysql,
        'db_connected' => $pdo !== null,
        'json_available' => $jsonAvailable,
        'backend' => $pdo ? 'mysql' : ($jsonAvailable ? 'json' : 'missing'),
        'error' => null,
    ];

    if ($requiresMysql && !$pdo) {
        $status['backend'] = 'unavailable';
        $status['error'] = 'MySQL required for key `' . $key . '` but connection is unavailable.';
    }

    return $status;
}

function castaneas_db_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'host' => getenv('CASTANEAS_DB_HOST') ?: '',
        'port' => getenv('CASTANEAS_DB_PORT') ?: '3306',
        'name' => getenv('CASTANEAS_DB_NAME') ?: '',
        'user' => getenv('CASTANEAS_DB_USER') ?: '',
        'password' => getenv('CASTANEAS_DB_PASSWORD') ?: '',
        'charset' => getenv('CASTANEAS_DB_CHARSET') ?: 'utf8mb4',
    ];

    $configFiles = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'castaneas-config' . DIRECTORY_SEPARATOR . 'db-config.php',
        __DIR__ . '/db-config.local.php',
    ];

    foreach ($configFiles as $configFile) {
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
            }
        }
    }

    return $config;
}

function castaneas_db_enabled() {
    $config = castaneas_db_config();

    return extension_loaded('pdo_mysql')
        && $config['host'] !== ''
        && $config['name'] !== ''
        && $config['user'] !== '';
}

function castaneas_db_config_sources() {
    return [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'castaneas-config' . DIRECTORY_SEPARATOR . 'db-config.php',
        __DIR__ . '/db-config.local.php',
    ];
}

function castaneas_db_config_file_found() {
    foreach (castaneas_db_config_sources() as $configFile) {
        if (is_file($configFile)) {
            return true;
        }
    }

    return false;
}

function castaneas_db() {
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }

    $pdo = null;
    if (!castaneas_db_enabled()) {
        return $pdo;
    }

    $config = castaneas_db_config();

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        $pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }

    return $pdo;
}

function castaneas_db_init_schema($pdo) {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS content_store (' .
        'store_key VARCHAR(50) NOT NULL PRIMARY KEY,' .
        'payload_json LONGTEXT NOT NULL,' .
        'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' .
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $initialized = true;
}

function castaneas_db_read_raw($key) {
    $pdo = castaneas_db();
    if (!$pdo) {
        return null;
    }

    castaneas_db_init_schema($pdo);
    $stmt = $pdo->prepare('SELECT payload_json FROM content_store WHERE store_key = :store_key LIMIT 1');
    $stmt->execute(['store_key' => $key]);
    $row = $stmt->fetch();

    return $row ? $row['payload_json'] : null;
}

function castaneas_db_write_raw($key, $rawJson) {
    $pdo = castaneas_db();
    if (!$pdo) {
        return false;
    }

    castaneas_db_init_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO content_store (store_key, payload_json) VALUES (:store_key, :payload_json) ' .
        'ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = CURRENT_TIMESTAMP'
    );

    return $stmt->execute([
        'store_key' => $key,
        'payload_json' => $rawJson,
    ]);
}

function castaneas_json_directories() {
    $configDir = getenv('CASTANEAS_DATA_DIR');
    $dirs = [
        $configDir ? rtrim($configDir, '/\\') : null,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'castaneas-data',
        __DIR__ . '/data',
        __DIR__ . '/uploads/data',
    ];

    return array_values(array_unique(array_filter($dirs)));
}

function castaneas_json_read_raw($key) {
    foreach (castaneas_json_directories() as $dir) {
        $file = $dir . '/' . $key . '.json';
        if (!is_file($file)) {
            continue;
        }

        $raw = file_get_contents($file);
        if ($raw !== false) {
            return $raw;
        }
    }

    return null;
}

function castaneas_json_write_raw($key, $rawJson) {
    foreach (castaneas_json_directories() as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            continue;
        }
        if (!is_writable($dir)) {
            continue;
        }

        $file = $dir . '/' . $key . '.json';
        return file_put_contents($file, $rawJson, LOCK_EX) !== false;
    }

    return false;
}

function castaneas_storage_backend() {
    if (castaneas_db()) {
        return 'mysql';
    }

    if (castaneas_storage_json_fallback_allowed()) {
        return 'json';
    }

    return 'unavailable';
}

function castaneas_storage_read_raw($key) {
    $status = castaneas_storage_key_status($key);
    if ($status['error'] !== null) {
        return null;
    }

    $raw = castaneas_db_read_raw($key);
    if ($raw !== null) {
        return $raw;
    }

    if ($status['requires_mysql']) {
        return null;
    }

    $raw = castaneas_json_read_raw($key);
    if ($raw !== null && castaneas_db()) {
        castaneas_db_write_raw($key, $raw);
    }

    return $raw;
}

function castaneas_storage_write_raw($key, $rawJson) {
    $status = castaneas_storage_key_status($key);
    if ($status['error'] !== null) {
        return false;
    }

    if (castaneas_db()) {
        return castaneas_db_write_raw($key, $rawJson);
    }

    if ($status['requires_mysql']) {
        return false;
    }

    return castaneas_json_write_raw($key, $rawJson);
}

function castaneas_storage_status() {
    $config = castaneas_db_config();
    $pdo = castaneas_db();
    $tableExists = false;
    $keys = [];

    if ($pdo) {
        castaneas_db_init_schema($pdo);
        $tableExists = true;

        try {
            $stmt = $pdo->query('SELECT store_key, updated_at FROM content_store ORDER BY store_key ASC');
            $keys = $stmt->fetchAll();
        } catch (Throwable $e) {
            $tableExists = false;
        }
    }

    return [
        'backend' => castaneas_storage_backend(),
        'json_fallback_allowed' => castaneas_storage_json_fallback_allowed(),
        'db_config_file_found' => castaneas_db_config_file_found(),
        'db_env_configured' => ($config['host'] !== '' && $config['name'] !== '' && $config['user'] !== ''),
        'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
        'db_connected' => $pdo !== null,
        'content_store_ready' => $tableExists,
        'stored_keys' => $keys,
        'key_statuses' => array_map('castaneas_storage_key_status', castaneas_allowed_keys()),
        'json_dirs' => castaneas_json_directories(),
    ];
}