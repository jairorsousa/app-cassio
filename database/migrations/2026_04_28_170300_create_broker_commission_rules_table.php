<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('percentage', 6, 3); // e.g. 15.000 = 15%
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'case_type_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_commission_rules');
    }
};
