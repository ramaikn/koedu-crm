<?php

namespace App\Services\Ai;

use RuntimeException;

/** Dasar semua kegagalan AI. Pesannya aman dicatat di log (tidak memuat API key). */
class AiException extends RuntimeException {}
