<?php

return [
    // ShipEngine REST API key (https://api.shipengine.com/v1/).
    'api_key' => env('SHIPENGINE_API_KEY', ''),

    // When true, ShipEngine runs against test data (no real carrier hits).
    'test' => env('SHIPENGINE_API_TEST', false),

    // Connected carrier account id (se-...). Not required for the GET /tracking
    // endpoint (which keys off carrier_code) but kept for label/other calls.
    'carrier_id' => env('SHIPENGINE_API_CARRIER_ID', ''),

    // Default carrier_code used for the tracking lookup.
    'carrier_code' => env('SHIPENGINE_CARRIER_CODE', 'usps'),
];
