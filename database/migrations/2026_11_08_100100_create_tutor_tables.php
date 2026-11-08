<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bidang keahlian. "group" = rumpun ilmu, dipakai saran tutor saat bidang tidak sama persis.
        Schema::create('expertise_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('group', 100)->index();
            $table->timestamps();
        });

        Schema::create('tutors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 20)->unique();
            $table->string('highest_degree', 2); // App\Enums\DegreeLevel (S2/S3)
            // Batas beban kerja. Saran tutor menurunkan skor tutor yang mendekati batas ini.
            $table->unsignedSmallInteger('max_weekly_sessions')->default(15);
            $table->boolean('is_active')->default(true)->index();
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::create('expertise_field_tutor', function (Blueprint $table) {
            $table->foreignId('tutor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expertise_field_id')->constrained()->cascadeOnDelete();
            $table->primary(['tutor_id', 'expertise_field_id']);
        });

        // Jam kerja rutin per hari. day_of_week mengikuti ISO: 1 = Senin, 7 = Minggu.
        Schema::create('tutor_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->index(['tutor_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_availabilities');
        Schema::dropIfExists('expertise_field_tutor');
        Schema::dropIfExists('tutors');
        Schema::dropIfExists('expertise_fields');
    }
};
