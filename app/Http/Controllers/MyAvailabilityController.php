<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Models\Tutor;
use App\Models\TutorAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Jam tersedia" untuk tutor yang sedang login. Tutor hanya mengubah jamnya sendiri;
 * profil lain (bidang, jenjang, batas sesi) tetap diatur admin lewat TutorController.
 */
class MyAvailabilityController extends Controller
{
    public function edit(Request $request): Response
    {
        $tutor = $request->user()->tutor;
        if ($tutor) {
            Gate::authorize('updateAvailability', $tutor);
            $tutor->load('availabilities');
        }

        return Inertia::render('my-availability', [
            // null bila akun belum ditautkan ke profil tutor: halaman menampilkan pesan untuk menghubungi admin.
            'availabilities' => $tutor?->availabilitiesForForm(),
            'days' => TutorAvailability::dayOptions(),
        ]);
    }

    public function update(AvailabilityRequest $request): RedirectResponse
    {
        /** @var Tutor|null $tutor */
        $tutor = $request->user()->tutor;
        abort_if($tutor === null, 404);
        Gate::authorize('updateAvailability', $tutor);

        DB::transaction(fn () => $tutor->replaceAvailabilities($request->validated('availabilities')));
        Log::info('Tutor mengubah jam tersedia', ['tutor_id' => $tutor->id, 'slots' => count($request->validated('availabilities'))]);

        return to_route('my-availability.edit')->with('success', 'Jam tersedia disimpan.');
    }
}
