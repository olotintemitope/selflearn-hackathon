<?php

namespace App\Services\Ocr;

final readonly class ProcessResult
{
    public function __construct(
        public bool $successful,
        public string $output,
        public string $errorOutput = '',
    ) {}
}
