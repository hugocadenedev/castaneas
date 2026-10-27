<?php
// ============================================================
//  CASTANEAS — inline-images.php
//  Les bannières de catégories et images de l'accueil sont enregistrées par le
//  back-office en images intégrées (data:image/...;base64), ce qui alourdissait
//  data.php de plusieurs Mo à chaque page. On les écrit une fois en fichiers
//  (uploads/inline/<empreinte>.<ext>) et on sert l'adresse du fichier à la place.
//  Si l'écriture échoue, l'image intégrée est conservée telle quelle.
// ============================================================

function castaneas_inline_image_file($dataUri) {
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,(.+)$#s', $dataUri, $match)) {
        return null;
    }

    $ext = $match[1] === 'jpeg' ? 'jpg' : $match[1];
    $hash = sha1($match[2]);
    $relative = 'uploads/inline/' . $hash . '.' . $ext;
    $file = __DIR__ . '/' . $relative;

    if (!is_file($file)) {
        $binary = base64_decode($match[2], true);
        if ($binary === false || $binary === '') {
            return null;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $binary) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return null;
        }
    }

    return '/' . $relative;
}

/** Remplace récursivement les images intégrées par l'adresse de leur fichier. */
function castaneas_externalize_inline_images($value) {
    if (is_string($value)) {
        if (strncmp($value, 'data:image/', 11) === 0 && strlen($value) > 1024) {
            return castaneas_inline_image_file($value) ?? $value;
        }
        return $value;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = castaneas_externalize_inline_images($item);
        }
        return $value;
    }
    if (is_object($value)) {
        foreach (get_object_vars($value) as $key => $item) {
            $value->$key = castaneas_externalize_inline_images($item);
        }
    }

    return $value;
}
