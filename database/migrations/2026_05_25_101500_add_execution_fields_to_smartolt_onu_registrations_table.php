<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->longText('execution_output')->nullable()->after('cli_script');
            $table->text('execution_error')->nullable()->after('execution_output');
            $table->timestamp('executed_at')->nullable()->after('execution_error');
            $table->foreignId('executed_by')->nullable()->after('executed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('smartolt_onu_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('executed_by');
            $table->dropColumn(['execution_output', 'execution_error', 'executed_at']);
        });
    }
};
