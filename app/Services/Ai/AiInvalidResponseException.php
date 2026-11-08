<?php

namespace App\Services\Ai;

/** Permintaan ditolak atau jawaban bukan JSON yang bisa dipakai. Berganti key tidak membantu. */
class AiInvalidResponseException extends AiException {}
