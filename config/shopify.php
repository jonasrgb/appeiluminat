<?php

return [
    // Webhook signing secret. Prefer API_KEY_SECRET_EILUMINAT, fallback to SHOPIFY_WEBHOOK_SECRET.
    'webhook_secret' => env('API_KEY_SECRET_EILUMINAT', env('SHOPIFY_WEBHOOK_SECRET')),
];

