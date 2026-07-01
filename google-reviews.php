<?php

require_once __DIR__ . '/integrations.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function castaneas_google_reviews_respond($status, array $payload) {
    http_response_code((int) $status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function castaneas_google_reviews_apply_ssl_options($ch, array $config) {
    if (!empty($config['ca_bundle'])) {
        curl_setopt($ch, CURLOPT_CAINFO, $config['ca_bundle']);
    }
    if (!empty($config['skip_ssl_verify'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

function castaneas_google_reviews_request($method, $url, array $config, array $payload = null, $fieldMask = '') {
    $headers = [
        'Accept: application/json',
        'X-Goog-Api-Key: ' . $config['api_key'],
    ];
    if ($fieldMask !== '') {
        $headers[] = 'X-Goog-FieldMask: ' . $fieldMask;
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $options);
    castaneas_google_reviews_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'status' => 0,
            'message' => $error !== '' ? $error : 'Erreur reseau Google Places.',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded)
            ? ($decoded['error']['message'] ?? $decoded['message'] ?? 'Erreur API Google Places.')
            : 'Erreur API Google Places.';

        return [
            'ok' => false,
            'status' => $status,
            'message' => $message,
            'raw' => $decoded ?: $response,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

function castaneas_google_reviews_place_id(array $config) {
    $placeId = trim((string) ($config['place_id'] ?? ''));
    if ($placeId !== '') {
        return ['ok' => true, 'place_id' => $placeId];
    }

    $query = trim((string) ($config['query'] ?? ''));
    if ($query === '') {
        return ['ok' => false, 'message' => 'Aucune requete Google Places configuree.'];
    }

    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    $response = castaneas_google_reviews_request(
        'POST',
        $baseUrl . '/places:searchText',
        $config,
        [
            'textQuery' => $query,
            'languageCode' => trim((string) ($config['language'] ?? 'fr')) ?: 'fr',
            'regionCode' => strtoupper(trim((string) ($config['region'] ?? 'FR')) ?: 'FR'),
            'maxResultCount' => 1,
        ],
        'places.id,places.displayName,places.rating,places.userRatingCount,places.googleMapsUri'
    );

    if (empty($response['ok'])) {
        return [
            'ok' => false,
            'status' => (int) ($response['status'] ?? 502),
            'message' => (string) ($response['message'] ?? 'Recherche Google Places impossible.'),
        ];
    }

    $place = is_array($response['data']['places'][0] ?? null) ? $response['data']['places'][0] : null;
    $placeId = trim((string) ($place['id'] ?? ''));
    if ($placeId === '') {
        return ['ok' => false, 'status' => 404, 'message' => 'Aucun lieu Google Places correspondant a la requete.'];
    }

    return ['ok' => true, 'place_id' => $placeId];
}

function castaneas_google_reviews_text($value) {
    if (is_array($value)) {
        return trim((string) ($value['text'] ?? ''));
    }

    return trim((string) $value);
}

function castaneas_google_reviews_normalize(array $place, $placeId) {
    $reviews = [];
    $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

    foreach (($place['reviews'] ?? []) as $review) {
        if (!is_array($review)) {
            continue;
        }

        $rating = max(1, min(5, (int) round((float) ($review['rating'] ?? 0))));
        $distribution[$rating] += 1;

        $authorName = trim((string) ($review['authorAttribution']['displayName'] ?? 'Client Google'));
        $text = castaneas_google_reviews_text($review['originalText'] ?? null);
        if ($text === '') {
            $text = castaneas_google_reviews_text($review['text'] ?? null);
        }

        if ($text === '') {
            continue;
        }

        $reviews[] = [
            'authorName' => $authorName,
            'rating' => $rating,
            'text' => $text,
            'publishTime' => trim((string) ($review['publishTime'] ?? '')),
            'relativeTime' => trim((string) ($review['relativePublishTimeDescription'] ?? '')),
            'authorUrl' => trim((string) ($review['authorAttribution']['uri'] ?? '')),
        ];
    }

    return [
        'ok' => true,
        'source' => 'google_places',
        'place' => [
            'placeId' => $placeId,
            'name' => castaneas_google_reviews_text($place['displayName'] ?? ''),
            'rating' => (float) ($place['rating'] ?? 0),
            'userRatingCount' => (int) ($place['userRatingCount'] ?? 0),
            'googleMapsUri' => trim((string) ($place['googleMapsUri'] ?? '')),
            'writeReviewUri' => $placeId !== '' ? 'https://search.google.com/local/writereview?placeid=' . rawurlencode($placeId) : '',
        ],
        'reviews' => $reviews,
        'sampleHistogram' => $distribution,
        'sampleCount' => count($reviews),
        'fetchedAt' => gmdate('c'),
    ];
}

$config = castaneas_google_places_config();
if (!castaneas_google_places_is_ready()) {
    castaneas_google_reviews_respond(503, [
        'ok' => false,
        'code' => 'google_places_not_configured',
        'message' => 'Configurez google_places.api_key puis google_places.place_id ou google_places.query pour synchroniser les avis Google.',
    ]);
}

$placeLookup = castaneas_google_reviews_place_id($config);
if (empty($placeLookup['ok'])) {
    castaneas_google_reviews_respond((int) ($placeLookup['status'] ?? 502), [
        'ok' => false,
        'code' => 'google_places_lookup_failed',
        'message' => (string) ($placeLookup['message'] ?? 'Recherche Google Places impossible.'),
    ]);
}

$placeId = trim((string) ($placeLookup['place_id'] ?? ''));
$baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
$details = castaneas_google_reviews_request(
    'GET',
    $baseUrl . '/places/' . rawurlencode($placeId),
    $config,
    null,
    'id,displayName,rating,userRatingCount,googleMapsUri,reviews'
);

if (empty($details['ok'])) {
    castaneas_google_reviews_respond((int) ($details['status'] ?? 502), [
        'ok' => false,
        'code' => 'google_places_details_failed',
        'message' => (string) ($details['message'] ?? 'Chargement des avis Google impossible.'),
    ]);
}

castaneas_google_reviews_respond(200, castaneas_google_reviews_normalize($details['data'], $placeId));
