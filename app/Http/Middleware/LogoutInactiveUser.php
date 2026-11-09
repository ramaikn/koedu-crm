<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang dinonaktifkan director langsung dikeluarkan pada permintaan berikutnya,
 * termasuk yang masih login dari sesi lama atau "ingat saya". Login baru sudah ditolak di LoginRequest.
 */
class LogoutInactiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Log::info('Sesi akun nonaktif diakhiri', ['user_id' => $user->id]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Akun Anda sudah tidak aktif. Hubungi director bila ini keliru.');
        }

        return $next($request);
    }
}
