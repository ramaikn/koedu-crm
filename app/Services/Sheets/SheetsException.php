<?php

namespace App\Services\Sheets;

use RuntimeException;

/** Dasar semua kegagalan Google Sheets. Pesannya aman dicatat di log (tanpa kunci atau isi data). */
class SheetsException extends RuntimeException {}
