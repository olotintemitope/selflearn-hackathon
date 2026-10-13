<?php

namespace App\Jobs;

use App\Models\Material;
use App\Services\Learning\ConceptExtractor;
use App\Services\Learning\ConfirmedClockGuideFallback;
use App\Services\Learning\LocalMaterialRelevanceClassifier;
use App\Services\Learning\MaterialRelevanceClassifier;
use App\Services\Learning\MaterialRelevanceException;
use App\Services\Learning\OpenAiConceptExtractor;
use App\Services\Learning\PastPaperQuestionIndexer;
use App\Services\Ocr\BatchOcrClient;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessMaterial implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Keep a property-level default so queued payloads created before this
     * option was added remain safe to deserialize and execute.
     */
    public bool $reuseClassifiedChunks = false;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $materialId, bool $reuseClassifiedChunks = false)
    {
        $this->reuseClassifiedChunks = $reuseClassifiedChunks;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('material:'.$this->materialId))->expireAfter(180)];
    }

    /**
     * Execute the job.
     */
    public function handle(OcrClient $ocr, ConceptExtractor $extractor, MaterialRelevanceClassifier $relevance, ?PastPaperQuestionIndexer $pastPaperQuestionIndexer = null): void
    {
        $material = Material::findOrFail($this->materialId);
        if ($material->status === 'ready' && ! $this->reuseClassifiedChunks) {
            return;
        }

        $material->update(['status' => 'processing', 'failure_message' => null]);
        $ocrResult = null;
        $version = $material->extraction_version;
        $batchedPdf = false;
        $metadata = $material->metadata ?? [];
        $progress = $metadata['extraction_progress'] ?? null;
        $savedExtraction = ! $this->reuseClassifiedChunks
            && (($metadata['processing_stage'] ?? null) === 'concepts'
                || (is_array($progress)
                    && (int) ($progress['next_page'] ?? 0) > (int) ($progress['total_pages'] ?? PHP_INT_MAX)));
        if (! $this->reuseClassifiedChunks && ! $savedExtraction) {
            $metadata['processing_stage'] = 'extraction';
            unset($metadata['failed_stage']);
            $material->update(['metadata' => $metadata]);
        }
        if (! $this->reuseClassifiedChunks && ! $savedExtraction) {
            $bytes = Storage::disk('local')->get($material->private_source_path);
            if ($material->mime_type === 'application/pdf' && $ocr instanceof BatchOcrClient) {
                $progress = $material->metadata['extraction_progress'] ?? null;
                if (! is_array($progress)) {
                    $totalPages = $ocr->pageCount($bytes, $material->mime_type);
                    if ($totalPages < 1) {
                        throw new MaterialRelevanceException('No readable pages were found in this material.');
                    }
                    $progress = ['version' => $material->extraction_version + 1, 'next_page' => 1, 'total_pages' => $totalPages];
                    $metadata = $material->metadata ?? [];
                    $metadata['extraction_progress'] = $progress;
                    $material->update(['metadata' => $metadata]);
                }

                $version = (int) $progress['version'];
                $firstPage = (int) $progress['next_page'];
                $totalPages = (int) $progress['total_pages'];
                $batchSize = min(10, max(1, (int) config('services.extraction.page_batch_size', 5)));
                if ($firstPage <= $totalPages) {
                    $lastPage = min($totalPages, $firstPage + $batchSize - 1);
                    $batchResult = $ocr->extractBatch($bytes, $material->mime_type, $firstPage, $lastPage);
                    if ($batchResult->pages === []) {
                        throw new MaterialRelevanceException('No readable pages were extracted from this material batch.');
                    }
                    $returnedPages = array_column($batchResult->pages, 'page');
                    $expectedPages = range($firstPage, $lastPage);
                    sort($returnedPages);
                    if ($returnedPages !== $expectedPages) {
                        throw new MaterialRelevanceException('The extractor did not return exactly the requested PDF pages.');
                    }
                    foreach ($batchResult->pages as $index => $page) {
                        $pageNumber = $page['page'] ?? ($firstPage + $index);
                        if (! is_int($pageNumber) || $pageNumber < $firstPage || $pageNumber > $lastPage) {
                            throw new MaterialRelevanceException('The extractor returned a page outside the requested batch.');
                        }
                        $page = $this->applyClockGoalContext($page, $material);
                        $material->chunks()->updateOrCreate([
                            'extraction_version' => $version, 'page' => $pageNumber,
                        ], [
                            'content' => $page['content'], 'section' => null,
                            'locator' => [
                                'page' => $pageNumber,
                                'extractor' => $page['extractor'] ?? $batchResult->extractor,
                                'warnings' => $page['warnings'] ?? [],
                                'provider_request_id' => $page['provider_request_id'] ?? null,
                                'confidence' => $page['confidence'] ?? null,
                                'confidence_rate' => $page['confidence_rate'] ?? null,
                                'clock_diagrams' => $page['clock_diagrams'] ?? null,
                            ],
                            'relevance_status' => 'pending', 'relevance_confidence' => null, 'relevance_reason' => null,
                        ]);
                    }
                    $progress['next_page'] = $lastPage + 1;
                    $metadata = $material->metadata ?? [];
                    $metadata['extraction_progress'] = $progress;
                    $material->update(['metadata' => $metadata]);
                    if ($lastPage < $totalPages) {
                        self::dispatch($material->id)->onQueue('materials');

                        return;
                    }
                }

                $batchedPdf = true;
                $storedPages = $material->chunks()->where('extraction_version', $version)->orderBy('page')->get();
                $pageData = $storedPages->map(fn ($chunk) => [
                    'page' => $chunk->page, 'content' => $chunk->content,
                    'extractor' => $chunk->locator['extractor'] ?? 'unknown',
                    'warnings' => $chunk->locator['warnings'] ?? [],
                    'provider_request_id' => $chunk->locator['provider_request_id'] ?? null,
                    'confidence' => $chunk->locator['confidence'] ?? null,
                    'confidence_rate' => $chunk->locator['confidence_rate'] ?? null,
                    'clock_diagrams' => $chunk->locator['clock_diagrams'] ?? null,
                ])->all();
                if ($pageData === []) {
                    throw new MaterialRelevanceException('No readable pages were extracted from this material.');
                }
                $pageExtractors = array_values(array_unique(array_column($pageData, 'extractor')));
                $warnings = [];
                foreach ($pageData as $page) {
                    $warnings = array_merge($warnings, $page['warnings']);
                }
                $ocrResult = new OcrResult($pageData, null, null, count($pageExtractors) > 1 ? 'mixed' : $pageExtractors[0], array_values(array_unique($warnings)));
            } else {
                $ocrResult = $ocr->extract($bytes, $material->mime_type);
                if ($ocrResult->pages === []) {
                    throw new MaterialRelevanceException('No readable pages were extracted from this material.');
                }
                $version++;
            }

            // Store OCR results before classification or concept generation. If a
            // provider is unavailable, retries continue at this stage without OCR.
            DB::transaction(function () use ($material, $ocrResult, $version, $batchedPdf): void {
                if ($batchedPdf) {
                    $material->chunks()->where('extraction_version', '!=', $version)->delete();
                } else {
                    $material->chunks()->delete();
                    foreach ($ocrResult->pages as $page) {
                        $page = $this->applyClockGoalContext($page, $material);
                        $material->chunks()->create([
                            'extraction_version' => $version,
                            'content' => $page['content'],
                            'page' => $page['page'],
                            'section' => null,
                            'locator' => [
                                'page' => $page['page'],
                                'extractor' => $page['extractor'] ?? $ocrResult->extractor,
                                'warnings' => $page['warnings'] ?? [],
                                'provider_request_id' => $page['provider_request_id'] ?? null,
                                'confidence' => $page['confidence'] ?? null,
                                'confidence_rate' => $page['confidence_rate'] ?? $ocrResult->confidenceRate,
                                'clock_diagrams' => $page['clock_diagrams'] ?? null,
                            ],
                        ]);
                    }
                }
                $metadata = $material->metadata ?? [];
                $metadata['processing_stage'] = 'relevance';
                if ($ocrResult) {
                    $metadata = array_merge($metadata, [
                        'ocr_confidence' => $ocrResult->confidence,
                        'ocr_confidence_rate' => $ocrResult->confidenceRate,
                        'ocr_request_id' => $ocrResult->providerRequestId,
                        'extractor' => $ocrResult->extractor,
                        'extraction_warnings' => $ocrResult->warnings,
                        'concept_prompt_version' => OpenAiConceptExtractor::PROMPT_VERSION,
                    ]);
                }
                $material->update(['extraction_version' => $version, 'metadata' => $metadata]);
            });
        } elseif ($savedExtraction) {
            $version = is_array($progress) ? (int) $progress['version'] : $material->extraction_version;
            $batchedPdf = is_array($progress);
        }

        $pending = $material->chunks()->where('relevance_status', 'pending')->orderBy('id')->get();
        $pending = $this->flagLowConfidenceMathpixPages($pending, $material);
        $pending = $this->holdClockDiagramPages($pending, $material);
        $metadata = $material->metadata ?? [];
        $metadata['processing_stage'] = $pending->isNotEmpty() ? 'relevance' : 'concepts';
        $material->update(['metadata' => $metadata]);
        if ($pending->isNotEmpty()) {
            $context = $this->relevanceConceptContext($material, $relevance);
            $chunks = $pending->map(fn ($chunk) => ['id' => $chunk->id, 'content' => $chunk->content, 'page' => $chunk->page])->all();
            $space = $material->learningSpace;
            $earlierMaterialIsProcessing = $space->materials()
                ->whereIn('materials.status', ['pending', 'processing'])
                ->where(function ($query) use ($material): void {
                    $query->where('materials.created_at', '<', $material->created_at)
                        ->orWhere(function ($tie) use ($material): void {
                            $tie->where('materials.created_at', $material->created_at)
                                ->where('materials.id', '<', $material->id);
                        });
                })
                ->exists();

            // When another upload arrived first but has not established a
            // source-backed topic yet, keep this one reviewable. This avoids
            // concurrent first uploads both becoming the anchor by accident.
            if ($relevance instanceof LocalMaterialRelevanceClassifier
                && $relevance->hasBroadGoal($space)
                && $context === []
                && $earlierMaterialIsProcessing) {
                $decisions = array_map(fn (array $chunk) => [
                    'chunk_id' => $chunk['id'],
                    'status' => 'possibly_related',
                    'confidence' => 0.5,
                    'reason' => 'The first material is still being prepared, so this page is waiting for a topic match.',
                ], $chunks);
            } else {
                $decisions = $relevance->classify($space, $chunks, $context);
            }
            DB::transaction(function () use ($pending, $decisions): void {
                foreach ($decisions as $decision) {
                    $pending->firstWhere('id', $decision['chunk_id'])->update([
                        'relevance_status' => $decision['status'],
                        'relevance_confidence' => $decision['confidence'],
                        'relevance_reason' => $decision['reason'],
                    ]);
                }
            });
        }

        $metadata = $material->fresh()->metadata ?? [];
        $metadata['processing_stage'] = 'concepts';
        $material->update(['metadata' => $metadata]);

        $eligible = $material->chunks()->where('relevance_status', 'related')->orderBy('id')->get();
        $graph = $eligible->isEmpty() ? ['concepts' => [], 'dependencies' => []] : $extractor->extract($eligible->map(fn ($chunk) => [
            'id' => $chunk->id, 'content' => $chunk->content, 'page' => $chunk->page,
        ])->all());
        if ($graph['concepts'] === []) {
            $clockFallback = (new ConfirmedClockGuideFallback)->build($eligible->map(fn ($chunk) => [
                'id' => $chunk->id, 'locator' => $chunk->locator ?? [],
            ])->all());
            if ($clockFallback !== null) {
                $graph = $clockFallback;
            }
        }

        DB::transaction(function () use ($material, $graph, $version): void {
            $conceptMap = [];
            foreach ($graph['concepts'] as $item) {
                $concept = $material->learningSpace->concepts()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($item['name'])])->first();
                if (! $concept) {
                    $concept = $material->learningSpace->concepts()->create([
                        'name' => $item['name'], 'description' => $item['description'],
                    ]);
                }
                $concept->sources()->syncWithoutDetaching($item['source_chunk_ids']);
                $conceptMap[$item['id']] = $concept;
            }
            foreach ($graph['dependencies'] as $dependency) {
                $conceptMap[$dependency['concept_id']]->prerequisites()->syncWithoutDetaching([
                    $conceptMap[$dependency['prerequisite_id']]->id => ['source_supported' => $dependency['source_supported']],
                ]);
            }

            $metadata = $material->metadata ?? [];
            unset($metadata['extraction_progress']);
            unset($metadata['processing_stage']);
            $metadata = array_merge($metadata, [
                'relevance_classifier_version' => LocalMaterialRelevanceClassifier::VERSION,
                'related_page_count' => $material->chunks()->where('relevance_status', 'related')->count(),
                'possibly_related_page_count' => $material->chunks()->where('relevance_status', 'possibly_related')->count(),
                'separate_page_count' => $material->chunks()->where('relevance_status', 'separate')->count(),
            ]);
            $material->update([
                'status' => 'ready', 'extraction_version' => $version,
                'metadata' => $metadata,
            ]);
        });
        if ($material->role === 'past_paper') {
            ($pastPaperQuestionIndexer ?? app(PastPaperQuestionIndexer::class))->index($material->fresh());
        }
    }

    /**
     * Broad study spaces need one stable, source-grounded topic anchor. Later
     * concepts from unrelated pages must not broaden the anchor and make a
     * third subject appear related just because it already contaminated the
     * path. Specific goals still use the full concept set.
     *
     * @return array<int, array{name: string, description: string}>
     */
    private function relevanceConceptContext(Material $material, MaterialRelevanceClassifier $classifier): array
    {
        $space = $material->learningSpace;
        $concepts = $space->concepts();

        if ($classifier instanceof LocalMaterialRelevanceClassifier && $classifier->hasBroadGoal($space)) {
            $anchorMaterialId = $space->materials()
                ->where('materials.id', '!=', $material->id)
                ->where('materials.status', 'ready')
                ->whereHas('chunks', fn ($chunks) => $chunks
                    ->where('relevance_status', 'related')
                    ->whereColumn('material_chunks.extraction_version', 'materials.extraction_version')
                    ->whereHas('concepts'))
                ->orderBy('materials.created_at')
                ->orderBy('materials.id')
                ->value('materials.id');

            if ($anchorMaterialId === null) {
                $concepts->whereRaw('1 = 0');
            } else {
                $concepts->whereHas('sources', fn ($sources) => $sources
                    ->where('material_id', $anchorMaterialId)
                    ->where('relevance_status', 'related'));
            }
        }

        return $concepts->orderBy('name')->get(['name', 'description'])
            ->map(fn ($concept) => ['name' => $concept->name, 'description' => $concept->description])->all();
    }

    public function failed(?Throwable $exception): void
    {
        $material = Material::find($this->materialId);
        if (! $material) {
            return;
        }
        $metadata = $material->metadata ?? [];
        $metadata['failed_stage'] = $metadata['processing_stage'] ?? 'extraction';
        $material->update([
            'status' => 'failed',
            'failure_message' => $this->safeMessage($exception),
            'metadata' => $metadata,
        ]);
    }

    private function safeMessage(?Throwable $exception): string
    {
        $message = $exception?->getMessage() ?? 'Processing failed unexpectedly.';

        return mb_substr($message, 0, 500);
    }

    /**
     * Mathpix's raw `confidence` can be a product across tokens. Use only the
     * provider's geometric-mean `confidence_rate` for this provisional review
     * gate, and never let low-rate text enter relevance or concept generation.
     */
    private function flagLowConfidenceMathpixPages($pending, Material $material)
    {
        $threshold = max(0.0, min(1.0, (float) config('services.mathpix.confidence_rate_review_threshold', 0.75)));
        $flaggedIds = [];
        $warnings = [];

        foreach ($pending as $chunk) {
            $locator = $chunk->locator ?? [];
            $confidenceRate = $locator['confidence_rate'] ?? null;
            if (($locator['extractor'] ?? null) !== 'mathpix') {
                continue;
            }
            if (is_numeric($confidenceRate) && (float) $confidenceRate >= $threshold) {
                continue;
            }

            $pageLabel = $chunk->page ?? 'unknown';
            $hasRate = is_numeric($confidenceRate);
            $reviewReason = $hasRate
                ? 'Some words on this page may be unclear. Compare the page with the original before including it in your study guide.'
                : 'We could not tell how clear the words on this page are. Compare it with the original before including it in your study guide.';
            $warning = $hasRate
                ? 'Page '.$pageLabel.' may contain unclear words. Compare it with the original before using it in your study guide.'
                : 'Page '.$pageLabel.' needs a closer look. Compare it with the original before using it in your study guide.';
            $locator['warnings'] = array_values(array_unique(array_merge($locator['warnings'] ?? [], [$warning])));
            $chunk->update([
                'locator' => $locator,
                'relevance_status' => 'possibly_related',
                'relevance_reason' => $reviewReason,
            ]);
            $flaggedIds[] = $chunk->id;
            $warnings[] = $warning;
        }

        if ($warnings !== []) {
            $metadata = $material->metadata ?? [];
            $metadata['extraction_warnings'] = array_values(array_unique(array_merge($metadata['extraction_warnings'] ?? [], $warnings)));
            $material->update(['metadata' => $metadata]);
        }

        return $pending->reject(fn ($chunk) => in_array($chunk->id, $flaggedIds, true))->values();
    }

    /**
     * Clock-face interpretation is specific to clock-learning goals. Circular
     * STEM figures, such as a periodic table or a star-layer diagram, must not
     * be held for clock confirmation or added as clock evidence.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function applyClockGoalContext(array $page, Material $material): array
    {
        if ($this->isClockLearningContext($material)) {
            return $page;
        }

        unset($page['clock_diagrams']);
        $page['warnings'] = array_values(array_filter(
            $page['warnings'] ?? [],
            fn ($warning): bool => ! is_string($warning)
                || (! str_contains(mb_strtolower($warning), 'clock reader')
                    && ! str_contains(mb_strtolower($warning), 'contains clock faces')),
        ));

        return $page;
    }

    private function isClockLearningContext(Material $material): bool
    {
        $space = $material->learningSpace;
        $context = implode(' ', array_filter([
            $space->subject,
            $space->goal,
        ], fn ($value): bool => is_string($value) && $value !== ''));

        return preg_match('/\b(?:clock|analogue|analog)\b|\b(?:hour|minute)\s+hands?\b|\b(?:read|reading|tell|telling)\s+(?:the\s+)?time\b/i', $context) === 1;
    }

    /**
     * A clock-specific local reader may suggest times, but it must never add
     * diagram facts to a study guide without the learner checking the source.
     */
    private function holdClockDiagramPages($pending, Material $material)
    {
        $flaggedIds = [];
        $warnings = [];

        foreach ($pending as $chunk) {
            $locator = $chunk->locator ?? [];
            if (! is_array($locator['clock_diagrams']['faces'] ?? null) || $locator['clock_diagrams']['faces'] === []) {
                continue;
            }

            $pageLabel = $chunk->page ?? 'unknown';
            $reason = 'Possible clock times were found on this page. Compare each one with the original and correct them before including this page in your study guide.';
            $warning = 'Page '.$pageLabel.' contains possible clock times. Check them against the original before using this page in your study guide.';
            $locator['warnings'] = array_values(array_unique(array_merge($locator['warnings'] ?? [], [$warning])));
            $chunk->update([
                'locator' => $locator,
                'relevance_status' => 'possibly_related',
                'relevance_reason' => $reason,
            ]);
            $flaggedIds[] = $chunk->id;
            $warnings[] = $warning;
        }

        if ($warnings !== []) {
            $metadata = $material->metadata ?? [];
            $metadata['extraction_warnings'] = array_values(array_unique(array_merge($metadata['extraction_warnings'] ?? [], $warnings)));
            $material->update(['metadata' => $metadata]);
        }

        return $pending->reject(fn ($chunk) => in_array($chunk->id, $flaggedIds, true))->values();
    }
}
