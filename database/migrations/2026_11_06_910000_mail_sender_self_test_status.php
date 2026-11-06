<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_sender_settings', function (Blueprint $t) {
            $t->string('last_self_test_status', 20)->nullable()->after('password_set_at');
            $t->timestamp('last_self_test_at')->nullable()->after('last_self_test_status');
            $t->string('last_self_test_error', 190)->nullable()->after('last_self_test_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_sender_settings', function (Blueprint $t) {
            $t->dropColumn(['last_self_test_status', 'last_self_test_at', 'last_self_test_error']);
        });
    }
};
