<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Draf jadwal hasil ekstraksi AI dari chat WA. Baru menjadi sesi setelah disetujui admin.
        Schema::create('schedule_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wa_conversations')->cascadeOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('wa_messages')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index(); // App\Enums\DraftStatus
            $table->json('extracted'); // nama, jenjang, bidang, layanan, waktu yang diminta
            $table->string('ai_provider', 30)->nullable();
            $table->string('ai_model', 60)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
        });

        // Satu tabel untuk sesi privat dan grup. Peserta disimpan di consultation_participants.
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // App\Enums\ConsultationType
            $table->foreignId('tutor_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('topic')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('meeting_mode', 20)->default('zoom'); // App\Enums\MeetingMode
            $table->string('meeting_url')->nullable();
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            // Kuota yang dipotong dari paket peserta saat sesi selesai.
            $table->string('benefit_type', 40)->nullable();
            $table->string('status', 20)->default('scheduled')->index(); // App\Enums\ConsultationStatus
            $table->text('tutor_notes')->nullable();
            $table->string('cancel_reason')->nullable();
            // Penanda notifikasi, supaya n8n tidak mengirim pesan yang sama dua kali.
            $table->timestamp('tutor_notified_at')->nullable();
            $table->timestamp('tutor_reminded_at')->nullable();
            $table->timestamp('sheet_synced_at')->nullable();
            $table->foreignId('schedule_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tutor_id', 'starts_at']);
            $table->index('starts_at');
        });

        Schema::create('consultation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('attendance', 20)->default('pending'); // App\Enums\AttendanceStatus
            $table->timestamp('quota_deducted_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('reminder_day_sent_at')->nullable();
            $table->timestamp('reminder_hour_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['consultation_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_participants');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('schedule_drafts');
    }
};
