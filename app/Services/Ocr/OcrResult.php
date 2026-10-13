<?php

namespace App\Services\Ocr;

final readonly class OcrResult
{
    /**
     * @param  array<int, array{page:int|null, content:string, extractor?:string, warnings?:list<string>, provider_request_id?:string|null, confidence?:float|null, confidence_rate?:float|null, clock_diagrams?:array<string,mixed>, figure_assets?:list<array{bytes:string,mime_type:string,alt:string,source_url?:string}>}>  $pages
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $pages,
        public ?float $confidence = null,
        public ?string $providerRequestId = null,
        public string $extractor = 'unknown',
        public array $warnings = [],
        public ?float $confidenceRate = null,
    ) {}
}
