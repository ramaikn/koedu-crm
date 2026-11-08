<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paket yang dibeli client. Harga dan kuota disalin dari paket saat dibeli,
        // supaya perubahan katalog tidak mengubah paket yang sudah berjalan.
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('primary_tutor_id')->nullable()->constrained('tutors')->nullOnDelete();
            $table->string('status', 20)->default('active')->index(); // App\Enums\EnrollmentStatus
            $table->date('starts_on');
            $table->date('ends_on')->index();
            $table->unsignedBigInteger('price');
            // Tanpa DP/cicilan: satu paket = satu pembayaran.
            $table->string('payment_status', 20)->default('unpaid')->index(); // App\Enums\PaymentStatus
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_proof_path')->nullable();
            $table->foreignId('payment_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('enrollment_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('benefit_type', 40);
            $table->unsignedInteger('total')->nullable(); // null = tanpa batas
            $table->unsignedInteger('used')->default(0);
            $table->unique(['enrollment_id', 'benefit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_quotas');
        Schema::dropIfExists('enrollments');
    }
};
