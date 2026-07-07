<?php

function castaneas_shipping_line_qty(array $item) {
    $bundleQty = max(1, (int) ($item['offerQty'] ?? 1));

    return max(1, (int) ($item['qty'] ?? 1)) * $bundleQty;
}

function castaneas_shipping_estimate_items(array $items) {
    $totalWeightG = 0;
    $maxLength = 0.0;
    $maxWidth = 0.0;
    $maxHeight = 0.0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
        $lineQty = castaneas_shipping_line_qty($item);

        $weightG = max(0, (int) ($shipping['weightG'] ?? 0));
        $totalWeightG += $weightG * $lineQty;

        $lengthCm = max(0.0, (float) ($shipping['lengthCm'] ?? 0));
        $widthCm = max(0.0, (float) ($shipping['widthCm'] ?? 0));
        $heightCm = max(0.0, (float) ($shipping['heightCm'] ?? 0));

        if ($lengthCm > 0 && $widthCm > 0 && $heightCm > 0) {
            $maxLength = max($maxLength, $lengthCm);
            $maxWidth = max($maxWidth, $widthCm);
            $maxHeight = max($maxHeight, $heightCm);
        }
    }

    if ($totalWeightG <= 0) {
        $totalWeightG = 250;
    }

    $parcel = [
        'weight' => [
            'value' => number_format(max(0.05, $totalWeightG / 1000), 3, '.', ''),
            'unit' => 'kg',
        ],
    ];

    if ($maxLength > 0 && $maxWidth > 0 && $maxHeight > 0) {
        $parcel['dimensions'] = [
            'length' => number_format($maxLength, 1, '.', ''),
            'width' => number_format($maxWidth, 1, '.', ''),
            'height' => number_format($maxHeight, 1, '.', ''),
            'unit' => 'cm',
        ];
    }

    return [
        'productWeightG' => $totalWeightG,
        'parcel' => $parcel,
        'parcels' => [$parcel],
    ];
}

function castaneas_shipping_estimate_order(array $order) {
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];

    return castaneas_shipping_estimate_items($items);
}