<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_send_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 40)->default('gmail_send');
            $t->string('account_email', 190);
            $t->text('access_token'); // encrypted cast
            $t->text('refresh_token'); // encrypted cast
            $t->timestamp('expires_at')->nullable();
            $t->json('scopes')->nullable();
            $t->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('connected_at')->nullable();
            $t->string('last_self_test_status', 20)->nullable();
            $t->timestamp('last_self_test_at')->nullable();
            $t->string('last_self_test_error', 190)->nullable();
            $t->timestamps();
            $t->unique('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_send_credentials');
    }
};
