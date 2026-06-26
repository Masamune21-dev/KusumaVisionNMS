<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr069_bulk_tasks', function (Blueprint $table) {
            // Scope ke satu PON port. Null = task lama (sapu seluruh OLT) — TR069
            // massal kini selalu per-port, tapi kolom nullable agar baris lama tetap valid.
            $table->unsignedInteger('slot')->nullable()->after('created_by');
            $table->unsignedInteger('port')->nullable()->after('slot');
        });
    }

    public function down(): void
    {
        Schema::table('tr069_bulk_tasks', function (Blueprint $table) {
            $table->dropColumn(['slot', 'port']);
        });
    }
};
