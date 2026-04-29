<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 20)->unique();
            $table->string('name');
            $table->foreignId('asset_class_id')->constrained()->restrictOnDelete();
            $table->string('sector')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('asset_class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
