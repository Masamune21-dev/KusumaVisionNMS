<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferensi tema antarmuka: 'dark', 'light', atau 'system'.
     *
     * NULL berarti pengguna belum pernah memilih; resolusinya jatuh ke cookie
     * `kv_theme` lalu ke bawaan 'dark', sehingga tidak ada yang tampilannya
     * berubah sendiri setelah kolom ini ada.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
