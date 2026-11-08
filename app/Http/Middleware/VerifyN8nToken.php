<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Melindungi endpoint yang dipanggil n8n. Token dikirim di header X-Koedu-Token. */
class VerifyN8nToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('koedu.n8n.api_token');
        $given = (string) $request->header('X-Koedu-Token');

        // Token kosong di .env berarti endpoint dimatikan, bukan terbuka untuk semua.
        abort_if($expected === '' || ! hash_equals($expected, $given), 401, 'Token tidak valid.');

        return $next($request);
    }
}
