<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('writ_assignors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writ_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('parte');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writ_assignors');
    }
};
