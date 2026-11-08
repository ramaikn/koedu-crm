<?php

namespace App\Services\Ai;

final readonly class AiResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public string $provider,
        public string $model,
    ) {}
}
