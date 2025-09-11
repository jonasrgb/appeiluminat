<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\ShopConnection;
use Illuminate\Console\Command;

class ShopsConnect extends Command
{
    protected $signature = 'shops:connect 
        {from : domain sursă} 
        {to* : domenii țintă (unul sau mai multe)}';

    protected $description = 'Leagă magazinul sursă de magazinele țintă pentru replicare produse';

    public function handle(): int
    {
        $fromDomain = $this->argument('from');
        $toDomains  = $this->argument('to');

        $from = Shop::where('domain', $fromDomain)->firstOrFail();

        foreach ($toDomains as $toDomain) {
            $to = Shop::where('domain', $toDomain)->firstOrFail();
            ShopConnection::firstOrCreate([
                'source_shop_id' => $from->id,
                'target_shop_id' => $to->id,
            ]);
            $this->info("Connected {$from->domain} -> {$to->domain}");
        }
        return self::SUCCESS;
    }
}
