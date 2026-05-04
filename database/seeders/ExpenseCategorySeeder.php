<?php

namespace Database\Seeders;

use App\Domains\Banking\Models\Category;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Alimentacao' => 'Utensils',
            'Assinaturas' => 'Repeat',
            'Educacao' => 'GraduationCap',
            'Emprestimo' => 'Handshake',
            'Impostos' => 'Receipt',
            'Lazer' => 'Gamepad2',
            'Moradia' => 'Home',
            'Outros' => 'Tag',
            'Pets' => 'PawPrint',
            'Saude' => 'HeartPulse',
            'Transporte' => 'Car',
            'Venera' => 'Tag',
            'Vestuario' => 'Shirt',
        ];

        foreach ($categories as $name => $icon) {
            Category::updateOrCreate(
                [
                    'parent_id' => null,
                    'name' => $name,
                    'type' => 'expense',
                ],
                [
                    'icon' => $icon,
                    'status' => true,
                ],
            );
        }
    }
}
