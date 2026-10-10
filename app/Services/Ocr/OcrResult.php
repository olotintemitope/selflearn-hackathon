<?php

namespace App\Services\Ocr;

final readonly class OcrResult
{
    /** @param array<int, array{page:int|null, content:string}> $pages */
    public function __construct(
        public array $pages,
        public ?float $confidence = null,
        public ?string $providerRequestId = null,
    ) {}
}
