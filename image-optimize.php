<?php
// ============================================================
//  CASTANEAS — image-optimize.php
//  Redimensionne (largeur max) et recompresse une image en gardant son nom et
//  son format, pour ne casser aucune référence existante.
//  L'original est conservé dans uploads/_originals/ avant tout remplacement.
//  Sans l'extension GD, rien n'est modifié.
// ============================================================

const CASTANEAS_IMAGE_MAX_WIDTH = 1600;
const CASTANEAS_IMAGE_MIN_BYTES = 250 * 1024;

function castaneas_image_optimizer_available() {
    return function_exists('imagecreatetruecolor') && function_exists('getimagesize');
}

/**
 * @return array{status:string, before:int, after:int}
 *   status : optimized | skipped | unchanged | unsupported | error
 */
function castaneas_optimize_image_file($file, $backupDir = null) {
    $before = is_file($file) ? (int) filesize($file) : 0;
    $result = ['status' => 'skipped', 'before' => $before, 'after' => $before];
    if (!castaneas_image_optimizer_available() || $before === 0) {
        $result['status'] = castaneas_image_optimizer_available() ? 'error' : 'unsupported';
        return $result;
    }

    $info = @getimagesize($file);
    if (!$info) {
        $result['status'] = 'error';
        return $result;
    }
    [$width, $height, $type] = $info;
    if ($width <= CASTANEAS_IMAGE_MAX_WIDTH && $before < CASTANEAS_IMAGE_MIN_BYTES) {
        return $result;
    }

    $loaders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($loaders[$type]) || !function_exists($loaders[$type])) {
        $result['status'] = 'unsupported';
        return $result;
    }

    @ini_set('memory_limit', '512M');
    $image = @$loaders[$type]($file);
    if (!$image) {
        $result['status'] = 'error';
        return $result;
    }

    $keepAlpha = $type !== IMAGETYPE_JPEG;
    if ($width > CASTANEAS_IMAGE_MAX_WIDTH) {
        $newWidth = CASTANEAS_IMAGE_MAX_WIDTH;
        $newHeight = max(1, (int) round($height * $newWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($keepAlpha) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    } elseif ($keepAlpha) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $written = false;
    if ($type === IMAGETYPE_JPEG) {
        imageinterlace($image, true);
        $written = imagejpeg($image, $tmp, 82);
    } elseif ($type === IMAGETYPE_PNG) {
        $written = imagepng($image, $tmp, 9);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
        $written = imagewebp($image, $tmp, 82);
    }
    imagedestroy($image);

    $after = $written && is_file($tmp) ? (int) filesize($tmp) : 0;
    if (!$written || $after === 0 || $after >= $before * 0.95) {
        @unlink($tmp);
        $result['status'] = $written ? 'unchanged' : 'error';
        return $result;
    }

    if ($backupDir !== null) {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            @unlink($tmp);
            $result['status'] = 'error';
            return $result;
        }
        $backup = rtrim($backupDir, '/\\') . '/' . basename($file);
        if (!is_file($backup) && !@copy($file, $backup)) {
            @unlink($tmp);
            $result['status'] = 'error';
            return $result;
        }
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        $result['status'] = 'error';
        return $result;
    }
    clearstatcache(true, $file);

    return ['status' => 'optimized', 'before' => $before, 'after' => (int) filesize($file)];
}

/** Vrai si l'image n'a aucun pixel transparent (échantillonnage). */
function castaneas_image_is_opaque($image) {
    if (imageistruecolor($image) === false && imagecolortransparent($image) >= 0) {
        return false;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $stepX = max(1, intdiv($width, 80));
    $stepY = max(1, intdiv($height, 80));
    for ($x = 0; $x < $width; $x += $stepX) {
        for ($y = 0; $y < $height; $y += $stepY) {
            if ((imagecolorat($image, $x, $y) >> 24) & 0x7F) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Photo enregistrée en PNG (sans transparence) : crée à côté une version JPEG
 * « <nom>.opt.jpg ». Le PNG d'origine n'est pas modifié ; le site sert la version
 * JPEG grâce à castaneas_converted_images() / castaneas_apply_converted_images().
 *
 * @return array{status:string, before:int, after:int, target?:string}
 */
function castaneas_convert_png_photo($file) {
    $before = is_file($file) ? (int) filesize($file) : 0;
    $result = ['status' => 'skipped', 'before' => $before, 'after' => $before];
    if (!castaneas_image_optimizer_available() || $before < CASTANEAS_IMAGE_MIN_BYTES) {
        return $result;
    }
    $info = @getimagesize($file);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        return $result;
    }

    @ini_set('memory_limit', '512M');
    $image = @imagecreatefrompng($file);
    if (!$image || !castaneas_image_is_opaque($image)) {
        $result['status'] = $image ? 'transparent' : 'error';
        return $result;
    }

    [$width, $height] = $info;
    if ($width > CASTANEAS_IMAGE_MAX_WIDTH) {
        $newWidth = CASTANEAS_IMAGE_MAX_WIDTH;
        $newHeight = max(1, (int) round($height * $newWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    $target = preg_replace('/\.png$/i', '', $file) . '.opt.jpg';
    $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
    imageinterlace($image, true);
    $written = imagejpeg($image, $tmp, 82);
    imagedestroy($image);
    $after = $written && is_file($tmp) ? (int) filesize($tmp) : 0;
    if (!$written || $after === 0 || $after >= $before * 0.8 || !@rename($tmp, $target)) {
        @unlink($tmp);
        $result['status'] = 'unchanged';
        return $result;
    }

    return ['status' => 'converted', 'before' => $before, 'after' => $after, 'target' => basename($target)];
}

/** Correspondances « uploads/x.png » → « uploads/x.opt.jpg » des PNG convertis. */
function castaneas_converted_images() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $file = __DIR__ . '/uploads/_originals/.converted.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    foreach (is_array($data) ? $data : [] as $source => $target) {
        if (is_string($target) && is_file(__DIR__ . '/uploads/' . basename($target))) {
            $map['uploads/' . basename($source)] = 'uploads/' . basename($target);
        }
    }

    return $map;
}

function castaneas_record_converted_image($source, $target) {
    $dir = __DIR__ . '/uploads/_originals';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $file = $dir . '/.converted.json';
    $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $data[basename($source)] = basename($target);

    return @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false;
}

/** Remplace récursivement les chemins des PNG convertis par leur version JPEG. */
function castaneas_apply_converted_images($value) {
    $map = castaneas_converted_images();
    if (!$map) {
        return $value;
    }
    if (is_string($value)) {
        $path = ltrim($value, '/');
        if (isset($map[$path])) {
            return (strpos($value, '/') === 0 ? '/' : '') . $map[$path];
        }
        return $value;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = castaneas_apply_converted_images($item);
        }
    } elseif (is_object($value)) {
        foreach (get_object_vars($value) as $key => $item) {
            $value->$key = castaneas_apply_converted_images($item);
        }
    }

    return $value;
}
