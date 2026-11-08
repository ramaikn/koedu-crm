<?php

use App\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Aplikasi internal: tidak ada halaman publik, langsung ke dashboard (atau login).
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Inbox WhatsApp: hanya staf kantor. Aturan baca/balas ada di WaConversationPolicy.
    Route::middleware('role:admin,director')->group(function () {
        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        Route::post('inbox/{conversation}/messages', [InboxController::class, 'reply'])->name('inbox.reply');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
