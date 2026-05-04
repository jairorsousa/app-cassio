<?php

namespace Database\Seeders;

use App\Domains\Banking\Models\Category;
use Illuminate\Database\Seeder;

class IncomeCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Cashback' => 'Gift',
            'Emprestimo' => 'Handshake',
            'Freelance' => 'Briefcase',
            'Investimentos' => 'TrendingUp',
            'Outros' => 'Tag',
            'Salario' => 'Wallet',
        ];

        foreach ($categories as $name => $icon) {
            Category::updateOrCreate(
                [
                    'parent_id' => null,
                    'name' => $name,
                    'type' => 'income',
                ],
                [
                    'icon' => $icon,
                    'status' => true,
                ],
            );
        }
    }
}
