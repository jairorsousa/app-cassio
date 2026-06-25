<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('type', 20)->default('cedente')->after('name');
            $table->string('zip_code', 20)->nullable()->after('address');
            $table->string('street')->nullable()->after('zip_code');
            $table->string('number', 30)->nullable()->after('street');
            $table->string('complement')->nullable()->after('number');
            $table->string('city', 120)->nullable()->after('complement');
            $table->string('state', 2)->nullable()->after('city');

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn([
                'type',
                'zip_code',
                'street',
                'number',
                'complement',
                'city',
                'state',
            ]);
        });
    }
};
