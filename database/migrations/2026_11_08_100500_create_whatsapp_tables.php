<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu percakapan per nomor WA. client_id terisi setelah nomor dicocokkan ke client.
        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('display_name')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wa_conversations')->cascadeOnDelete();
            $table->string('direction', 10); // App\Enums\MessageDirection
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('status', 20); // App\Enums\MessageStatus
            $table->string('provider', 20); // driver WA yang dipakai: waha, cloud_api
            // ID dari provider. Unik supaya webhook yang terkirim dua kali tidak tersimpan ganda.
            $table->string('provider_message_id')->nullable()->unique();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_conversations');
    }
};
