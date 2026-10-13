<?php

namespace App\Services\Ocr;

interface BatchOcrClient extends OcrClient
{
    public function pageCount(string $bytes, string $mimeType): int;

    public function extractBatch(string $bytes, string $mimeType, int $firstPage, int $lastPage): OcrResult;
}
