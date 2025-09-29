<?php

return [
    'mirror_bootstrap' => [
        // Global on/off switch for auto-bootstrap when no mirror is found
        'enabled' => env('MIRROR_BOOTSTRAP_ENABLED', false),
        // Dry-run: when true, only log intended actions; no DB writes or Shopify mutations
        'dry_run' => env('MIRROR_BOOTSTRAP_DRY_RUN', true),
    ],
];

