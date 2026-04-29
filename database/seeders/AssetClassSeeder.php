<?php

namespace Database\Seeders;

use App\Domains\Investments\Models\AssetClass;
use Illuminate\Database\Seeder;

class AssetClassSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
            ['name' => 'Ações', 'slug' => 'acoes'],
            ['name' => 'FIIs', 'slug' => 'fiis'],
            ['name' => 'ETFs', 'slug' => 'etfs'],
            ['name' => 'BDRs', 'slug' => 'bdrs'],
            ['name' => 'Outros', 'slug' => 'outros'],
        ];

        foreach ($classes as $c) {
            AssetClass::updateOrCreate(['slug' => $c['slug']], $c + ['status' => true]);
        }
    }
}
