<?php

namespace App\Jobs;

use App\Models\Material;
use App\Services\Learning\ConceptExtractor;
use App\Services\Learning\OpenAiConceptExtractor;
use App\Services\Ocr\OcrClient;
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
     * Create a new job instance.
     */
    public function __construct(public int $materialId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('material:'.$this->materialId))->expireAfter(180)];
    }

    /**
     * Execute the job.
     */
    public function handle(OcrClient $ocr, ConceptExtractor $extractor): void
    {
        $material = Material::findOrFail($this->materialId);
        if ($material->status === 'ready') {
            return;
        }

        $material->update(['status' => 'processing', 'failure_message' => null]);
        $bytes = Storage::disk('local')->get($material->private_source_path);
        $ocrResult = $ocr->extract($bytes, $material->mime_type);
        $version = $material->extraction_version + 1;

        DB::transaction(function () use ($material, $ocrResult, $extractor, $version): void {
            $material->chunks()->delete();
            $chunks = collect($ocrResult->pages)->map(fn (array $page) => $material->chunks()->create([
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
                ],
            ]));

            $graph = $extractor->extract($chunks->map(fn ($chunk) => [
                'id' => $chunk->id, 'content' => $chunk->content, 'page' => $chunk->page,
            ])->all());

            $conceptMap = [];
            foreach ($graph['concepts'] as $item) {
                $concept = $material->learningSpace->concepts()->create([
                    'name' => $item['name'], 'description' => $item['description'],
                ]);
                $concept->sources()->sync($item['source_chunk_ids']);
                $conceptMap[$item['id']] = $concept;
            }
            foreach ($graph['dependencies'] as $dependency) {
                $conceptMap[$dependency['concept_id']]->prerequisites()->attach(
                    $conceptMap[$dependency['prerequisite_id']]->id,
                    ['source_supported' => $dependency['source_supported']],
                );
            }

            $material->update([
                'status' => 'ready', 'extraction_version' => $version,
                'metadata' => [
                    'ocr_confidence' => $ocrResult->confidence,
                    'ocr_request_id' => $ocrResult->providerRequestId,
                    'extractor' => $ocrResult->extractor,
                    'extraction_warnings' => $ocrResult->warnings,
                    'concept_prompt_version' => OpenAiConceptExtractor::PROMPT_VERSION,
                ],
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        Material::whereKey($this->materialId)->update([
            'status' => 'failed',
            'failure_message' => $this->safeMessage($exception),
        ]);
    }

    private function safeMessage(?Throwable $exception): string
    {
        $message = $exception?->getMessage() ?? 'Processing failed unexpectedly.';

        return mb_substr($message, 0, 500);
    }
}
