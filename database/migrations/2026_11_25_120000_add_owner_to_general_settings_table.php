<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('general_settings', 'owner')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->string('owner')->default('PT Berkah Media Kusuma Vision')->after('app_version');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('general_settings', 'owner')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->dropColumn('owner');
            });
        }
    }
};