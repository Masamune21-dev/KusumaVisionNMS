<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tautan ke identitas terpusat di sso.kusumavision.net.
     *
     * Nullable karena baris lama belum tertaut sampai `sso:link-back` dijalankan
     * (atau sampai pemiliknya login sekali lewat SSO).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('sso_user_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sso_user_id');
        });
    }
};
