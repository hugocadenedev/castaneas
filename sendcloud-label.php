<?php

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/order-store.php';
require_once __DIR__ . '/sendcloud.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function castaneas_sendcloud_label_response($status, array $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function castaneas_sendcloud_label_sync_order(array $order, array $parcel) {
    $sendcloud = is_array($order['sendcloud'] ?? null) ? $order['sendcloud'] : [];
    $sendcloud['lastAttemptAt'] = gmdate('c');
    $sendcloud['createdAt'] = $sendcloud['createdAt'] ?? gmdate('c');
    $sendcloud['parcelId'] = $parcel['id'] ?? ($sendcloud['parcelId'] ?? null);
    $sendcloud['trackingNumber'] = $parcel['tracking_number'] ?? ($sendcloud['trackingNumber'] ?? null);
    $sendcloud['labelUrl'] = castaneas_sendcloud_label_url_from_parcel($parcel) ?: ($sendcloud['labelUrl'] ?? null);
    $sendcloud['shipmentId'] = $parcel['shipment']['id'] ?? ($sendcloud['shipmentId'] ?? null);
    $sendcloud['shipmentName'] = $parcel['shipment']['name'] ?? ($sendcloud['shipmentName'] ?? null);
    $sendcloud['lastResult'] = ['ok' => true, 'status' => 'label_ready'];

    $updated = castaneas_order_update_status((string) $order['id'], (string) ($order['status'] ?? 'paid'), ['sendcloud' => $sendcloud]);
    if (!$updated) {
        return null;
    }

    return $updated;
}

function castaneas_sendcloud_label_sync_failure(array $order, array $result) {
    $sendcloud = is_array($order['sendcloud'] ?? null) ? $order['sendcloud'] : [];
    $sendcloud['lastAttemptAt'] = gmdate('c');
    $sendcloud['lastResult'] = $result;

    return castaneas_order_update_status((string) $order['id'], (string) ($order['status'] ?? 'paid'), ['sendcloud' => $sendcloud]);
}

function castaneas_sendcloud_label_ensure_for_order(array $order) {
    $sendcloud = is_array($order['sendcloud'] ?? null) ? $order['sendcloud'] : [];
    $parcelId = (int) ($sendcloud['parcelId'] ?? 0);

    if ($parcelId > 0) {
        $parcelResult = castaneas_sendcloud_refresh_parcel($parcelId);
        if ($parcelResult['ok']) {
            $parcel = $parcelResult['data']['parcel'];
            if (castaneas_sendcloud_label_url_from_parcel($parcel)) {
                return ['ok' => true, 'order' => castaneas_sendcloud_label_sync_order($order, $parcel), 'parcel' => $parcel];
            }
        }

        $announceResult = castaneas_sendcloud_request_existing_label($parcelId);
        if (!$announceResult['ok']) {
            return $announceResult;
        }

        $parcel = $announceResult['data']['parcel'];
        $updatedOrder = castaneas_sendcloud_label_sync_order($order, $parcel);
        if (!$updatedOrder) {
            return ['ok' => false, 'code' => 'sendcloud_order_sync_failed', 'message' => 'Impossible de mettre à jour la commande après génération de l\'étiquette.'];
        }

        return ['ok' => true, 'order' => $updatedOrder, 'parcel' => $parcel];
    }

    $sendResult = castaneas_sendcloud_send_order($order);
    if (!$sendResult['ok']) {
        return $sendResult;
    }

    $parcel = castaneas_sendcloud_extract_parcel($sendResult['data']);
    if (!$parcel) {
        return ['ok' => false, 'code' => 'sendcloud_missing_parcel', 'message' => 'Colis Sendcloud introuvable dans la réponse.'];
    }

    $updatedOrder = castaneas_sendcloud_label_sync_order($order, $parcel);
    if (!$updatedOrder) {
        return ['ok' => false, 'code' => 'sendcloud_order_sync_failed', 'message' => 'Impossible de mettre à jour la commande après création du colis.'];
    }

    return ['ok' => true, 'order' => $updatedOrder, 'parcel' => $parcel];
}

$token = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
if ($token !== castaneas_admin_token()) {
    castaneas_sendcloud_label_response(401, ['ok' => false, 'error' => 'Unauthorized']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    castaneas_sendcloud_label_response(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if (!castaneas_sendcloud_is_ready()) {
    castaneas_sendcloud_label_response(503, ['ok' => false, 'error' => 'Sendcloud non configuré.']);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
if (!is_array($body)) {
    castaneas_sendcloud_label_response(400, ['ok' => false, 'error' => 'JSON invalide.']);
}

$ref = trim((string) ($body['ref'] ?? ''));
if ($ref === '') {
    castaneas_sendcloud_label_response(400, ['ok' => false, 'error' => 'Référence commande manquante.']);
}

$order = castaneas_order_find($ref);
if (!$order) {
    castaneas_sendcloud_label_response(404, ['ok' => false, 'error' => 'Commande introuvable.']);
}

if (!in_array((string) ($order['status'] ?? ''), ['paid', 'processing', 'shipped', 'delivered'], true)) {
    castaneas_sendcloud_label_response(409, ['ok' => false, 'error' => 'L\'étiquette Sendcloud ne peut être générée qu\'après paiement.']);
}

$labelResult = castaneas_sendcloud_label_ensure_for_order($order);
if (!$labelResult['ok']) {
    castaneas_sendcloud_label_sync_failure($order, $labelResult);
    castaneas_sendcloud_label_response(502, ['ok' => false, 'error' => $labelResult['message'] ?? 'Impossible de générer l\'étiquette Sendcloud.', 'code' => $labelResult['code'] ?? 'sendcloud_label_failed']);
}

$updatedOrder = $labelResult['order'] ?? castaneas_order_find($ref);
$parcelId = (int) (($updatedOrder['sendcloud']['parcelId'] ?? 0));
$pdfResult = castaneas_sendcloud_download_label_pdf($parcelId);
if (!$pdfResult['ok']) {
    castaneas_sendcloud_label_response(502, ['ok' => false, 'error' => $pdfResult['message'] ?? 'Impossible de télécharger l\'étiquette Sendcloud.', 'code' => $pdfResult['code'] ?? 'sendcloud_label_download_failed']);
}

http_response_code(200);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="sendcloud-label-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $ref) . '.pdf"');
echo $pdfResult['raw'];
exit;