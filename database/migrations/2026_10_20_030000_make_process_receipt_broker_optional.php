<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('broker_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('process_receipts')->whereNull('broker_id')->exists()) {
            throw new RuntimeException('Existem recebimentos sem corretor. Vincule um corretor ou remova essas divisões antes de reverter a migração.');
        }
        Schema::table('process_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('broker_id')->nullable(false)->change();
        });
    }
};
