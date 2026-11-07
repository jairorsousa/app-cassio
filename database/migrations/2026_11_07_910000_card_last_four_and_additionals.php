<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cartão principal e adicionais: final (4 dígitos) separado do nome e "Adicional de" (cartão principal).
 * Lançamento de adicional fica na fatura do principal, com additional_card_id para filtrar por adicional/titular.
 * O final é preenchido a partir do nome só quando é inequívoco (um único grupo de 4 dígitos, sem repetir em outro cartão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->string('last_four', 4)->nullable()->after('name');
            $table->string('card_type', 12)->default('principal')->after('last_four'); // principal | virtual | adicional
            $table->foreignId('parent_card_id')->nullable()->after('card_type')->constrained('credit_cards')->nullOnDelete();
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('additional_card_id')->nullable()->after('credit_card_id')->constrained('credit_cards')->nullOnDelete();
        });

        $cards = DB::table('credit_cards')->get(['id', 'name']);
        $guess = [];
        foreach ($cards as $c) {
            preg_match_all('/(?<!\d)(\d{4})(?!\d)/', (string) $c->name, $m);
            if (count($m[1]) === 1) {
                $guess[$c->id] = $m[1][0];
            }
        }
        $counts = array_count_values($guess);
        foreach ($guess as $id => $four) {
            if ($counts[$four] === 1) {
                DB::table('credit_cards')->where('id', $id)->update(['last_four' => $four]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('additional_card_id');
        });
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_card_id');
            $table->dropColumn(['last_four', 'card_type']);
        });
    }
};
