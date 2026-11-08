<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mencatat jadwal terakhir yang sudah diberitahukan. Dengan begitu perubahan waktu atau tutor bisa
        // diumumkan dengan jam lama dan baru, dan pembatalan hanya dikabarkan ke yang pernah diberi tahu.
        Schema::table('consultations', function (Blueprint $table) {
            $table->timestampTz('tutor_notified_starts_at')->nullable()->after('tutor_notified_at');
            $table->unsignedBigInteger('tutor_notified_tutor_id')->nullable()->after('tutor_notified_starts_at');
            $table->timestamp('tutor_cancel_notified_at')->nullable()->after('tutor_notified_tutor_id');
        });

        Schema::table('consultation_participants', function (Blueprint $table) {
            $table->timestampTz('confirmed_starts_at')->nullable()->after('confirmation_sent_at');
            $table->unsignedBigInteger('confirmed_tutor_id')->nullable()->after('confirmed_starts_at');
            $table->timestamp('cancel_notified_at')->nullable()->after('confirmed_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_participants', function (Blueprint $table) {
            $table->dropColumn(['confirmed_starts_at', 'confirmed_tutor_id', 'cancel_notified_at']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['tutor_notified_starts_at', 'tutor_notified_tutor_id', 'tutor_cancel_notified_at']);
        });
    }
};
