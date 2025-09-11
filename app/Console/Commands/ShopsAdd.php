<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

class ShopsAdd extends Command
{
    protected $signature = 'shops:add
        {domain : ex. powerledsync.myshopify.com}
        {--name=}
        {--token=}
        {--api-version=2024-07}
        {--source=0}
        {--active=1}
        {--location=}'; // <- nou

    protected $description = 'Adaugă/actualizează un magazin Shopify în tabelul shops';

    public function handle(): int
    {
        $domain = $this->argument('domain');

        $shop = Shop::updateOrCreate(
            ['domain' => $domain],
            [
                'name'              => $this->option('name') ?? $domain,
                'access_token'      => $this->option('token') ?? '',
                'api_version'       => $this->option('version'),
                'is_source'         => (bool)$this->option('source'),
                'is_active'         => (bool)$this->option('active'),
                'location_legacy_id'=> $this->option('location') ? (int)$this->option('location') : null, // <- nou
            ]
        );

        $this->info("Saved shop #{$shop->id} {$shop->domain} (source={$shop->is_source}, location={$shop->location_legacy_id})");
        return self::SUCCESS;
    }
}
