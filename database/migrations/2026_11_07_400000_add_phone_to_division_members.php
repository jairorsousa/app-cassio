<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Celular opcional do participante (E.164, ex.: +5585999998888), usado no botão "Cobrar" (wa.me). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('division_members', fn (Blueprint $t) => $t->string('phone', 20)->nullable()->after('email'));
    }

    public function down(): void
    {
        Schema::table('division_members', fn (Blueprint $t) => $t->dropColumn('phone'));
    }
};
