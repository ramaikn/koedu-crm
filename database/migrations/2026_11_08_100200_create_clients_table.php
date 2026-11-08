<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nomor WA format internasional tanpa "+", mis. 6281234567890. Kunci pencocokan chat masuk.
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable();
            $table->string('university')->nullable();
            $table->string('study_program')->nullable(); // jurusan/prodi
            $table->foreignId('expertise_field_id')->nullable()->constrained()->nullOnDelete();
            $table->string('degree_level', 2)->nullable(); // App\Enums\DegreeLevel
            $table->string('status', 20)->default('lead')->index(); // App\Enums\ClientStatus
            $table->string('source', 50)->nullable(); // whatsapp, website, referral, dan lainnya
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Data client tidak dihapus permanen supaya riwayat sesi dan pembayaran tetap utuh.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
