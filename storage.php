<?php

function castaneas_admin_token() {
    $token = getenv('CASTANEAS_ADMIN_TOKEN');
    if (is_string($token) && $token !== '') {
        return $token;
    }

    return 'cas_srv_9e4f2b8d3a7c1065';
}

function castaneas_allowed_keys() {
    return ['products', 'categories', 'orders', 'recipes', 'homepage'];
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

    $localConfig = __DIR__ . '/db-config.local.php';
    if (is_file($localConfig)) {
        $loaded = require $localConfig;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
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
    return castaneas_db() ? 'mysql' : 'json';
}

function castaneas_storage_read_raw($key) {
    $raw = castaneas_db_read_raw($key);
    if ($raw !== null) {
        return $raw;
    }

    return castaneas_json_read_raw($key);
}

function castaneas_storage_write_raw($key, $rawJson) {
    if (castaneas_db()) {
        return castaneas_db_write_raw($key, $rawJson);
    }

    return castaneas_json_write_raw($key, $rawJson);
}