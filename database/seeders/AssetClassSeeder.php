<?php

namespace Database\Seeders;

use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Support\MarketTicker;
use Illuminate\Database\Seeder;

class AssetClassSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MarketTicker::classes() as $slug => $name) {
            AssetClass::updateOrCreate(['slug' => $slug], ['name' => $name, 'status' => true]);
        }
    }
}
