<?php

return [
    'mirror_bootstrap' => [
        // Global on/off switch for auto-bootstrap when no mirror is found
        'enabled' => env('MIRROR_BOOTSTRAP_ENABLED', false),
        // Dry-run: when true, only log intended actions; no DB writes or Shopify mutations
        'dry_run' => env('MIRROR_BOOTSTRAP_DRY_RUN', true),
    ],
    'stock_only_bg' => [
        // Temporary deep debug for BG stock-only sync
        'debug' => env('SHOP8_STOCK_DEBUG', false),
    ],
    'bem_watermark_sync' => [
        'enabled' => env('BEM_WATERMARK_SYNC_ENABLED', false),
        'dry_run' => env('BEM_WATERMARK_SYNC_DRY_RUN', true),
        'required_tag' => env('BEM_WATERMARK_SYNC_REQUIRED_TAG', 'wm_test'),
        'backup_shop_domain' => env('BEM_WATERMARK_BACKUP_SHOP_DOMAIN', 'eiluminatbackup.myshopify.com'),
        'notification_email' => env('BEM_WATERMARK_NOTIFICATION_EMAIL', 'mitnickoff121@gmail.com'),
        'width_ratio' => env('BEM_WATERMARK_WIDTH_RATIO', 0.25),
        'opacity' => env('BEM_WATERMARK_OPACITY', 15),
        'update_manifest_enabled' => env('BEM_WATERMARK_UPDATE_MANIFEST_ENABLED', false),
        'target_shop_domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('BEM_WATERMARK_TARGET_SHOP_DOMAINS', ''))
        ))),
        'domain_aliases' => [
            'eiluminat.myshopify.com' => 'eiluminat',
            'eiluminatbackup.myshopify.com' => 'eiluminat',
            'powerleds-ro.myshopify.com' => 'powerleds',
            'lustreled.myshopify.com' => 'lustreled',
            'iluminat-industrial.myshopify.com' => 'iluminat-industrial',
        ],
    ],
];
