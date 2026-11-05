<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 3: papéis múltiplos em contatos, vínculos estruturados de comissão,
 * despesas de processo com processo/cliente, corretores padrão no requisitório.
 * Não apaga dados. Adiantamentos existentes ficam como pessoais (kind=personal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('type');
        });

        // Compatibilidade: cada contato herda o type atual como único papel.
        DB::table('contacts')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $type = $row->type ?: 'cedente';
                DB::table('contacts')->where('id', $row->id)->update([
                    'roles' => json_encode([$type]),
                ]);
            }
        });

        Schema::table('broker_commissions', function (Blueprint $table) {
            $table->string('commission_mode', 12)->default('fixed')->after('percentage_applied');
            $table->decimal('rate_or_amount', 15, 3)->nullable()->after('commission_mode');
            $table->string('base', 12)->default('fees')->after('rate_or_amount');
            $table->string('process_ref', 120)->nullable()->after('name');
            $table->foreignId('writ_id')->nullable()->after('process_ref')->constrained('writs')->nullOnDelete();
        });

        // Preenche mode/rate a partir dos dados já existentes, sem mudar valores.
        DB::table('broker_commissions')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $pct = (float) $row->percentage_applied;
                $mode = $pct > 0 ? 'percent' : 'fixed';
                $rate = $mode === 'percent' ? $pct : (float) $row->commission_amount;
                DB::table('broker_commissions')->where('id', $row->id)->update([
                    'commission_mode' => $mode,
                    'rate_or_amount' => $rate,
                    'base' => 'fees',
                ]);
            }
        });

        Schema::table('broker_advances', function (Blueprint $table) {
            $table->string('kind', 32)->default('personal')->after('broker_id');
            $table->string('process_ref', 120)->nullable()->after('notes');
            $table->foreignId('writ_id')->nullable()->after('process_ref')->constrained('writs')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->after('writ_id')->constrained('contacts')->nullOnDelete();
        });

        Schema::table('broker_process_expenses', function (Blueprint $table) {
            $table->string('process_ref', 120)->nullable()->after('description');
            $table->foreignId('writ_id')->nullable()->after('process_ref')->constrained('writs')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->after('writ_id')->constrained('contacts')->nullOnDelete();
        });

        Schema::table('brokers', function (Blueprint $table) {
            $table->string('default_mode', 12)->nullable()->after('notes');
            $table->decimal('default_rate_or_amount', 15, 3)->nullable()->after('default_mode');
            $table->string('default_base', 12)->nullable()->after('default_rate_or_amount');
        });

        Schema::create('writ_default_brokers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writ_id')->constrained('writs')->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->restrictOnDelete();
            $table->string('base', 12)->default('fees');
            $table->string('mode', 12)->default('percent');
            $table->decimal('rate_or_amount', 15, 3)->default(0);
            $table->timestamps();
            $table->unique(['writ_id', 'broker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writ_default_brokers');

        Schema::table('brokers', function (Blueprint $table) {
            $table->dropColumn(['default_mode', 'default_rate_or_amount', 'default_base']);
        });

        Schema::table('broker_process_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('writ_id');
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn('process_ref');
        });

        Schema::table('broker_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('writ_id');
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn(['kind', 'process_ref']);
        });

        Schema::table('broker_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('writ_id');
            $table->dropColumn(['commission_mode', 'rate_or_amount', 'base', 'process_ref']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
