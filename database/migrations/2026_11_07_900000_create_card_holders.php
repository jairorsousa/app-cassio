<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Titular do cartão vira lista editável (card_holders). Os campos antigos (holder_type/holder_name/holder_contact_id)
 * ficam intactos; cada cartão com titular antigo ganha o titular equivalente. Nada é apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_holders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->foreignId('card_holder_id')->nullable()->after('bank')->constrained('card_holders')->nullOnDelete();
        });

        $now = now();
        $holder = function (string $name) use ($now): int {
            $name = trim($name);
            $id = DB::table('card_holders')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');

            return $id ?: DB::table('card_holders')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        };
        $holder('Cássio');
        foreach (DB::table('credit_cards')->whereNotNull('holder_type')->where('holder_type', '!=', '')->get() as $card) {
            $name = match ($card->holder_type) {
                'cassio' => 'Cássio',
                'luciane' => 'Luciane',
                'filho' => trim((string) $card->holder_name) !== '' ? trim((string) $card->holder_name) : null,
                'contato' => DB::table('contacts')->where('id', $card->holder_contact_id)->value('name'),
                default => null,
            };
            if ($name) {
                DB::table('credit_cards')->where('id', $card->id)->update(['card_holder_id' => $holder($name)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_holder_id');
        });
        Schema::dropIfExists('card_holders');
    }
};
