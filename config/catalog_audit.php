<?php

return [
    'shops' => [
        'eiluminat' => 'eiluminat.myshopify.com',
        'lustreled' => 'lustreled.myshopify.com',
        'powerleds' => 'powerleds-ro.myshopify.com',
        'industrial' => 'iluminat-industrial.myshopify.com',
        'bulgaria' => 'eiluminat-bg.myshopify.com',
    ],
    'connection' => 'database_catalog_audit',
    'queue' => 'catalog_audit',
    'timeout_seconds' => 1200,
    'poll_seconds' => 5,
];
