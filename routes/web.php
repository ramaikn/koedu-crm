<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleDraftController;
use App\Http\Controllers\TutorController;
use Illuminate\Support\Facades\Route;

// Aplikasi internal: tidak ada halaman publik, langsung ke dashboard (atau login).
Route::redirect('/', '/dashboard')->name('home');

// Hak akses per data diatur di Policy. Middleware role hanya membatasi halaman yang tidak relevan untuk suatu role.
Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Jadwal: semua role. Tutor hanya melihat sesinya sendiri.
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::middleware('role:admin')->group(function () {
        Route::get('schedule/create', [ScheduleController::class, 'create'])->name('schedule.create');
        Route::post('schedule', [ScheduleController::class, 'store'])->name('schedule.store');
        Route::post('schedule/check', [ScheduleController::class, 'check'])->name('schedule.check');
        Route::post('schedule/suggest-tutors', [ScheduleController::class, 'suggestTutors'])->name('schedule.suggest');
        Route::post('schedule-drafts/{draft}/reject', [ScheduleDraftController::class, 'reject'])->name('drafts.reject');
        Route::get('schedule/{consultation}/edit', [ScheduleController::class, 'edit'])->name('schedule.edit');
        Route::put('schedule/{consultation}', [ScheduleController::class, 'update'])->name('schedule.update');
        Route::post('schedule/{consultation}/cancel', [ScheduleController::class, 'cancel'])->name('schedule.cancel');
    });
    Route::get('schedule/{consultation}', [ScheduleController::class, 'show'])->name('schedule.show');
    Route::post('schedule/{consultation}/complete', [ScheduleController::class, 'complete'])->name('schedule.complete');

    // Client: tutor hanya melihat client yang ia bimbing (ClientPolicy).
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::middleware('role:admin,director')->get('clients/search', [ClientController::class, 'search'])->name('clients.search');
    Route::middleware('role:admin')->group(function () {
        Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

        // Paket client, pembayaran (tanpa DP/cicilan), dan kuota.
        Route::post('clients/{client}/enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::put('enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('enrollments.update');
        Route::post('enrollments/{enrollment}/payment', [EnrollmentController::class, 'recordPayment'])->name('enrollments.payment');
        Route::delete('enrollments/{enrollment}/payment', [EnrollmentController::class, 'markUnpaid'])->name('enrollments.unpay');
        Route::patch('enrollment-quotas/{quota}', [EnrollmentController::class, 'adjustQuota'])->name('enrollments.quota');
    });
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');

    Route::middleware('role:admin,director')->group(function () {
        Route::get('enrollments/{enrollment}/proof', [EnrollmentController::class, 'proof'])->name('enrollments.proof');

        // Tutor dan katalog paket. Policy membedakan admin dan director.
        Route::get('tutors', [TutorController::class, 'index'])->name('tutors.index');
        Route::get('tutors/create', [TutorController::class, 'create'])->name('tutors.create');
        Route::post('tutors', [TutorController::class, 'store'])->name('tutors.store');
        Route::get('tutors/{tutor}', [TutorController::class, 'show'])->name('tutors.show');
        Route::get('tutors/{tutor}/edit', [TutorController::class, 'edit'])->name('tutors.edit');
        Route::put('tutors/{tutor}', [TutorController::class, 'update'])->name('tutors.update');

        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');

        // Inbox WhatsApp. Admin membaca dan membalas, director hanya membaca (WaConversationPolicy).
        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        Route::post('inbox/{conversation}/messages', [InboxController::class, 'reply'])->name('inbox.reply');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
