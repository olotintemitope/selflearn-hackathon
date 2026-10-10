<?php

namespace App\Services\Ocr;

interface OcrClient
{
    public function extract(string $bytes, string $mimeType): OcrResult;
}
