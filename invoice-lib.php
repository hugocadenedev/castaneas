<?php

require_once __DIR__ . '/order-store.php';

function castaneas_billing_settings_defaults() {
    return [
        'format' => 'FAC-{YYYY}-{SEQ4}',
        'nextNumber' => 1,
    ];
}

function castaneas_billing_settings_normalize($settings) {
    $defaults = castaneas_billing_settings_defaults();
    $settings = is_array($settings) ? $settings : [];

    $format = trim((string) ($settings['format'] ?? $defaults['format']));
    if ($format === '') {
        $format = $defaults['format'];
    }

    $nextNumber = (int) ($settings['nextNumber'] ?? $defaults['nextNumber']);
    if ($nextNumber < 1) {
        $nextNumber = 1;
    }

    return [
        'format' => $format,
        'nextNumber' => $nextNumber,
    ];
}

function castaneas_billing_settings_load() {
    $raw = castaneas_storage_read_raw('billing_settings');
    if ($raw === null || trim($raw) === '') {
        return castaneas_billing_settings_defaults();
    }

    $settings = json_decode($raw, true);

    return castaneas_billing_settings_normalize($settings);
}

function castaneas_billing_settings_save(array $settings) {
    $normalized = castaneas_billing_settings_normalize($settings);

    return castaneas_storage_write_raw(
        'billing_settings',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function castaneas_invoice_format_has_sequence_token($format) {
    return preg_match('/\{SEQ(?::\d+|\d+)?\}/', $format) === 1;
}

function castaneas_invoice_render_number($format, $sequence, $issuedAt = null) {
    $issuedAt = $issuedAt ?: gmdate('c');

    try {
        $date = new DateTimeImmutable($issuedAt);
    } catch (Throwable $e) {
        $date = new DateTimeImmutable('now');
    }

    $number = strtr((string) $format, [
        '{YYYY}' => $date->format('Y'),
        '{YY}' => $date->format('y'),
        '{MM}' => $date->format('m'),
        '{DD}' => $date->format('d'),
    ]);

    return preg_replace_callback('/\{SEQ(?::(\d+)|(\d+))?\}/', function ($matches) use ($sequence) {
        $width = 0;
        if (!empty($matches[1])) {
            $width = (int) $matches[1];
        } elseif (!empty($matches[2])) {
            $width = (int) $matches[2];
        }

        return $width > 0
            ? str_pad((string) $sequence, $width, '0', STR_PAD_LEFT)
            : (string) $sequence;
    }, $number);
}

function castaneas_invoice_max_issued_sequence() {
    $maxSequence = 0;

    foreach (castaneas_orders_all() as $order) {
        $sequence = (int) ($order['invoiceSequence'] ?? 0);
        if ($sequence > $maxSequence) {
            $maxSequence = $sequence;
        }
    }

    return $maxSequence;
}

function castaneas_billing_settings_validate(array $settings) {
    $normalized = castaneas_billing_settings_normalize($settings);
    if ($normalized['format'] === '') {
        return 'Le format de facture est obligatoire.';
    }
    if (strlen($normalized['format']) > 120) {
        return 'Le format de facture est trop long.';
    }
    if (!castaneas_invoice_format_has_sequence_token($normalized['format'])) {
        return 'Le format doit contenir un jeton de sequence comme {SEQ4} ou {SEQ:4}.';
    }

    $maxIssuedSequence = castaneas_invoice_max_issued_sequence();
    if ($normalized['nextNumber'] <= $maxIssuedSequence) {
        return 'Le prochain numero doit etre superieur au dernier numero deja attribue.';
    }

    return null;
}

function castaneas_invoice_can_issue(array $order) {
    $status = trim((string) ($order['status'] ?? ''));

    return in_array($status, ['paid', 'processing', 'shipped', 'completed'], true);
}

function castaneas_invoice_find_duplicate_number($invoiceNumber, $currentOrderId) {
    foreach (castaneas_orders_all() as $order) {
        if (($order['id'] ?? '') === $currentOrderId) {
            continue;
        }
        if (($order['invoiceNumber'] ?? '') === $invoiceNumber) {
            return true;
        }
    }

    return false;
}

function castaneas_invoice_assign_order($ref, $issuedAt = null) {
    $order = castaneas_order_find($ref);
    if (!$order) {
        return ['ok' => false, 'error' => 'Commande introuvable.'];
    }
    if (!empty($order['invoiceNumber'])) {
        return ['ok' => true, 'order' => $order, 'settings' => castaneas_billing_settings_load(), 'created' => false];
    }
    if (!castaneas_invoice_can_issue($order)) {
        return ['ok' => false, 'error' => 'Une facture ne peut etre emise que pour une commande payee ou en preparation.'];
    }

    $settings = castaneas_billing_settings_load();
    $validationError = castaneas_billing_settings_validate($settings);
    if ($validationError !== null) {
        return ['ok' => false, 'error' => $validationError];
    }

    $sequence = max(1, (int) ($settings['nextNumber'] ?? 1));
    $issuedAt = $issuedAt ?: (string) ($order['paidAt'] ?? $order['updatedAt'] ?? $order['createdAt'] ?? gmdate('c'));
    $invoiceNumber = castaneas_invoice_render_number($settings['format'], $sequence, $issuedAt);

    while (castaneas_invoice_find_duplicate_number($invoiceNumber, (string) ($order['id'] ?? ''))) {
        $sequence++;
        $invoiceNumber = castaneas_invoice_render_number($settings['format'], $sequence, $issuedAt);
    }

    $settings['nextNumber'] = $sequence + 1;
    if (!castaneas_billing_settings_save($settings)) {
        return ['ok' => false, 'error' => 'Impossible d\'enregistrer les parametres de facturation.'];
    }

    $order['invoiceNumber'] = $invoiceNumber;
    $order['invoiceSequence'] = $sequence;
    $order['invoiceIssuedAt'] = $issuedAt;
    $order['invoiceFormat'] = $settings['format'];

    $savedOrder = castaneas_order_upsert($order);
    if (!$savedOrder) {
        return ['ok' => false, 'error' => 'Impossible d\'enregistrer le numero de facture sur la commande.'];
    }

    return ['ok' => true, 'order' => $savedOrder, 'settings' => $settings, 'created' => true];
}