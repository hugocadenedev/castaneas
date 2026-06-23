<?php
// ============================================================
//  CASTANEAS — upload.php
//  Upload d'images pour le back-office
//
//  POST /upload.php  (multipart/form-data, champ "file")
//  Header X-Admin-Token requis
//  Retourne : {"url":"uploads/xxxxxx.jpg"}
// ============================================================

require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');

// ── Vérification du token ─────────────────────────────────
$token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if ($token !== castaneas_admin_token()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Méthode et fichier ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Aucun fichier reçu']);
    exit;
}

$f = $_FILES['file'];

if ($f['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Erreur lors de l\'upload (code ' . $f['error'] . ')']);
    exit;
}

// ── Validation MIME (via finfo, pas l'extension) ──────────
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mime    = $finfo->file($f['tmp_name']);

if (!in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de fichier non autorisé (jpg/png/webp/gif uniquement)']);
    exit;
}

// ── Taille max 8 Mo ───────────────────────────────────────
if ($f['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Fichier trop grand (max 8 Mo)']);
    exit;
}

// ── Répertoire de destination ─────────────────────────────
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── Nom de fichier sécurisé (aléatoire) ───────────────────
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$ext    = $extMap[$mime];
$name   = bin2hex(random_bytes(10)) . '.' . $ext;
$dest   = $uploadDir . $name;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'Échec de l\'enregistrement sur le serveur']);
    exit;
}

echo json_encode(['url' => 'uploads/' . $name]);
