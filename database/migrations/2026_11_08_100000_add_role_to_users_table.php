<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nilai: App\Enums\UserRole. Disimpan sebagai string supaya mudah menambah role baru.
            $table->string('role', 20)->default('tutor')->after('email')->index();
            $table->string('phone', 20)->nullable()->after('role');
            // Akun nonaktif tidak bisa login, tapi riwayatnya tetap tersimpan.
            $table->boolean('is_active')->default(true)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'is_active']);
        });
    }
};
