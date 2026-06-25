<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('phones')->nullable()->after('phone');
            $table->json('emails')->nullable()->after('email');
            $table->string('pix_key_type', 20)->nullable()->after('pix_key');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['phones', 'emails', 'pix_key_type']);
        });
    }
};
