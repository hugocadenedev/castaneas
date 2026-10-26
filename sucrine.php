<?php

require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/storage.php';

function castaneas_products_index() {
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    $raw = castaneas_storage_read_raw('products');
    if ($raw === null) {
        return $index;
    }

    $products = json_decode($raw, true);
    if (!is_array($products)) {
        return $index;
    }

    foreach ($products as $product) {
        if (!is_array($product) || empty($product['id'])) {
            continue;
        }

        $index[$product['id']] = $product;
    }

    return $index;
}

function castaneas_sucrine_is_sku_reference($value) {
    return preg_match('/^AR\d+$/i', trim((string) $value)) === 1;
}

function castaneas_sucrine_catalogue_lookup_index(array $config) {
    static $cache = [];

    $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    $catalogueId = trim((string) ($config['catalogue_id'] ?? ''));
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    $cacheKey = sha1($baseUrl . '|' . $catalogueId . '|' . $apiKey);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($catalogueId === '') {
        return $cache[$cacheKey] = [
            'ok' => false,
            'message' => 'Catalogue Sucrine manquant. Configurez sucrine.catalogue_id pour resoudre automatiquement les SKU AR....',
        ];
    }

    $url = $baseUrl . '/professional/catalogues/' . rawurlencode($catalogueId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ApiKey ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    castaneas_sucrine_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return $cache[$cacheKey] = [
            'ok' => false,
            'message' => $error !== '' ? $error : 'Erreur reseau lors du chargement du catalogue Sucrine.',
        ];
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = castaneas_sucrine_error_message($decoded);
        if ($message === null && is_string($response) && trim($response) !== '') {
            $message = trim($response);
        }

        return $cache[$cacheKey] = [
            'ok' => false,
            'message' => $message ?: 'Impossible de charger le catalogue Sucrine.',
            'status' => $status,
        ];
    }

    $catalogueItems = is_array($decoded['standardCatalogue'] ?? null) ? $decoded['standardCatalogue'] : null;
    if ($catalogueItems === null) {
        return $cache[$cacheKey] = [
            'ok' => false,
            'message' => 'Le catalogue Sucrine ne contient pas de standardCatalogue exploitable.',
        ];
    }

    $index = [];
    $vatRates = [];
    foreach ($catalogueItems as $catalogueItem) {
        if (!is_array($catalogueItem)) {
            continue;
        }

        foreach (($catalogueItem['rawPrices'] ?? []) as $rawPrice) {
            if (!is_array($rawPrice) || empty($rawPrice['_id'])) {
                continue;
            }

            $resolvedId = trim((string) $rawPrice['_id']);
            if (isset($catalogueItem['vatRate']) && is_numeric($catalogueItem['vatRate'])) {
                $vatRates[$resolvedId] = (float) $catalogueItem['vatRate'];
            }
            $candidateSkus = [
                $rawPrice['sku'] ?? null,
                $rawPrice['price']['sku'] ?? null,
                $rawPrice['metadata']['woocommerceIdentifier'] ?? null,
            ];

            foreach ($candidateSkus as $candidateSku) {
                $candidateSku = strtoupper(trim((string) $candidateSku));
                if ($candidateSku === '') {
                    continue;
                }

                $index[$candidateSku] = [
                    'id' => $resolvedId,
                    'name' => trim((string) ($catalogueItem['name'] ?? '')),
                ];
            }
        }
    }

    return $cache[$cacheKey] = [
        'ok' => true,
        'index' => $index,
        'vatRates' => $vatRates,
    ];
}

function castaneas_sucrine_resolve_reference($reference, array $config) {
    $reference = trim((string) $reference);
    if ($reference === '') {
        return [
            'ok' => false,
            'message' => 'Reference Sucrine vide.',
        ];
    }

    if (!castaneas_sucrine_is_sku_reference($reference)) {
        return [
            'ok' => true,
            'id' => $reference,
        ];
    }

    $lookup = castaneas_sucrine_catalogue_lookup_index($config);
    if (empty($lookup['ok'])) {
        return [
            'ok' => false,
            'message' => (string) ($lookup['message'] ?? 'Lookup SKU Sucrine impossible.'),
        ];
    }

    $sku = strtoupper($reference);
    $resolved = $lookup['index'][$sku] ?? null;
    if (!is_array($resolved) || empty($resolved['id'])) {
        return [
            'ok' => false,
            'message' => 'SKU Sucrine introuvable dans le catalogue: ' . $sku,
        ];
    }

    return [
        'ok' => true,
        'id' => trim((string) $resolved['id']),
        'sku' => $sku,
        'name' => trim((string) ($resolved['name'] ?? '')),
    ];
}

function castaneas_sucrine_add_item(array &$items, $reference, $quantity, $unitPrice) {
    $reference = trim((string) $reference);
    $quantity = max(0, (int) $quantity);
    if ($reference === '' || $quantity <= 0) {
        return;
    }

    if (!isset($items[$reference])) {
        $items[$reference] = [
            'quantity' => 0,
            'lineTotal' => 0.0,
        ];
    }

    // Un même article peut venir de plusieurs lignes à des prix différents (unité + lot) :
    // Sucrine n'accepte qu'un prix par article, on envoie donc le prix unitaire moyen.
    $items[$reference]['quantity'] += $quantity;
    $items[$reference]['lineTotal'] += max(0, (float) $unitPrice) * $quantity;
    $items[$reference]['ePrice'] = [
        'amount' => round($items[$reference]['lineTotal'] / $items[$reference]['quantity'], 4),
        'amountType' => 'unitPrice',
    ];
}

function castaneas_sucrine_build_items(array $order, array &$issues = []) {
    $items = [];
    $products = castaneas_products_index();
    $config = castaneas_sucrine_config();

    foreach (($order['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $product = $products[$item['id'] ?? ''] ?? null;
        $sucrineReference = $item['sucrineId'] ?? ($product['sucrineId'] ?? null);
        $qty = max(0, (int) ($item['qty'] ?? 0));
        $offerQty = max(1, (int) ($item['offerQty'] ?? 1));
        $lineQty = $qty * $offerQty;
        $unitPrice = $offerQty > 0 ? ((float) ($item['price'] ?? 0) / $offerQty) : (float) ($item['price'] ?? 0);

        if ($sucrineReference && $lineQty > 0) {
            $resolved = castaneas_sucrine_resolve_reference($sucrineReference, $config);
            if (!empty($resolved['ok']) && !empty($resolved['id'])) {
                castaneas_sucrine_add_item($items, $resolved['id'], $lineQty, $unitPrice);
            } else {
                $label = trim((string) ($product['name'] ?? ($item['name'] ?? ($item['id'] ?? 'Produit'))));
                $issues[] = $label . ': ' . (string) ($resolved['message'] ?? 'Reference Sucrine invalide.');
            }
        }

        // Un produit qui a sa propre référence Sucrine (ex. Duo/Trio Gourmand) est un article composé
        // côté Sucrine : ses composants ne doivent pas être envoyés en plus (sinon doublon de produits et de prix).
        // Les composants ne sont envoyés que pour un coffret sans référence Sucrine propre.
        $boxItems = is_array($product['boxItems'] ?? null) ? $product['boxItems'] : [];
        if (!$boxItems || $qty <= 0 || $sucrineReference) {
            continue;
        }

        foreach ($boxItems as $boxItem) {
            if (!is_array($boxItem) || empty($boxItem['productId'])) {
                continue;
            }

            $child = $products[$boxItem['productId']] ?? null;
            $childReference = $child['sucrineId'] ?? null;
            if (!$childReference) {
                continue;
            }

            $childQty = max(1, (int) ($boxItem['qty'] ?? 1)) * $qty;
            $resolvedChild = castaneas_sucrine_resolve_reference($childReference, $config);
            if (!empty($resolvedChild['ok']) && !empty($resolvedChild['id'])) {
                castaneas_sucrine_add_item($items, $resolvedChild['id'], $childQty, (float) ($boxItem['unitPrice'] ?? 0));
            } else {
                $label = trim((string) ($child['name'] ?? ($boxItem['productId'] ?? 'Produit coffret')));
                $issues[] = $label . ': ' . (string) ($resolvedChild['message'] ?? 'Reference Sucrine invalide.');
            }
        }
    }

    foreach ($items as &$builtItem) {
        unset($builtItem['lineTotal']);
    }
    unset($builtItem);

    return $items;
}

/**
 * Remise (code promo) au format Sucrine : une ligne par taux de TVA,
 * répartie au prorata du montant TTC des articles envoyés.
 */
function castaneas_sucrine_build_discounts(array $order, array $items, array $config) {
    $discount = round((float) ($order['discount'] ?? ($order['promo']['discount'] ?? 0)), 2);
    if ($discount <= 0 || !$items) {
        return [];
    }

    $lookup = castaneas_sucrine_catalogue_lookup_index($config);
    $vatRates = is_array($lookup['vatRates'] ?? null) ? $lookup['vatRates'] : [];
    $defaultRate = (float) ($config['default_vat_rate'] ?? 5.5);

    $bases = [];
    foreach ($items as $itemId => $item) {
        $rate = (string) ($vatRates[$itemId] ?? $defaultRate);
        $lineTotal = (float) ($item['ePrice']['amount'] ?? 0) * (int) ($item['quantity'] ?? 0);
        $bases[$rate] = ($bases[$rate] ?? 0) + $lineTotal;
    }
    $total = array_sum($bases);
    if ($total <= 0) {
        return [];
    }
    $discount = min($discount, round($total, 2));

    $code = trim((string) ($order['promo']['code'] ?? ''));
    $comment = $code !== '' ? 'Code promo ' . $code : 'Remise site';

    $discounts = [];
    $remaining = $discount;
    $rates = array_keys($bases);
    foreach ($rates as $i => $rate) {
        $share = $i === count($rates) - 1 ? $remaining : round($discount * $bases[$rate] / $total, 2);
        $remaining = round($remaining - $share, 2);
        if ($share <= 0) {
            continue;
        }

        $split = castaneas_sucrine_ttc_split($share, (float) $rate);
        $discounts[] = [
            'rawValue' => $split['amount'],
            'type' => 'amount',
            'vatRate' => $split['vatRate'],
            'comment' => $comment,
            'dfAmount' => $split['dfAmount'],
            'vatAmount' => $split['vatAmount'],
            'amount' => $split['amount'],
        ];
    }

    return $discounts;
}

function castaneas_sucrine_contact_name(array $billing) {
    return trim(implode(' ', array_filter([
        trim((string) ($billing['prenom'] ?? '')),
        trim((string) ($billing['nom'] ?? '')),
    ])));
}

function castaneas_sucrine_address_payload(array $billing) {
    return [
        'address' => trim((string) ($billing['adresse'] ?? '')),
        'addressExtra' => trim((string) ($billing['complement'] ?? '')),
        'city' => trim((string) ($billing['ville'] ?? '')),
        'company' => trim((string) ($billing['societe'] ?? '')),
        'name' => castaneas_sucrine_contact_name($billing),
        'country' => strtoupper(trim((string) ($billing['pays'] ?? 'FR'))),
        'zipcode' => trim((string) ($billing['cp'] ?? '')),
    ];
}

function castaneas_sucrine_delivery_address(array $order, array $billing) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $servicePointAddress = is_array($servicePoint['address'] ?? null) ? $servicePoint['address'] : [];
    $type = trim((string) ($shipping['type'] ?? ''));

    if ($type !== 'relay' || !$servicePoint) {
        return castaneas_sucrine_address_payload($billing);
    }

    $street = trim(implode(' ', array_filter([
        (string) ($servicePointAddress['street'] ?? ''),
        (string) ($servicePointAddress['houseNumber'] ?? ''),
    ])));

    return [
        'address' => $street,
        'addressExtra' => '',
        'city' => trim((string) ($servicePointAddress['city'] ?? '')),
        'company' => trim((string) ($servicePoint['carrier']['name'] ?? '')),
        'name' => trim((string) ($servicePoint['name'] ?? castaneas_sucrine_contact_name($billing))),
        'country' => strtoupper(trim((string) ($servicePointAddress['countryCode'] ?? ($billing['pays'] ?? 'FR')))),
        'zipcode' => trim((string) ($servicePointAddress['postalCode'] ?? '')),
    ];
}

function castaneas_sucrine_delivery_description(array $order) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];

    $parts = array_filter([
        trim((string) ($shipping['name'] ?? '')),
        trim((string) ($shipping['carrier']['name'] ?? '')),
    ]);

    $description = $parts ? implode(' - ', array_values(array_unique($parts))) : trim((string) ($shipping['type'] ?? 'Livraison'));
    if (!empty($servicePoint['name'])) {
        $description .= ' - ' . trim((string) $servicePoint['name']);
    }

    return $description;
}

function castaneas_sucrine_message(array $order, array $billing) {
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $servicePoint = is_array($shipping['servicePoint'] ?? null) ? $shipping['servicePoint'] : [];
    $notes = [];

    $rawNote = trim((string) ($billing['note'] ?? ''));
    if ($rawNote !== '') {
        $notes[] = $rawNote;
    }

    if ($servicePoint) {
        $servicePointAddress = is_array($servicePoint['address'] ?? null) ? $servicePoint['address'] : [];
        $location = trim(implode(' ', array_filter([
            (string) ($servicePointAddress['postalCode'] ?? ''),
            (string) ($servicePointAddress['city'] ?? ''),
        ])));
        $relayNote = 'Point relais: ' . trim((string) ($servicePoint['name'] ?? ''));
        if ($location !== '') {
            $relayNote .= ' (' . $location . ')';
        }
        if (!empty($servicePoint['carrierServicePointId'])) {
            $relayNote .= ' [code transporteur: ' . trim((string) $servicePoint['carrierServicePointId']) . ']';
        }
        $notes[] = $relayNote;
    }

    $promoCode = trim((string) ($order['promo']['code'] ?? ''));
    if ($promoCode !== '') {
        $notes[] = 'Code promo: ' . $promoCode;
    }

    return implode(' | ', $notes);
}

function castaneas_sucrine_delivery_point(array $order, array $config) {
    return trim((string) ($config['delivery_point'] ?? ''));
}

function castaneas_sucrine_timeslot_start() {
    return gmdate('Y-m-d\\TH:i:s\\Z');
}

function castaneas_sucrine_timeslot_end($start) {
    $timestamp = strtotime((string) $start);
    if ($timestamp === false) {
        return gmdate('Y-m-d\\TH:i:s\\Z', time() + 3600);
    }

    return gmdate('Y-m-d\\TH:i:s\\Z', $timestamp + 3600);
}

function castaneas_sucrine_delivery_vat_rate(array $config) {
    $rate = isset($config['delivery_vat_rate']) ? (float) $config['delivery_vat_rate'] : 20.0;

    return $rate >= 0 ? round($rate, 2) : 0.0;
}

function castaneas_sucrine_ttc_split($ttcAmount, $vatRate) {
    $ttcAmount = round(max(0, (float) $ttcAmount), 2);
    $vatRate = max(0, (float) $vatRate);
    if ($ttcAmount <= 0 || $vatRate <= 0) {
        return [
            'amount' => $ttcAmount,
            'dfAmount' => $ttcAmount,
            'vatRate' => 0,
            'vatAmount' => 0,
        ];
    }

    $htAmount = round($ttcAmount / (1 + ($vatRate / 100)), 2);
    $vatAmount = round($ttcAmount - $htAmount, 2);

    return [
        'amount' => $ttcAmount,
        'dfAmount' => $htAmount,
        'vatRate' => $vatRate,
        'vatAmount' => $vatAmount,
    ];
}

function castaneas_sucrine_payload(array $order, $deliveryPoint = null, array $items = null) {
    $billing = $order['billing'] ?? [];
    $config = castaneas_sucrine_config();
    $deliveryAddress = castaneas_sucrine_delivery_address($order, $billing);
    $invoiceAddress = castaneas_sucrine_address_payload($billing);
    $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
    $shippingLabel = castaneas_sucrine_delivery_description($order);
    $shippingAmount = round((float) ($shipping['price'] ?? 0), 2);
    $shippingVat = castaneas_sucrine_ttc_split($shippingAmount, castaneas_sucrine_delivery_vat_rate($config));
    $resolvedDeliveryPoint = $deliveryPoint !== null ? trim((string) $deliveryPoint) : castaneas_sucrine_delivery_point($order, $config);
    $timeSlot = castaneas_sucrine_timeslot_start();
    $items = $items ?? castaneas_sucrine_build_items($order);

    return [
        'orderType' => 'order',
        'customerOrderReference' => (string) ($order['id'] ?? ''),
        'orderSource' => trim((string) ($config['order_source'] ?? 'castaneas')),
        'newContact' => [
            'name' => castaneas_sucrine_contact_name($billing),
            'email' => trim((string) ($billing['email'] ?? '')),
            'phone' => trim((string) ($billing['tel'] ?? '')),
        ],
        'advancedCatalogueItems' => $items,
        'skipPreciseSupplyCheck' => !array_key_exists('skip_precise_supply_check', $config) || !empty($config['skip_precise_supply_check']),
        'deliveryPoint' => $resolvedDeliveryPoint,
        'delivery' => [
            'description' => $shippingLabel,
            'amount' => $shippingVat['amount'],
            'dfAmount' => $shippingVat['dfAmount'],
            'vatRate' => $shippingVat['vatRate'],
            'vatAmount' => $shippingVat['vatAmount'],
        ],
        'discounts' => castaneas_sucrine_build_discounts($order, $items, $config),
        'defaultOrderState' => trim((string) ($config['order_state'] ?? '')) ?: 'terminated',
        'deliveryAddress' => $deliveryAddress,
        'invoicingAddress' => $invoiceAddress,
        'message' => castaneas_sucrine_message($order, $billing),
        'customTimeSlot' => true,
        'timeSlot' => $timeSlot,
        'timeSlotEnd' => castaneas_sucrine_timeslot_end($timeSlot),
    ];
}

function castaneas_sucrine_error_message($value) {
    if (is_string($value)) {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    if (!is_array($value)) {
        return null;
    }

    $candidates = [
        $value['message'] ?? null,
        $value['error'] ?? null,
        $value['detail'] ?? null,
        $value['title'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $resolved = castaneas_sucrine_error_message($candidate);
        if ($resolved !== null) {
            return $resolved;
        }
    }

    if (!empty($value['errors']) && is_array($value['errors'])) {
        $messages = [];
        foreach ($value['errors'] as $error) {
            $resolved = castaneas_sucrine_error_message($error);
            if ($resolved !== null) {
                $messages[] = $resolved;
            }
        }
        if ($messages) {
            return implode(' | ', array_values(array_unique($messages)));
        }
    }

    if (!empty($value['violations']) && is_array($value['violations'])) {
        $messages = [];
        foreach ($value['violations'] as $violation) {
            $resolved = castaneas_sucrine_error_message($violation);
            if ($resolved !== null) {
                $messages[] = $resolved;
            }
        }
        if ($messages) {
            return implode(' | ', array_values(array_unique($messages)));
        }
    }

    $flat = [];
    array_walk_recursive($value, static function ($item) use (&$flat) {
        if (is_scalar($item) && trim((string) $item) !== '') {
            $flat[] = trim((string) $item);
        }
    });

    return $flat ? implode(' | ', array_values(array_unique($flat))) : null;
}

function castaneas_sucrine_apply_ssl_options($ch, array $config) {
    if (!empty($config['ca_bundle'])) {
        curl_setopt($ch, CURLOPT_CAINFO, $config['ca_bundle']);
    }
    if (!empty($config['skip_ssl_verify'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

function castaneas_sucrine_post_order(array $config, array $payload, $deliveryPoint) {
    $url = rtrim($config['base_url'], '/') . '/professional/customerOrders/order';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: ApiKey ' . $config['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    castaneas_sucrine_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 'sucrine_transport_error',
            'message' => $error ?: 'Erreur réseau Sucrine.',
            'deliveryPoint' => $deliveryPoint,
            'payload' => $payload,
        ];
    }

    $decoded = json_decode($response, true);
    if ($status >= 200 && $status < 300) {
        return [
            'ok' => true,
            'status' => $status,
            'deliveryPoint' => $deliveryPoint,
            'data' => is_array($decoded) ? $decoded : ['raw' => $response],
        ];
    }

    $message = castaneas_sucrine_error_message($decoded);
    if ($message === null && is_string($response) && trim($response) !== '') {
        $message = trim($response);
    }

    return [
        'ok' => false,
        'code' => 'sucrine_http_error',
        'status' => $status,
        'message' => $message ?: 'Erreur API Sucrine.',
        'deliveryPoint' => $deliveryPoint,
        'payload' => $payload,
        'raw' => $decoded ?: $response,
    ];
}

function castaneas_sucrine_existing_contact_id(array $result) {
    if (($result['code'] ?? '') !== 'sucrine_http_error') {
        return null;
    }

    $raw = $result['raw'] ?? null;
    if (!is_array($raw)) {
        return null;
    }

    $error = is_array($raw['error'] ?? null) ? $raw['error'] : [];
    $name = trim((string) ($error['name'] ?? ''));
    $contact = is_array($error['contact'] ?? null) ? $error['contact'] : [];
    $contactId = trim((string) ($contact['_id'] ?? ''));
    if ($name !== 'ContactExistingError' || $contactId === '') {
        return null;
    }

    return $contactId;
}

function castaneas_sucrine_request(array $config, $method, $path, array $body = null) {
    $ch = curl_init(rtrim($config['base_url'], '/') . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: ApiKey ' . $config['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);
    castaneas_sucrine_apply_ssl_options($ch, $config);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'status' => 0, 'message' => $error ?: 'Erreur réseau Sucrine.'];
    }

    $decoded = json_decode($response, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => $decoded];
    }

    return [
        'ok' => false,
        'status' => $status,
        'message' => castaneas_sucrine_error_message($decoded) ?: (trim((string) $response) ?: 'Erreur API Sucrine.'),
    ];
}

function castaneas_sucrine_contact_group_id(array $config) {
    $groupId = trim((string) ($config['contact_group_id'] ?? ''));
    if ($groupId !== '') {
        return $groupId;
    }

    $groupName = trim((string) ($config['contact_group_name'] ?? ''));
    if ($groupName === '') {
        return null;
    }

    $result = castaneas_sucrine_request($config, 'GET', '/professional/contacts/groups');
    $groups = is_array($result['data'] ?? null) ? $result['data'] : [];
    if (isset($groups['items']) && is_array($groups['items'])) {
        $groups = $groups['items'];
    }
    foreach ($groups as $group) {
        if (is_array($group) && strcasecmp(trim((string) ($group['name'] ?? '')), $groupName) === 0) {
            return trim((string) ($group['_id'] ?? '')) ?: null;
        }
    }

    return null;
}

/** Identifiant du contact dans la réponse de création de commande (format non documenté). */
function castaneas_sucrine_order_contact_id($data) {
    if (!is_array($data)) {
        return null;
    }
    foreach (['orderedBy', 'contact', 'customer'] as $key) {
        $value = $data[$key] ?? null;
        if (is_array($value)) {
            $value = $value['_id'] ?? null;
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

/** Les contacts sont listés du plus récent au plus ancien : quelques pages suffisent juste après la commande. */
function castaneas_sucrine_find_contact_id_by_email(array $config, $email) {
    $email = strtolower(trim((string) $email));
    if ($email === '') {
        return null;
    }

    for ($page = 1; $page <= 3; $page++) {
        $result = castaneas_sucrine_request($config, 'GET', '/professional/contacts?limit=50&page=' . $page);
        $items = $result['data']['items'] ?? null;
        if (!is_array($items) || !$items) {
            return null;
        }
        foreach ($items as $contact) {
            $emails = array_merge([$contact['email'] ?? ''], is_array($contact['otherEmails'] ?? null) ? $contact['otherEmails'] : []);
            foreach ($emails as $candidate) {
                if (is_string($candidate) && strtolower(trim($candidate)) === $email) {
                    return trim((string) ($contact['_id'] ?? '')) ?: null;
                }
            }
        }
    }

    return null;
}

/** Rattache le client au groupe Sucrine des clients web. Ne fait jamais échouer la commande. */
function castaneas_sucrine_attach_contact_group(array $config, array $order, array $orderResult, $contactId = null) {
    $groupId = castaneas_sucrine_contact_group_id($config);
    if ($groupId === null) {
        return ['ok' => false, 'message' => 'Groupe de contacts Sucrine introuvable.'];
    }

    $contactId = $contactId
        ?: castaneas_sucrine_order_contact_id($orderResult['data'] ?? null)
        ?: castaneas_sucrine_find_contact_id_by_email($config, $order['billing']['email'] ?? '');
    if ($contactId === null) {
        return ['ok' => false, 'groupId' => $groupId, 'message' => 'Contact Sucrine introuvable pour cette commande.'];
    }

    $result = castaneas_sucrine_request($config, 'POST', '/professional/contacts/groups/' . rawurlencode($groupId) . '/addToGroup', [
        'contacts' => [$contactId],
    ]);

    return [
        'ok' => !empty($result['ok']),
        'groupId' => $groupId,
        'contactId' => $contactId,
        'message' => $result['message'] ?? null,
    ];
}

/** Identifiant Sucrine de la commande créée (format de réponse non documenté). */
function castaneas_sucrine_created_order_id($data) {
    if (!is_array($data)) {
        return null;
    }
    $candidates = [
        $data['_id'] ?? null,
        $data['id'] ?? null,
        $data['order']['_id'] ?? null,
        $data['customerOrder']['_id'] ?? null,
        $data['orderId'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return null;
}

/** Enregistre le règlement (déjà encaissé sur le site) sur la commande Sucrine. Ne fait jamais échouer l'envoi. */
function castaneas_sucrine_register_payment(array $config, array $order, $sucrineOrderId) {
    if ($sucrineOrderId === null) {
        return ['ok' => false, 'message' => 'Identifiant de commande Sucrine absent de la réponse.'];
    }

    $body = [
        'amount' => round((float) ($order['total'] ?? 0), 2),
        'paid' => true,
        'payload' => [
            'source' => 'castaneas',
            'reference' => (string) ($order['id'] ?? ''),
            'gateway' => (string) ($order['payment']['gateway'] ?? ''),
            'transactionId' => (string) ($order['payment']['transactionId'] ?? ''),
            'paidAt' => (string) ($order['paidAt'] ?? ''),
        ],
    ];
    $paymentMean = trim((string) ($config['payment_mean'] ?? ''));
    if ($paymentMean !== '') {
        $body['paymentMean'] = $paymentMean;
    }

    $result = castaneas_sucrine_request($config, 'POST', '/professional/payments/orders/' . rawurlencode($sucrineOrderId) . '/payment/registerPayment', $body);

    return [
        'ok' => !empty($result['ok']),
        'orderId' => $sucrineOrderId,
        'amount' => $body['amount'],
        'message' => $result['message'] ?? null,
    ];
}

function castaneas_sucrine_send_order(array $order) {
    if (!castaneas_sucrine_is_ready()) {
        return [
            'ok' => false,
            'code' => 'sucrine_not_configured',
            'message' => 'Configuration Sucrine absente.',
        ];
    }

    $issues = [];
    $items = castaneas_sucrine_build_items($order, $issues);
    if ($issues) {
        return [
            'ok' => false,
            'code' => 'sucrine_lookup_failed',
            'message' => implode(' | ', array_values(array_unique($issues))),
        ];
    }

    if (empty($items)) {
        return [
            'ok' => false,
            'code' => 'sucrine_missing_products',
            'message' => 'Aucun produit de la commande ne possède de référence Sucrine.',
        ];
    }
    $config = castaneas_sucrine_config();
    $deliveryPoint = castaneas_sucrine_delivery_point($order, $config);
    if ($deliveryPoint === '') {
        return [
            'ok' => false,
            'code' => 'sucrine_missing_delivery_point',
            'message' => 'Mode de distribution Sucrine manquant. Configurez sucrine.delivery_point.',
            'payload' => castaneas_sucrine_payload($order),
        ];
    }

    $payload = castaneas_sucrine_payload($order, $deliveryPoint, $items);
    $result = castaneas_sucrine_post_order($config, $payload, $deliveryPoint);
    $existingContactId = castaneas_sucrine_existing_contact_id($result);
    if ($existingContactId !== null) {
        unset($payload['newContact']);
        $payload['orderedBy'] = $existingContactId;

        $result = castaneas_sucrine_post_order($config, $payload, $deliveryPoint);
        if (!empty($result['ok'])) {
            $result['retriedWithOrderedBy'] = true;
            $result['orderedBy'] = $existingContactId;
        }
    }

    if (!empty($result['ok'])) {
        $result['sucrineOrderId'] = castaneas_sucrine_created_order_id($result['data'] ?? null);
        if (!empty($config['register_payment'])) {
            $result['payment'] = castaneas_sucrine_register_payment($config, $order, $result['sucrineOrderId']);
        }
        $result['contactGroup'] = castaneas_sucrine_attach_contact_group($config, $order, $result, $existingContactId);
    }

    return $result;
}