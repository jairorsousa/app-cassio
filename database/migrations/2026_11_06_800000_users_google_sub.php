<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'google_sub')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('google_sub', 255)->nullable()->unique()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'google_sub')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropUnique(['google_sub']);
                $t->dropColumn('google_sub');
            });
        }
    }
};
