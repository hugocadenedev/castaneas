<?php

return [
    'base_url' => 'https://www.castaneas.fr',
    'payment_simulate' => false,
    'up2pay' => [
        'site' => '1234567',
        'rang' => '01',
        'identifiant' => '123456789',
        'hmac_key' => 'VOTRE_CLE_HMAC',
        'gateway_url' => 'https://tpeweb.e-transactions.fr/php/',
        'currency' => '978',
        'language' => 'FRA',
        'hash_algo' => 'SHA512',
    ],
    'sucrine' => [
        'api_key' => 'VOTRE_CLE_API_SUCRINE',
        'base_url' => 'https://app.sucrine.club/api',
    ],
    'sendcloud' => [
        'public_key' => 'VOTRE_CLE_PUBLIQUE_SENDCLOUD',
        'secret_key' => 'VOTRE_CLE_PRIVEE_SENDCLOUD',
        'base_url' => 'https://panel.sendcloud.sc/api/v2',
        'sender_address' => '',
        'ca_bundle' => '',
        'skip_ssl_verify' => false,
        'shipping_method_id' => '',
        'shipping_method_name' => '',
        'request_label' => true,
        'apply_shipping_rules' => true,
    ],
];