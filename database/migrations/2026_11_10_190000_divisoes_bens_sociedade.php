<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Divisões × Bens: tipo da despesa (despesa comum ou compra do bem), modo de quem paga a mais na compra
 * (adiantamento aos sócios ou aumentar a própria parte) e o bem comprado em sociedade ligado a um grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('division_expenses', function (Blueprint $t) {
            $t->string('kind', 10)->default('expense')->after('category');
            $t->string('purchase_mode', 10)->nullable()->after('kind');
        });
        Schema::table('properties', function (Blueprint $t) {
            $t->foreignId('division_group_id')->nullable()->after('bank_account_id')->constrained('division_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->dropConstrainedForeignId('division_group_id');
        });
        Schema::table('division_expenses', function (Blueprint $t) {
            $t->dropColumn(['kind', 'purchase_mode']);
        });
    }
};
