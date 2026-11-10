<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Divisões: categoria do Financeiro do Cassio para a parte dele em cada despesa, separada da categoria do grupo
 * (que os outros participantes veem). Só estrutura; o preenchimento das despesas antigas é feito por
 * DivisionService::backfillOwnerCategories(), copiando a categoria que cada lançamento já tem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('division_expenses', function (Blueprint $t) {
            $t->foreignId('owner_category_id')->nullable()->after('category')->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('division_expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('owner_category_id');
        });
    }
};
