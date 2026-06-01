<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            // Whitelist of alarm types that should be pushed to Telegram.
            // NULL means "all types" (back-compat for existing installs); an
            // explicit array (even empty) is honoured as-is.
            $table->json('notify_types')->nullable()->after('notify_on_clear');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn('notify_types');
        });
    }
};
