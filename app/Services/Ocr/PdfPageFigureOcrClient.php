<?php

namespace App\Services\Ocr;

interface PdfPageFigureOcrClient extends CloudOcrClient
{
    /**
     * @param  list<int>  $pageNumbers  1-based page numbers from the original PDF
     */
    public function extractPdfPages(string $bytes, array $pageNumbers): OcrResult;
}
