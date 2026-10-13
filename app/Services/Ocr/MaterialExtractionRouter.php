<?php

namespace App\Services\Ocr;

final class MaterialExtractionRouter implements BatchOcrClient
{
    private const MIN_READABLE_CHARACTERS = 20;

    private const MATHPIX_IMAGE_MAX_BASE64_BYTES = 1_999_000;

    private const PDF_MATHPIX_RENDER_DENSITIES = [220, 200, 180, 160];

    private ClockDiagramAnalyzer $clockDiagrams;

    public function __construct(
        private ProcessRunner $processes,
        private CloudOcrClient $cloud,
        ?ClockDiagramAnalyzer $clockDiagrams = null,
    ) {
        $this->clockDiagrams = $clockDiagrams ?? new ClockDiagramAnalyzer;
    }

    public function extract(string $bytes, string $mimeType): OcrResult
    {
        return $mimeType === 'application/pdf'
            ? $this->extractPdf($bytes)
            : $this->extractImage($bytes, $mimeType);
    }

    public function pageCount(string $bytes, string $mimeType): int
    {
        if ($mimeType !== 'application/pdf') {
            return 1;
        }

        $path = tempnam(sys_get_temp_dir(), 'selflearn-pdf-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new OcrException('Could not create a controlled temporary PDF for extraction.');
        }
        try {
            $info = $this->processes->run([
                config('services.extraction.pdfinfo_binary', 'pdfinfo'), $path,
            ], null, config('services.extraction.process_timeout', 30));
            if (! $info->successful || preg_match('/^Pages:\s+(\d+)$/mi', $info->output, $matches) !== 1) {
                throw new OcrException('Could not inspect PDF page count before extraction.');
            }
            $count = (int) $matches[1];
            if ($count > config('services.extraction.max_pdf_pages', 500)) {
                throw new OcrException('This PDF exceeds the configured page limit.');
            }

            return $count;
        } finally {
            @unlink($path);
        }
    }

    public function extractBatch(string $bytes, string $mimeType, int $firstPage, int $lastPage): OcrResult
    {
        if ($mimeType !== 'application/pdf') {
            if ($firstPage !== 1 || $lastPage !== 1) {
                throw new OcrException('Image extraction supports only page 1.');
            }

            return $this->extract($bytes, $mimeType);
        }
        if ($firstPage < 1 || $lastPage < $firstPage) {
            throw new OcrException('The requested PDF page batch is outside the document.');
        }

        return $this->extractPdf($bytes, $firstPage, $lastPage);
    }

    private function extractImage(string $bytes, string $mimeType): OcrResult
    {
        $cloud = $this->cloud->extract($bytes, $mimeType);
        $reason = 'Mathpix was used for image extraction to preserve STEM notation, handwriting, and visual content.';
        $pages = $this->decoratePages($cloud->pages, 'mathpix', [$reason]);
        $clockAnalysis = $this->clockDiagrams->analyze($bytes);
        if ($clockAnalysis !== null && isset($pages[0])) {
            $pages[0]['clock_diagrams'] = $clockAnalysis;
            $pages[0]['warnings'][] = 'A local clock reader found candidate times. Check or correct them against the original before including this page in your study guide.';
        }

        return new OcrResult(
            $pages,
            $cloud->confidence, $cloud->providerRequestId, 'mathpix', [$reason], $cloud->confidenceRate,
        );
    }

    private function extractPdf(string $bytes, ?int $firstPage = null, ?int $lastPage = null): OcrResult
    {
        $path = tempnam(sys_get_temp_dir(), 'selflearn-pdf-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new OcrException('Could not create a controlled temporary PDF for extraction.');
        }
        $renderDirectory = null;

        try {
            $info = $this->processes->run([
                config('services.extraction.pdfinfo_binary', 'pdfinfo'), $path,
            ], null, config('services.extraction.process_timeout', 30));
            $documentPageCount = $info->successful && preg_match('/^Pages:\s+(\d+)$/mi', $info->output, $matches) === 1
                ? (int) $matches[1]
                : 0;
            if ($documentPageCount > config('services.extraction.max_pdf_pages', 500)) {
                throw new OcrException('This PDF exceeds the configured page limit.');
            }
            if ($firstPage !== null && $lastPage !== null && $lastPage > $documentPageCount) {
                throw new OcrException('The requested PDF page batch is outside the document.');
            }
            if ($documentPageCount === 0) {
                throw new OcrException('Could not inspect PDF page count before extraction.');
            }

            $rangeStart = $firstPage ?? 1;
            $rangeEnd = $lastPage ?? $documentPageCount;
            $textCommand = [config('services.extraction.pdftotext_binary', 'pdftotext'), '-layout', '-enc', 'UTF-8'];
            if ($firstPage !== null && $lastPage !== null) {
                array_push($textCommand, '-f', (string) $firstPage, '-l', (string) $lastPage);
            }
            array_push($textCommand, $path, '-');
            $result = $this->processes->run($textCommand, null, config('services.extraction.process_timeout', 30));

            if (! $result->successful) {
                if ($firstPage !== null) {
                    throw new OcrException('Could not extract selectable text for the requested PDF page batch.');
                }

                return $this->cloudFallback($bytes, 'application/pdf', 'Local PDF text extraction failed; Mathpix was used.', $rangeStart, $rangeEnd);
            }

            $rawPages = preg_split('/\f/u', $result->output) ?: [];
            if (end($rawPages) === '') {
                array_pop($rawPages);
            }
            if ($firstPage === null && count($rawPages) > config('services.extraction.max_pdf_pages', 500)) {
                throw new OcrException('This PDF exceeds the configured page limit.');
            }
            if ($rawPages === [] && $firstPage === null) {
                return $this->cloudFallback($bytes, 'application/pdf', 'The PDF had no usable local text; Mathpix was used.', $rangeStart, $rangeEnd);
            }
            $expectedPages = $rangeEnd > 0 ? $rangeEnd - $rangeStart + 1 : count($rawPages);
            $rawPages = array_slice(array_pad($rawPages, $expectedPages, ''), 0, $expectedPages);
            $layoutSignals = $this->inspectPageImages($path, $rangeStart, $rangeStart + count($rawPages) - 1);
            $pagesToInspect = [];
            foreach ($rawPages as $index => $rawPage) {
                $pageNumber = $rangeStart + $index;
                $text = trim($rawPage);
                if ($this->isReadable($text)
                    && (isset($layoutSignals['full_page_scan'][$pageNumber]) || (! $this->looksStemLike($text) && ! isset($layoutSignals['visual'][$pageNumber])))) {
                    $pagesToInspect[] = $pageNumber;
                }
            }
            $visualRatios = [];
            if ($pagesToInspect !== []) {
                $renderDirectory = sys_get_temp_dir().'/selflearn-render-'.bin2hex(random_bytes(12));
                if (! mkdir($renderDirectory, 0700)) {
                    throw new OcrException('Could not create a controlled temporary directory for PDF inspection.');
                }
                $renderPrefix = $renderDirectory.'/page';
                $rendered = $this->processes->run([
                    config('services.extraction.pdftoppm_binary', 'pdftoppm'), '-r', '72', '-png',
                    '-f', (string) $rangeStart, '-l', (string) ($rangeStart + count($rawPages) - 1), $path, $renderPrefix,
                ], null, config('services.extraction.process_timeout', 30));
                $pageLayouts = $rendered->successful ? $this->extractPageWordBoxes($path, $rangeStart, $rangeStart + count($rawPages) - 1) : [];
                $rasterPaths = [];
                foreach ($pagesToInspect as $pageNumber) {
                    if (! $rendered->successful) {
                        $visualRatios[$pageNumber] = 1.0;

                        continue;
                    }
                    $rasterPaths[$pageNumber] = $renderPrefix.'-'.str_pad((string) $pageNumber, 2, '0', STR_PAD_LEFT).'.png';
                }
                $visualRatios += $this->vectorVisualInkRatios($pageLayouts, $rasterPaths);
            }
            $fullScanThreshold = $this->fullPageScanVisualThreshold($visualRatios, $layoutSignals['full_page_scan']);
            $pages = [];
            $warnings = [];
            $mathpixPageWarnings = [];
            foreach ($rawPages as $index => $rawPage) {
                $pageNumber = $rangeStart + $index;
                $text = trim($rawPage);
                $stemSignal = $this->looksStemLike($text);
                $visualSignal = isset($layoutSignals['visual'][$pageNumber])
                    || (isset($visualRatios[$pageNumber]) && (isset($layoutSignals['full_page_scan'][$pageNumber])
                        ? ($visualRatios[$pageNumber] >= 0.999 || $visualRatios[$pageNumber] >= $fullScanThreshold)
                        : $visualRatios[$pageNumber] >= config('services.extraction.vector_visual_ink_ratio', 0.0025)));
                if ($this->isReadable($text) && ! $stemSignal && ! $visualSignal) {
                    $pages[] = ['page' => $pageNumber, 'content' => $text, 'extractor' => 'pdftotext', 'warnings' => []];

                    continue;
                }

                $warning = match (true) {
                    $stemSignal => 'Local page text contains STEM cues; Mathpix was used for notation-aware extraction.',
                    $visualSignal => 'Page structure contains meaningful non-text visual content; Mathpix was used to capture it.',
                    default => null,
                };
                $mathpixPageWarnings[$pageNumber] = $warning;
            }

            if ($mathpixPageWarnings !== [] && $this->cloud instanceof PdfPageFigureOcrClient) {
                $mathpix = $this->cloud->extractPdfPages($bytes, array_keys($mathpixPageWarnings));
                $returnedPages = array_column($mathpix->pages, 'page');
                sort($returnedPages);
                $expectedPages = array_keys($mathpixPageWarnings);
                sort($expectedPages);
                if ($returnedPages !== $expectedPages) {
                    throw new OcrException('Mathpix did not return every selected source page.');
                }
                foreach ($mathpix->pages as $page) {
                    $pageNumber = $page['page'];
                    $page['warnings'] = array_values(array_unique(array_merge(
                        array_filter([$mathpixPageWarnings[$pageNumber] ?? null]),
                        $page['warnings'] ?? [],
                    )));
                    $pages[] = $page;
                    $warnings = array_merge($warnings, $page['warnings']);
                }
            } else {
                foreach ($mathpixPageWarnings as $pageNumber => $warning) {
                    $page = $this->extractPdfPage($path, $pageNumber, $warning);
                    $pages[] = $page;
                    $warnings = array_merge($warnings, $page['warnings']);
                }
            }

            usort($pages, fn (array $first, array $second) => ($first['page'] ?? 0) <=> ($second['page'] ?? 0));

            $extractors = array_values(array_unique(array_column($pages, 'extractor')));

            return new OcrResult(
                $pages, null, null, count($extractors) > 1 ? 'mixed' : $extractors[0],
                array_values(array_unique($warnings)),
            );
        } finally {
            @unlink($path);
            if ($renderDirectory !== null) {
                foreach (glob($renderDirectory.'/*') ?: [] as $renderedPage) {
                    if (is_file($renderedPage)) {
                        @unlink($renderedPage);
                    }
                }
                @rmdir($renderDirectory);
            }
        }
    }

    /** @return array{page:int, content:string, extractor:string, warnings:list<string>} */
    private function extractPdfPage(string $pdfPath, int $pageNumber, ?string $warning = null): array
    {
        $imageBytes = $this->renderPdfPageForMathpix($pdfPath, $pageNumber);
        $cloud = $this->cloud->extract($imageBytes, 'image/png');
        $content = trim((string) ($cloud->pages[0]['content'] ?? ''));
        if ($content === '') {
            throw new OcrException('Mathpix returned no readable text for PDF page '.$pageNumber.'.');
        }
        $clockAnalysis = $this->clockDiagrams->analyze($imageBytes);
        $warnings = [$warning ?? 'Page '.$pageNumber.' was rasterized and processed by Mathpix for STEM-aware extraction.'];
        if ($clockAnalysis !== null) {
            $warnings[] = 'A local clock reader found candidate times. Check or correct them against the original before including this page in your study guide.';
        }

        $page = [
            'page' => $pageNumber, 'content' => $content, 'extractor' => 'mathpix',
            'warnings' => $warnings,
            'provider_request_id' => $cloud->providerRequestId,
            'confidence' => $cloud->confidence,
            'confidence_rate' => $cloud->confidenceRate,
        ];
        if ($clockAnalysis !== null) {
            $page['clock_diagrams'] = $clockAnalysis;
        }

        return $page;
    }

    private function renderPdfPageForMathpix(string $pdfPath, int $pageNumber): string
    {
        foreach (self::PDF_MATHPIX_RENDER_DENSITIES as $density) {
            $image = $this->processes->run([
                config('services.extraction.magick_binary', 'magick'), '-density', (string) $density,
                $pdfPath.'['.($pageNumber - 1).']', '-background', 'white', '-alpha', 'remove',
                '-colorspace', 'Gray', '-depth', '8', '-define', 'png:compression-level=9', 'png:-',
            ], null, config('services.extraction.process_timeout', 30));

            if (! $image->successful || $image->output === '') {
                throw new OcrException('Could not rasterize PDF page '.$pageNumber.' for image processing.');
            }

            if ($this->fitsMathpixImageLimit($image->output)) {
                return $image->output;
            }
        }

        throw new OcrException('Could not prepare PDF page '.$pageNumber.' within the supported image size.');
    }

    private function fitsMathpixImageLimit(string $imageBytes): bool
    {
        $dataUriPrefixBytes = strlen('data:image/png;base64,');
        $base64Bytes = 4 * intdiv(strlen($imageBytes) + 2, 3);

        return $dataUriPrefixBytes + $base64Bytes <= self::MATHPIX_IMAGE_MAX_BASE64_BYTES;
    }

    private function cloudFallback(string $bytes, string $mimeType, string $warning, ?int $firstPage = null, ?int $lastPage = null): OcrResult
    {
        $cloud = $this->cloud->extract($bytes, $mimeType);
        $pages = $this->decoratePages($cloud->pages, 'mathpix', [$warning]);

        if ($mimeType === 'application/pdf') {
            foreach ($pages as &$page) {
                $pageNumber = $page['page'] ?? null;
                if (! is_int($pageNumber) || ($firstPage !== null && $pageNumber < $firstPage)
                    || ($lastPage !== null && $pageNumber > $lastPage)) {
                    continue;
                }
                $image = $this->renderPdfPageForClockAnalysis($bytes, $pageNumber);
                $clockAnalysis = $image === null ? null : $this->clockDiagrams->analyze($image);
                if ($clockAnalysis !== null) {
                    $page['clock_diagrams'] = $clockAnalysis;
                    $page['warnings'][] = 'A local clock reader found candidate times. Check or correct them against the original before including this page in your study guide.';
                }
            }
            unset($page);
        }

        return new OcrResult(
            $pages,
            $cloud->confidence, $cloud->providerRequestId, 'mathpix', [$warning], $cloud->confidenceRate,
        );
    }

    private function renderPdfPageForClockAnalysis(string $bytes, int $pageNumber): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'selflearn-clock-pdf-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            return null;
        }
        try {
            $image = $this->processes->run([
                config('services.extraction.magick_binary', 'magick'), '-density', '120',
                $path.'['.($pageNumber - 1).']', '-background', 'white', '-alpha', 'remove', 'png:-',
            ], null, config('services.extraction.process_timeout', 30));

            return $image->successful && $image->output !== '' ? $image->output : null;
        } finally {
            @unlink($path);
        }
    }

    private function isReadable(string $text): bool
    {
        $plain = preg_replace('/\s+/u', '', $text) ?? '';
        $readable = preg_replace('/[^\pL\pN]/u', '', $plain) ?? '';
        preg_match_all('/\b\pL{2,}\b/u', $text, $words);

        return mb_strlen($plain) >= self::MIN_READABLE_CHARACTERS
            && count($words[0]) >= 3
            && mb_strlen($readable) / max(1, mb_strlen($plain)) >= 0.55
            && ! str_contains($text, "\u{FFFD}");
    }

    private function looksStemLike(string $text): bool
    {
        $mathNotation = preg_match('/(?:[=∫∑√±×÷^]|\\\\(?:frac|sum|int|sqrt)|\b[a-z]\s*[+\-*\/]\s*\d)/iu', $text) === 1;
        $explicitConcept = preg_match('/\b(?:clock|equation|formula|calculate|calculation|physics|chemistry|chemical|molecule|atom|biology|algebra|geometry|trigonometry|voltage|circuit|quantitative|statistics|statistical|experiment|velocity|acceleration|photosynthesis|reaction|organism|cell)\b/iu', $text) === 1;
        $technicalPair = preg_match('/\b(?:minute|hour)\s+hand\b|\bconservation\s+of\s+energy\b|\b(?:conservation|kinetic|potential)\s+energy\b|\belectric\s+current\b|\b(?:mass|force)\s+(?:and\s+)?(?:acceleration|velocity)\b|\b(?:density|volume)\s+of\s+(?:a\s+)?mass\b|\bforce\s+of\s+gravity\b/iu', $text) === 1;
        $otherTechnicalCue = preg_match('/\b(?:graph|angle|fraction|ratio)\b/iu', $text) === 1;

        return $mathNotation || $explicitConcept || $technicalPair || $otherTechnicalCue;
    }

    /** @return array{visual:array<int, true>,full_page_scan:array<int, true>} */
    private function inspectPageImages(string $path, int $firstPage, int $lastPage): array
    {
        $pageInfo = $this->processes->run([
            config('services.extraction.pdfinfo_binary', 'pdfinfo'), '-box',
            '-f', (string) $firstPage, '-l', (string) $lastPage, $path,
        ], null, config('services.extraction.process_timeout', 30));
        if (! $pageInfo->successful) {
            throw new OcrException('Could not inspect PDF page layout to select a safe extractor.');
        }

        $imageOutput = '';
        $batchSize = max(1, (int) config('services.extraction.pdfimages_page_batch', 10));
        for ($batchFirstPage = $firstPage; $batchFirstPage <= $lastPage; $batchFirstPage += $batchSize) {
            $batchLastPage = min($lastPage, $batchFirstPage + $batchSize - 1);
            $imageInfo = $this->processes->run([
                config('services.extraction.pdfimages_binary', 'pdfimages'), '-list',
                '-f', (string) $batchFirstPage, '-l', (string) $batchLastPage, $path,
            ], null, config('services.extraction.process_timeout', 30));
            if (! $imageInfo->successful) {
                throw new OcrException('Could not inspect PDF page layout to select a safe extractor.');
            }
            $imageOutput .= $imageInfo->output;
        }

        $pageSizes = [];
        preg_match_all('/^Page\s+(\d+)\s+size:\s*([\d.]+)\s+x\s+([\d.]+)\s+pts\s*$/mi', $pageInfo->output, $sizeMatches, PREG_SET_ORDER);
        foreach ($sizeMatches as $match) {
            $pageSizes[(int) $match[1]] = [(float) $match[2], (float) $match[3]];
        }

        $visualPages = [];
        $fullPageScans = [];
        foreach (preg_split('/\R/u', $imageOutput) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+\d+\s+image\b/', $line, $imageRow) !== 1) {
                continue;
            }
            if (preg_match('/^\s*(\d+)\s+\d+\s+image\s+(\d+)\s+(\d+)\s+\S+\s+\d+\s+\d+\s+\S+\s+\S+\s+\S+\s+\S+\s+([\d.]+)\s+([\d.]+)/', $line, $match) !== 1) {
                $visualPages[(int) $imageRow[1]] = true;

                continue;
            }
            if (! isset($pageSizes[(int) $match[1]])) {
                continue;
            }

            [$pageWidth, $pageHeight] = $pageSizes[(int) $match[1]];
            $imageWidth = (float) $match[2];
            $imageHeight = (float) $match[3];
            $xPpi = (float) $match[4];
            $yPpi = (float) $match[5];
            if ($xPpi <= 0 || $yPpi <= 0 || $pageWidth <= 0 || $pageHeight <= 0) {
                $visualPages[(int) $match[1]] = true;

                continue;
            }

            $coverage = (($imageWidth / $xPpi * 72) * ($imageHeight / $yPpi * 72)) / ($pageWidth * $pageHeight);
            $sameShapeAsPage = abs(($imageWidth / $imageHeight) - ($pageWidth / $pageHeight)) < 0.025;
            // Full-page scan backgrounds are not treated as figures when a readable OCR text layer exists.
            if ($coverage >= 0.55 && $sameShapeAsPage) {
                $fullPageScans[(int) $match[1]] = true;

                continue;
            }
            if ($coverage >= 0.04) {
                $visualPages[(int) $match[1]] = true;
            }
        }

        return ['visual' => $visualPages, 'full_page_scan' => $fullPageScans];
    }

    /** @return array<int, array{width:float,height:float,words:list<array{0:string,1:string,2:string,3:string,4:string}>}> */
    private function extractPageWordBoxes(string $pdfPath, int $firstPage, int $lastPage): array
    {
        $bbox = $this->processes->run([
            config('services.extraction.pdftotext_binary', 'pdftotext'), '-bbox-layout',
            '-enc', 'UTF-8', '-f', (string) $firstPage, '-l', (string) $lastPage, $pdfPath, '-',
        ], null, config('services.extraction.process_timeout', 30));
        if (! $bbox->successful) {
            return [];
        }

        preg_match_all('/<page\s+width="([\d.]+)"\s+height="([\d.]+)"[^>]*>(.*?)<\/page>/is', $bbox->output, $pageMatches, PREG_SET_ORDER);
        $layouts = [];
        foreach ($pageMatches as $index => $pageMatch) {
            preg_match_all('/<word\s+xMin="([\d.]+)"\s+yMin="([\d.]+)"\s+xMax="([\d.]+)"\s+yMax="([\d.]+)"/i', $pageMatch[3], $wordMatches, PREG_SET_ORDER);
            $layouts[$firstPage + $index] = [
                'width' => (float) $pageMatch[1],
                'height' => (float) $pageMatch[2],
                'words' => $wordMatches,
            ];
        }

        return $layouts;
    }

    /**
     * @param  array<int, array{width:float,height:float,words:list<array{0:string,1:string,2:string,3:string,4:string}>}>  $pageLayouts
     * @param  array<int, string>  $rasterPaths
     * @return array<int, float>
     */
    private function vectorVisualInkRatios(array $pageLayouts, array $rasterPaths): array
    {
        $visualRatios = [];
        $pageNumbers = [];
        $command = [config('services.extraction.magick_binary', 'magick')];
        foreach ($rasterPaths as $pageNumber => $rasterPath) {
            $pageLayout = $pageLayouts[$pageNumber] ?? null;
            if ($pageLayout === null || $pageLayout['width'] <= 0 || $pageLayout['height'] <= 0 || $pageLayout['words'] === []) {
                $visualRatios[$pageNumber] = 1.0;

                continue;
            }

            $rectangles = [];
            foreach ($pageLayout['words'] as $word) {
                $left = max(0, (float) $word[1] - 2.5);
                $top = max(0, (float) $word[2] - 2.5);
                $right = min($pageLayout['width'], (float) $word[3] + 2.5);
                $bottom = min($pageLayout['height'], (float) $word[4] + 2.5);
                $rectangles[] = sprintf('rectangle %.2f,%.2f %.2f,%.2f', $left, $top, $right, $bottom);
            }

            array_push($command, '(', $rasterPath, '-background', 'white', '-alpha', 'remove',
                '-colorspace', 'gray', '-threshold', '82%', '-fill', 'white', '-stroke', 'none',
                '-draw', implode(' ', $rectangles), ')');
            $pageNumbers[] = $pageNumber;
        }
        if ($pageNumbers === []) {
            return $visualRatios;
        }

        array_push($command, '-format', "%[fx:1-mean]\n", 'info:');
        $residual = $this->processes->run($command, null, config('services.extraction.process_timeout', 30));
        preg_match_all('/(?:^|\R)(0(?:\.\d+)?|1(?:\.0+)?)(?=\R|$)/', trim($residual->output), $matches);
        if (! $residual->successful || count($matches[1]) !== count($pageNumbers)) {
            foreach ($pageNumbers as $pageNumber) {
                $visualRatios[$pageNumber] = 1.0;
            }

            return $visualRatios;
        }

        foreach ($pageNumbers as $index => $pageNumber) {
            $visualRatios[$pageNumber] = (float) $matches[1][$index];
        }

        return $visualRatios;
    }

    /** @param array<int, float> $visualRatios @param array<int, true> $scanPages */
    private function fullPageScanVisualThreshold(array $visualRatios, array $scanPages): float
    {
        $scanRatios = [];
        foreach (array_keys($scanPages) as $pageNumber) {
            if (isset($visualRatios[$pageNumber])) {
                $scanRatios[] = $visualRatios[$pageNumber];
            }
        }
        if ($scanRatios === []) {
            return INF;
        }
        if (count($scanRatios) < 3) {
            return 0.02;
        }

        sort($scanRatios);
        $middle = intdiv(count($scanRatios), 2);
        $median = count($scanRatios) % 2 === 0
            ? ($scanRatios[$middle - 1] + $scanRatios[$middle]) / 2
            : $scanRatios[$middle];
        $deviations = array_map(fn (float $ratio) => abs($ratio - $median), $scanRatios);
        sort($deviations);
        $mad = count($deviations) % 2 === 0
            ? ($deviations[$middle - 1] + $deviations[$middle]) / 2
            : $deviations[$middle];

        return $median + max(0.003, 3 * $mad);
    }

    /** @return array<int, array{page:int|null, content:string, extractor:string, warnings:list<string>, provider_request_id?:string|null, confidence?:float|null, confidence_rate?:float|null, clock_diagrams?:array<string,mixed>}> */
    private function decoratePages(array $pages, string $extractor, array $warnings): array
    {
        return array_map(fn (array $page) => [
            'page' => $page['page'], 'content' => $page['content'],
            'extractor' => $page['extractor'] ?? $extractor,
            'warnings' => array_values(array_unique(array_merge($page['warnings'] ?? [], $warnings))),
            'provider_request_id' => $page['provider_request_id'] ?? null,
            'confidence' => $page['confidence'] ?? null,
            'confidence_rate' => $page['confidence_rate'] ?? null,
            ...(isset($page['clock_diagrams']) ? ['clock_diagrams' => $page['clock_diagrams']] : []),
        ], $pages);
    }
}
