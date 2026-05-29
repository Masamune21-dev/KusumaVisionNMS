<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            // Secret token validated against the X-Telegram-Bot-Api-Secret-Token header
            // so only Telegram can POST to the webhook endpoint.
            $table->text('webhook_secret')->nullable()->after('chat_id');
            // Inbound command handling toggle, separate from the outbound `enabled` flag.
            $table->boolean('commands_enabled')->default(false)->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'commands_enabled']);
        });
    }
};
