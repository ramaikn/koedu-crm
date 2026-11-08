<?php

namespace App\Http\Controllers;

use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Enrollment;
use App\Models\EnrollmentQuota;
use App\Models\Package;
use App\Models\Tutor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Paket yang dibeli client, pembayaran (tanpa DP/cicilan), dan pemakaian kuota. */
class EnrollmentController extends Controller
{
    public function store(Request $request, Client $client, CreateEnrollment $create): RedirectResponse
    {
        Gate::authorize('create', Enrollment::class);

        $data = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'primary_tutor_id' => ['nullable', 'integer', Rule::exists('tutors', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $create->handle(
            $client,
            Package::findOrFail($data['package_id']),
            CarbonImmutable::createFromFormat('Y-m-d', $data['starts_on'], config('app.timezone'))->startOfDay(),
            isset($data['primary_tutor_id']) ? Tutor::find($data['primary_tutor_id']) : null,
            $request->user(),
            $data['notes'] ?? null,
        );

        return back()->with('success', 'Paket ditambahkan untuk client.');
    }

    /** Ganti tutor pembimbing, catatan, atau status (batalkan/selesaikan paket). */
    public function update(Request $request, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $enrollment);

        $data = $request->validate([
            'primary_tutor_id' => ['nullable', 'integer', Rule::exists('tutors', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([EnrollmentStatus::Active->value, EnrollmentStatus::Completed->value, EnrollmentStatus::Cancelled->value])],
        ]);

        $enrollment->update(array_filter($data, fn ($v, $k) => $k !== 'status' || $v !== null, ARRAY_FILTER_USE_BOTH));

        return back()->with('success', 'Paket client diperbarui.');
    }

    /** Catat pembayaran lunas. Satu paket, satu pembayaran; tanpa cicilan. */
    public function recordPayment(Request $request, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('recordPayment', $enrollment);

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:50'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [
            'proof.mimes' => 'Bukti bayar harus berupa gambar (JPG/PNG) atau PDF.',
            'proof.max' => 'Ukuran bukti bayar maksimal 5 MB.',
        ]);

        $path = $enrollment->payment_proof_path;
        if ($request->hasFile('proof')) {
            // Disimpan di disk privat (tidak bisa dibuka lewat URL publik), diunduh lewat route bertoken sesi.
            $path = $request->file('proof')->store('payment-proofs');
            if ($enrollment->payment_proof_path) {
                Storage::delete($enrollment->payment_proof_path);
            }
        }

        $enrollment->update([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => CarbonImmutable::createFromFormat('Y-m-d', $data['paid_on'], config('app.timezone'))->setTime(12, 0),
            'payment_method' => $data['payment_method'],
            'payment_proof_path' => $path,
            'payment_recorded_by' => $request->user()->id,
        ]);

        Log::info('Pembayaran dicatat', ['enrollment_id' => $enrollment->id, 'by' => $request->user()->id]);

        return back()->with('success', 'Pembayaran dicatat.');
    }

    /** Koreksi bila pembayaran salah dicatat. */
    public function markUnpaid(Request $request, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('recordPayment', $enrollment);

        $enrollment->update([
            'payment_status' => PaymentStatus::Unpaid,
            'paid_at' => null,
            'payment_method' => null,
            'payment_recorded_by' => null,
        ]);
        Log::info('Pembayaran dibatalkan', ['enrollment_id' => $enrollment->id, 'by' => $request->user()->id]);

        return back()->with('success', 'Status pembayaran dikembalikan ke belum lunas.');
    }

    public function proof(Enrollment $enrollment): StreamedResponse
    {
        // Bukti bayar adalah data keuangan: hanya staf kantor, tidak termasuk tutor pembimbing.
        abort_unless(request()->user()->isStaff(), 403);
        abort_if($enrollment->payment_proof_path === null || ! Storage::exists($enrollment->payment_proof_path), 404);

        return Storage::response($enrollment->payment_proof_path);
    }

    /** Koreksi pemakaian kuota manual (mis. satu kali Turnitin dipakai). Tambah atau kurangi satu. */
    public function adjustQuota(Request $request, EnrollmentQuota $quota): RedirectResponse
    {
        Gate::authorize('update', $quota->enrollment);

        $delta = $request->validate(['delta' => ['required', 'integer', 'in:-1,1']])['delta'];
        $used = $quota->used + $delta;

        if ($used < 0 || ($quota->total !== null && $used > $quota->total)) {
            throw ValidationException::withMessages(['delta' => 'Pemakaian kuota di luar batas.']);
        }

        $quota->update(['used' => $used]);

        return back();
    }
}
