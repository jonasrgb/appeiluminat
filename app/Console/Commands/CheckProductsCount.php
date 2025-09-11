<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Mail\ProductsCountReport;

class CheckProductsCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-products-count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifică numărul de produse fara stoc din magazin și trimite raportul pe email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $stores = [
            [
                'name' => 'eiluminat',
                'store_url' => env('SHOPIFY_SHOP_EILUMINAT_URL'),
                'access_token' => env('ACCESS_TOKEN_ADMIN_EILUMINAT'),
                'collection_id' => 'gid://shopify/Collection/647888273754',
                'collection_url' => 'https://admin.shopify.com/store/eiluminat/products?start=MQ%3D%3D&collection_id=647888273754&status=ACTIVE', 
            ],
            [
                'name' => 'powerleds',
                'store_url' => env('STORE_URL_POWERLED'),
                'access_token' => env('ACCESS_TOKEN_ADMIN_POWER'),
                'collection_id' => 'gid://shopify/Collection/646094258515',
                'collection_url' => 'https://admin.shopify.com/store/powerleds-ro/products?start=MQ%3D%3D&collection_id=646094258515&status=ACTIVE', 
            ],
            [
                'name' => 'lustreled',
                'store_url' => env('STORE_URL_LUSTRELED'),
                'access_token' => env('ACCESS_TOKEN_ADMIN_LUSTRELED'),
                'collection_id' => 'gid://shopify/Collection/657237737817',
                'collection_url' => 'https://admin.shopify.com/store/lustreled/products?start=MQ%3D%3D&collection_id=657237737817&status=ACTIVE', 
            ],
        ];

        $reports = [];

        foreach ($stores as $store) {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $store['access_token'],
                'Content-Type' => 'application/json',
            ])->post("https://{$store['store_url']}/admin/api/2025-01/graphql.json", [
                'query' => "
                    query {
                        collection(id: \"{$store['collection_id']}\") {
                            id
                            title
                            productsCount {
                                count
                            }
                        }
                    }
                "
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $count = $data['data']['collection']['productsCount']['count'] ?? null;
                $title = $data['data']['collection']['title'] ?? 'Unknown';

                $reports[] = [
                    'store' => $store['name'],
                    'collection_title' => $title,
                    'products_count' => $count,
                    'collection_url' => $store['collection_url'],
                ];
            } else {
                $this->error("Eroare la magazinul {$store['name']}: " . $response->body());
            }
        }

        if (!empty($reports)) {
            Mail::to('arseneionut@eiluminat.ro')->send(new ProductsCountReport($reports));
            $this->info("Email cu raportul a fost trimis cu succes!");
        } else {
            $this->warn("Nu am găsit date pentru raportul săptămânal.");
        }
    }
}
