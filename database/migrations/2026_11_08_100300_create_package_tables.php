<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('category', 20)->index(); // App\Enums\PackageCategory
            $table->string('name');
            $table->string('schedule_type', 20)->default('flexible'); // App\Enums\ScheduleType
            $table->string('delivery', 20)->default('private'); // App\Enums\DeliveryMode
            // Diisi bila harga paket beda per jenjang (mis. beasiswa S1 dan S2).
            $table->string('degree_level', 2)->nullable();
            $table->unsignedSmallInteger('duration_days');
            // Rupiah dalam bilangan bulat, tanpa desimal.
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('list_price')->nullable(); // harga coret
            $table->unsignedSmallInteger('max_participants')->nullable(); // untuk kelas grup
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('package_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('benefit_type', 40); // App\Enums\BenefitType
            $table->unsignedInteger('quantity')->nullable(); // null = tanpa batas
            $table->unique(['package_id', 'benefit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_benefits');
        Schema::dropIfExists('packages');
    }
};
