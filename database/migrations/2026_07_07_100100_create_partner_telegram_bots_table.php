<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot Telegram per-partner (self-service). Kolom mengikuti telegram_settings (singleton
 * global) + user_id unik (1 bot per partner). Secret di-enkripsi di layer model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->text('bot_token')->nullable();
            $table->text('chat_id')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('commands_enabled')->default(false);
            $table->string('min_severity')->default('warning');
            $table->boolean('notify_on_raise')->default(true);
            $table->boolean('notify_on_clear')->default(true);
            $table->json('notify_types')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_telegram_bots');
    }
};
