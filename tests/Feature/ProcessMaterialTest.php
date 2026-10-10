<?php

namespace Tests\Feature;

use App\Jobs\ProcessMaterial;
use App\Models\Material;
use App\Services\Learning\ConceptExtractor;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_science_material_becomes_page_linked_valid_concepts(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create(['private_source_path' => 'materials/science.png']);
        Storage::disk('local')->put($material->private_source_path, 'real-image-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([['page' => 1, 'content' => 'Photosynthesis converts light energy into chemical energy.']], .97, 'ocr-1');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                return ['concepts' => [[
                    'id' => 'c1', 'name' => 'Photosynthesis', 'description' => 'Conversion of light into stored chemical energy.',
                    'source_chunk_ids' => [$chunks[0]['id']],
                ]], 'dependencies' => []];
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor);

        $material->refresh();
        $this->assertSame('ready', $material->status);
        $this->assertSame(1, $material->extraction_version);
        $this->assertSame(1, $material->chunks()->first()->page);
        $concept = $material->learningSpace->concepts()->firstOrFail();
        $this->assertSame([$material->chunks()->first()->id], $concept->sources()->pluck('material_chunks.id')->all());
        Storage::disk('local')->assertExists($material->private_source_path);
    }

    public function test_provider_failure_is_actionable_and_original_is_retained(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create(['private_source_path' => 'materials/technical.pdf']);
        Storage::disk('local')->put($material->private_source_path, 'real-pdf-bytes');
        $job = new ProcessMaterial($material->id);
        $job->failed(new OcrException('Mathpix is temporarily unavailable (HTTP 429).'));

        $this->assertSame('failed', $material->refresh()->status);
        $this->assertStringContainsString('429', $material->failure_message);
        Storage::disk('local')->assertExists($material->private_source_path);
    }
}
