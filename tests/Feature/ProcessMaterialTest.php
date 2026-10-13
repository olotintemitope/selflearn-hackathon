<?php

namespace Tests\Feature;

use App\Jobs\ProcessMaterial;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\PastPaperQuestion;
use App\Models\User;
use App\Services\Learning\ConceptExtractor;
use App\Services\Learning\LocalMaterialRelevanceClassifier;
use App\Services\Learning\MaterialRelevanceClassifier;
use App\Services\Learning\PastPaperQuestionIndexer;
use App\Services\Ocr\BatchOcrClient;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_queued_payload_without_reuse_flag_uses_safe_default(): void
    {
        $jobClass = new \ReflectionClass(ProcessMaterial::class);
        $legacyPayload = $jobClass->newInstanceWithoutConstructor();
        $jobClass->getProperty('materialId')->setValue($legacyPayload, 123);
        $restored = unserialize(serialize($legacyPayload));

        $this->assertSame(123, $restored->materialId);
        $this->assertFalse($restored->reuseClassifiedChunks);
    }

    public function test_science_material_becomes_page_linked_valid_concepts(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create([
            'private_source_path' => 'materials/science.png',
        ]);
        $material->learningSpace->update(['goal' => 'Photosynthesis and plant energy']);
        Storage::disk('local')->put($material->private_source_path, 'real-image-bytes');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p1sAAAAASUVORK5CYII=');
        $ocr = new class($png) implements OcrClient
        {
            public function __construct(private string $png) {}

            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([[
                    'page' => 1,
                    'content' => 'Photosynthesis converts light energy into chemical energy.',
                    'extractor' => 'tesseract',
                    'warnings' => ['Page 1 required local OCR.'],
                    'figure_assets' => [
                        ['bytes' => 'not-an-image', 'mime_type' => 'image/png', 'alt' => 'Invalid crop'],
                        ['bytes' => $this->png, 'mime_type' => 'image/png', 'alt' => 'Photosynthesis diagram'],
                    ],
                ]], .97, 'ocr-1', 'tesseract', ['Page 1 required local OCR.']);
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
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::assertSame('Photosynthesis and plant energy', $space->goal);
                TestCase::assertSame([], $existingConcepts);

                return array_map(fn (array $chunk) => [
                    'chunk_id' => $chunk['id'], 'status' => 'related', 'confidence' => 0.98,
                    'reason' => 'This page explains photosynthesis, which advances the goal.',
                ], $chunks);
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, $classifier);

        $material->refresh();
        $this->assertSame('ready', $material->status);
        $this->assertSame(1, $material->extraction_version);
        $this->assertSame(1, $material->chunks()->first()->page);
        $this->assertSame('tesseract', $material->chunks()->first()->locator['extractor']);
        $this->assertSame('tesseract', $material->metadata['extractor']);
        $this->assertSame('related', $material->chunks()->first()->relevance_status);
        $this->assertSame(['Page 1 required local OCR.'], $material->metadata['extraction_warnings']);
        $figure = $material->chunks()->first()->locator['figures'][0];
        $this->assertSame('Photosynthesis diagram', $figure['alt']);
        $this->assertStringEndsWith('/figure-1.png', $figure['path']);
        Storage::disk('local')->assertExists($figure['path']);
        $concept = $material->learningSpace->concepts()->firstOrFail();
        $this->assertSame([$material->chunks()->first()->id], $concept->sources()->pluck('material_chunks.id')->all());
        Storage::disk('local')->assertExists($material->private_source_path);
    }

    public function test_non_clock_stem_diagrams_do_not_trigger_clock_review(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create([
            'title' => 'NASA elements and star layers',
            'private_source_path' => 'materials/nasa-elements.png',
        ]);
        $material->learningSpace->update([
            'title' => 'Learn how stars create chemical elements',
            'subject' => 'Chemistry and stellar nucleosynthesis',
            'goal' => 'Understand where chemical elements come from and how stellar processes form them.',
        ]);
        Storage::disk('local')->put($material->private_source_path, 'diagram-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([[
                    'page' => 1,
                    'content' => 'Figure 1: The periodic table of the elements, coded by the processes that produce them.',
                    'extractor' => 'mathpix',
                    'confidence_rate' => .96,
                    'clock_diagrams' => ['version' => 'local-clock-reader-v1', 'status' => 'needs_learner_confirmation', 'faces' => [
                        ['index' => 1, 'candidate_time' => '02:15', 'match_quality' => 'candidate', 'bounds' => ['left' => .1, 'top' => .2, 'width' => .1, 'height' => .1]],
                    ]],
                    'warnings' => ['A local clock reader found candidate times. Check or correct them against the original before including this page in your study guide.'],
                ]], .96, 'nasa-diagram-test', 'mathpix', [], .96);
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::assertStringContainsString('elements', $chunks[0]['content']);

                return [[
                    'chunk_id' => $chunks[0]['id'], 'status' => 'related', 'confidence' => .98,
                    'reason' => 'This page explains how elements are represented and formed.',
                ]];
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertCount(1, $chunks);

                return ['concepts' => [[
                    'id' => 'element-formation', 'name' => 'Element formation', 'description' => 'Stellar processes produce chemical elements.',
                    'source_chunk_ids' => [$chunks[0]['id']],
                ]], 'dependencies' => []];
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, $classifier);

        $chunk = $material->chunks()->firstOrFail();
        $this->assertSame('related', $chunk->relevance_status);
        $this->assertNull($chunk->locator['clock_diagrams']);
        $this->assertStringNotContainsString('clock reader', implode(' ', $chunk->locator['warnings'] ?? []));
        $this->assertSame(1, $material->learningSpace->concepts()->count());
        $this->assertSame('ready', $material->refresh()->status);
    }

    public function test_clock_learning_goal_keeps_clock_candidates_in_review(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create([
            'title' => 'Clock face worksheet',
            'private_source_path' => 'materials/clock.png',
        ]);
        $material->learningSpace->update([
            'title' => 'Learn to read analogue clocks',
            'subject' => 'Mathematics: telling time',
            'goal' => 'Read hour and minute hands and tell times to the hour and half hour.',
        ]);
        Storage::disk('local')->put($material->private_source_path, 'clock-image-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([[
                    'page' => 1,
                    'content' => 'What time is it?',
                    'extractor' => 'mathpix',
                    'confidence_rate' => .96,
                    'clock_diagrams' => ['version' => 'local-clock-reader-v1', 'status' => 'needs_learner_confirmation', 'faces' => [
                        ['index' => 1, 'candidate_time' => '03:00', 'match_quality' => 'candidate', 'bounds' => ['left' => .1, 'top' => .2, 'width' => .1, 'height' => .1]],
                    ]],
                    'warnings' => ['A local clock reader found candidate times. Check or correct them against the original before including this page in your study guide.'],
                ]], .96, 'clock-image-test', 'mathpix', [], .96);
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::fail('Unconfirmed clock readings must wait for learner review.');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::fail('Unconfirmed clock readings must not become concepts.');
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, $classifier);

        $chunk = $material->chunks()->firstOrFail();
        $this->assertSame('possibly_related', $chunk->relevance_status);
        $this->assertSame('needs_learner_confirmation', $chunk->locator['clock_diagrams']['status']);
        $this->assertSame(0, $material->learningSpace->concepts()->count());
        $this->assertSame('ready', $material->refresh()->status);
    }

    public function test_low_mathpix_confidence_rate_requires_learner_review_before_concept_extraction(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create(['private_source_path' => 'materials/uncertain.png']);
        Storage::disk('local')->put($material->private_source_path, 'scan-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([[
                    'page' => 1,
                    'content' => 'The law of conservation of energy. Explain using a swinging pendulum.',
                    'extractor' => 'mathpix',
                    'confidence' => .02,
                    'confidence_rate' => .62,
                ]], .02, 'ocr-review-test', 'mathpix', [], .62);
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::fail('Low-confidence OCR must not be classified or sent into concept generation.');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::fail('Low-confidence OCR must not reach the concept extractor.');
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, $classifier);

        $chunk = $material->chunks()->firstOrFail();
        $this->assertSame('possibly_related', $chunk->relevance_status);
        $this->assertSame(.62, $chunk->locator['confidence_rate']);
        $this->assertStringContainsString('Compare the page with the original', $chunk->relevance_reason);
        $this->assertContains('Page 1 may contain unclear words. Compare it with the original before using it in your study guide.', $material->refresh()->metadata['extraction_warnings']);
        $this->assertSame(0, $material->learningSpace->concepts()->count());
        $this->assertSame('ready', $material->status);
        Storage::disk('local')->assertExists($material->private_source_path);

        $this->actingAs($material->learningSpace->user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Include this page')
            ->assertSee('Open the original material to compare');
    }

    public function test_missing_mathpix_confidence_rate_requires_review_and_raw_product_is_not_misrepresented(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create(['private_source_path' => 'materials/rate-unavailable.png']);
        Storage::disk('local')->put($material->private_source_path, 'scan-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([[
                    'page' => 1, 'content' => 'A physics source mentions kinetic energy.',
                    'extractor' => 'mathpix', 'confidence' => .02,
                ]], .02, 'ocr-product-only', 'mathpix');
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::fail('A Mathpix page with no confidence rate must not be auto-classified.');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::fail('A Mathpix page with no confidence rate must not reach concept generation.');
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, $classifier);

        $chunk = $material->chunks()->firstOrFail();
        $this->assertSame('possibly_related', $chunk->relevance_status);
        $this->assertStringContainsString('could not tell how clear the words', $chunk->relevance_reason);
        $this->assertStringNotContainsString('Mathpix', implode(' ', $material->refresh()->metadata['extraction_warnings']));
        $this->assertStringNotContainsString('confidence rate', implode(' ', $material->refresh()->metadata['extraction_warnings']));
        $this->assertSame(0, $material->learningSpace->concepts()->count());
    }

    public function test_past_paper_processing_indexes_page_questions_without_mixing_examiner_notes_into_prompts(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create([
            'role' => 'past_paper',
            'title' => 'WAEC Physics Paper 2 2023',
            'original_filename' => 'physics-paper-2023.pdf',
            'private_source_path' => 'materials/physics-paper.png',
        ]);
        Storage::disk('local')->put($material->private_source_path, 'scan-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([[
                    'page' => 7,
                    'content' => <<<'OCR'
                    \section*{Question 2}
                    1. Define strain energy and write an expression for a stretched wire. (2 marks)
                    \section*{Observation}
                    Some candidates confused strain energy with an elastic constant.
                    EXPECTED RESPONSES:
                    The energy stored in a material as a result of deformation.
                    E = Y A e^2 / (2l)
                    OCR,
                    'extractor' => 'mathpix',
                    'confidence' => .96,
                    'confidence_rate' => .96,
                ]], .96, 'request-1', 'mathpix', [], .96);
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                return ['concepts' => [[
                    'id' => 'strain', 'name' => 'Strain energy', 'description' => 'Energy stored by deformation.',
                    'source_chunk_ids' => [$chunks[0]['id']],
                ]], 'dependencies' => []];
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                return array_map(fn (array $chunk) => [
                    'chunk_id' => $chunk['id'], 'status' => 'related', 'confidence' => .98,
                    'reason' => 'This is the uploaded Physics question.',
                ], $chunks);
            }
        };

        $job = new ProcessMaterial($material->id);
        $job->handle($ocr, $extractor, $classifier);
        $question = PastPaperQuestion::query()->firstOrFail();

        $this->assertSame('ready', $material->refresh()->status);
        $this->assertSame(7, $question->page);
        $this->assertSame('2', $question->question_number);
        $this->assertSame(2023, $question->paper_year);
        $this->assertSame(2, $question->marks);
        $this->assertSame('structured_response', $question->question_type);
        $this->assertStringContainsString('Define strain energy', $question->prompt);
        $this->assertStringContainsString('(2 marks)', $question->source_text);
        $this->assertStringNotContainsString('Some candidates', $question->prompt);
        $this->assertSame('source_expected_response', $question->answer_source);
        $this->assertArrayNotHasKey('expected_answer', $question->toArray());

        app(PastPaperQuestionIndexer::class)->index($material->fresh());
        $this->assertSame(1, $material->pastPaperQuestions()->count());
    }

    public function test_ambiguous_multi_year_upload_label_does_not_assign_a_single_paper_year(): void
    {
        $material = Material::factory()->create([
            'role' => 'past_paper',
            'status' => 'ready',
            'extraction_version' => 1,
            'title' => 'WAEC Physics practice 2021 to 2023',
            'original_filename' => 'physics-2023.pdf',
        ]);
        $material->chunks()->create([
            'extraction_version' => 1,
            'content' => '1. State the unit of force. (2 marks)',
            'page' => 1,
            'locator' => ['page' => 1, 'confidence' => .96],
            'relevance_status' => 'related',
        ]);

        app(PastPaperQuestionIndexer::class)->index($material);

        $this->assertNull($material->pastPaperQuestions()->firstOrFail()->paper_year);
    }

    public function test_learner_can_build_and_review_a_past_paper_question_list_without_exposing_detected_answers(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->for(LearningSpace::factory()->for($user))->create([
            'role' => 'past_paper',
            'status' => 'ready',
            'extraction_version' => 1,
        ]);
        $material->chunks()->create([
            'extraction_version' => 1,
            'content' => <<<'OCR'
            \section*{Question 4}
            1. Define strain energy.
            \section*{Observation}
            Some candidates confused it with elastic constant.
            EXPECTED RESPONSES:
            Strain energy is energy stored by deformation.
            OCR,
            'page' => 3,
            'locator' => ['page' => 3, 'extractor' => 'mathpix', 'confidence' => .92],
            'relevance_status' => 'related',
        ]);

        $this->actingAs($user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Past-paper question bank')
            ->assertSee('Find questions on this paper')
            ->assertDontSee('Strain energy is energy stored by deformation.');

        $this->post(route('materials.past-paper-questions.index', $material))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Question 4 · Page 3')
            ->assertSee('Define strain energy.')
            ->assertSee('Answer text from the paper is kept separate')
            ->assertDontSee('Some candidates confused it')
            ->assertDontSee('Strain energy is energy stored by deformation.');

        $other = User::factory()->create();
        $this->actingAs($other)->post(route('materials.past-paper-questions.index', $material))->assertForbidden();
    }

    public function test_unrelated_and_uncertain_pages_are_retained_but_do_not_create_concepts(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create();
        Storage::disk('local')->put($material->private_source_path, 'real-pdf-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([
                    ['page' => 1, 'content' => 'Clock faces show hours and minutes on an analogue clock.', 'extractor' => 'pdftotext'],
                    ['page' => 2, 'content' => 'HTML and CSS define the structure and style of a web page.', 'extractor' => 'pdftotext'],
                    ['page' => 3, 'content' => 'Time calculations in this chapter may help with elapsed-time exercises.', 'extractor' => 'pdftotext'],
                ], null, null, 'pdftotext');
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                return [
                    ['chunk_id' => $chunks[0]['id'], 'status' => 'related', 'confidence' => 0.95, 'reason' => 'It teaches reading clock faces.'],
                    ['chunk_id' => $chunks[1]['id'], 'status' => 'separate', 'confidence' => 0.97, 'reason' => 'It teaches web page structure and styling.'],
                    ['chunk_id' => $chunks[2]['id'], 'status' => 'possibly_related', 'confidence' => 0.61, 'reason' => 'It may relate through elapsed time, but context is unclear.'],
                ];
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertCount(1, $chunks);
                TestCase::assertSame(1, $chunks[0]['page']);

                return ['concepts' => [[
                    'id' => 'clock', 'name' => 'Reading a clock', 'description' => 'Relating hands to time.',
                    'source_chunk_ids' => [$chunks[0]['id']],
                ]], 'dependencies' => []];
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, $classifier);

        $this->assertSame(['related', 'separate', 'possibly_related'], $material->chunks()->orderBy('page')->pluck('relevance_status')->all());
        $this->assertSame(1, $material->learningSpace->concepts()->count());
        $this->assertSame(1, $material->refresh()->metadata['separate_page_count']);
        $this->assertSame(1, $material->metadata['possibly_related_page_count']);
        Storage::disk('local')->assertExists($material->private_source_path);
    }

    public function test_generic_goal_topic_anchor_keeps_food_and_ict_pages_out_of_physics_concepts(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $space = LearningSpace::factory()->for($owner)->create([
            'title' => 'Real scan batch evaluation',
            'goal' => 'Study the materials I upload',
        ]);
        $firstMaterial = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
        $physicsSource = $firstMaterial->chunks()->create([
            'extraction_version' => 1,
            'page' => 1,
            'content' => 'Conservation of energy: a pendulum transfers potential energy into kinetic energy. A force changes motion.',
            'locator' => ['page' => 1],
            'relevance_status' => 'related',
        ]);
        $physicsConcept = $space->concepts()->create([
            'name' => 'Conservation of energy',
            'description' => 'Energy changes form in a closed system. A pendulum transfers potential energy into kinetic energy. A force changes motion.',
        ]);
        $physicsConcept->sources()->attach($physicsSource->id);

        foreach ([
            [
                'name' => 'Food and nutrition',
                'description' => 'Carbohydrates provide nutrients. Proteins support growth, while vitamins and minerals support health.',
                'content' => 'Food and Nutrition: carbohydrates provide nutrients. Proteins support growth, while vitamins and minerals support health.',
            ],
            [
                'name' => 'Computer networks',
                'description' => 'Computer hardware, software, networks, and web pages support information technology.',
                'content' => 'Information and Communication Technology: computer hardware, software, networks, and web pages.',
            ],
        ] as $seed) {
            $otherMaterial = Material::factory()->for($space)->create(['status' => 'ready', 'extraction_version' => 1]);
            $otherSource = $otherMaterial->chunks()->create([
                'extraction_version' => 1,
                'page' => 1,
                'content' => $seed['content'],
                'locator' => ['page' => 1],
                'relevance_status' => 'related',
            ]);
            $otherConcept = $space->concepts()->create([
                'name' => $seed['name'],
                'description' => $seed['description'],
            ]);
            $otherConcept->sources()->attach($otherSource->id);
        }

        $material = Material::factory()->for($space)->create([
            'private_source_path' => 'materials/mixed-subject-scans.png',
        ]);
        Storage::disk('local')->put($material->private_source_path, 'image-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([
                    ['page' => 1, 'content' => 'Food and Nutrition: carbohydrates provide energy. Proteins support growth, while vitamins and minerals support health.', 'extractor' => 'pdftotext'],
                    ['page' => 2, 'content' => 'Information and Communication Technology: computer hardware, software, networks, and web pages.', 'extractor' => 'pdftotext'],
                    ['page' => 3, 'content' => 'Conservation of energy explains energy changes. Work and power describe energy transfer.', 'extractor' => 'pdftotext'],
                ], null, null, 'pdftotext');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertCount(1, $chunks);
                TestCase::assertSame(3, $chunks[0]['page']);

                return ['concepts' => [[
                    'id' => 'kinetic-energy',
                    'name' => 'Kinetic energy',
                    'description' => 'Kinetic energy depends on mass and velocity.',
                    'source_chunk_ids' => [$chunks[0]['id']],
                ]], 'dependencies' => []];
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, new LocalMaterialRelevanceClassifier);

        $this->assertSame(['possibly_related', 'separate', 'related'], $material->chunks()->orderBy('page')->pluck('relevance_status')->all());
        $this->assertSame(4, $space->concepts()->count());
        $this->assertSame([$material->chunks()->where('page', 3)->value('id')], $space->concepts()->where('name', 'Kinetic energy')->firstOrFail()->sources()->pluck('material_chunks.id')->all());
        Storage::disk('local')->assertExists($material->private_source_path);
    }

    public function test_a_later_upload_waits_for_the_first_topic_anchor_in_a_broad_space(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([['page' => 1, 'content' => 'Photosynthesis converts light into stored energy.', 'extractor' => 'pdftotext']], null, null, 'pdftotext');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertSame([], $chunks);

                return ['concepts' => [], 'dependencies' => []];
            }
        };

        foreach (['pending', 'processing'] as $earlierStatus) {
            $space = LearningSpace::factory()->for($owner)->create([
                'title' => 'Real scan batch evaluation',
                'goal' => 'Study the materials I upload',
            ]);
            Material::factory()->for($space)->create([
                'status' => $earlierStatus,
                'extraction_version' => 1,
                'created_at' => now()->subMinute(),
            ]);
            $material = Material::factory()->for($space)->create([
                'private_source_path' => 'materials/later-upload-'.$earlierStatus.'.png',
                'created_at' => now(),
            ]);
            Storage::disk('local')->put($material->private_source_path, 'image-bytes');

            (new ProcessMaterial($material->id))->handle($ocr, $extractor, new LocalMaterialRelevanceClassifier);

            $this->assertSame('possibly_related', $material->chunks()->firstOrFail()->relevance_status);
            $this->assertStringContainsString('first material is still being prepared', $material->chunks()->first()->relevance_reason);
        }
    }

    public function test_a_later_upload_can_anchor_after_a_completed_material_without_concepts(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $space = LearningSpace::factory()->for($owner)->create([
            'title' => 'General study',
            'goal' => 'Study the materials I upload',
        ]);
        Material::factory()->for($space)->create([
            'status' => 'ready',
            'extraction_version' => 1,
            'created_at' => now()->subMinute(),
        ]);
        $material = Material::factory()->for($space)->create([
            'private_source_path' => 'materials/first-readable-topic.png',
            'created_at' => now(),
        ]);
        Storage::disk('local')->put($material->private_source_path, 'image-bytes');
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                return new OcrResult([['page' => 1, 'content' => 'Physics explains motion, forces, and energy transfer.', 'extractor' => 'pdftotext']], null, null, 'pdftotext');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertCount(1, $chunks);

                return ['concepts' => [], 'dependencies' => []];
            }
        };

        (new ProcessMaterial($material->id))->handle($ocr, $extractor, new LocalMaterialRelevanceClassifier);

        $this->assertSame('related', $material->chunks()->firstOrFail()->relevance_status);
    }

    public function test_empty_ai_concept_output_uses_a_labeled_fallback_for_confirmed_clock_readings(): void
    {
        $material = Material::factory()->create(['status' => 'processing', 'extraction_version' => 1]);
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'page' => 1,
            'content' => 'Learner-confirmed local clock diagram readings (not Mathpix OCR): Clock face 1 shows 03:00.',
            'locator' => ['clock_diagrams' => [
                'status' => 'learner_confirmed',
                'faces' => [['index' => 1, 'confirmed_time' => '03:00']],
            ]],
            'relevance_status' => 'related',
        ]);
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                TestCase::fail('Saved, confirmed evidence must not be extracted again.');
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertStringContainsString('03:00', $chunks[0]['content']);

                return ['concepts' => [], 'dependencies' => []];
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::fail('A confirmed related page must not be classified again.');
            }
        };

        (new ProcessMaterial($material->id, true))->handle($ocr, $extractor, $classifier);

        $concept = $material->learningSpace->concepts()->firstOrFail();
        $this->assertSame([$chunk->id], $concept->sources()->pluck('material_chunks.id')->all());
        $this->assertStringContainsString('Supplementary clock-reading guidance', $concept->description);
        $this->assertSame('ready', $material->refresh()->status);
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

    public function test_retry_after_concept_provider_failure_reuses_persisted_extraction_and_classification(): void
    {
        Storage::fake('local');
        $material = Material::factory()->create(['private_source_path' => 'materials/retry.pdf']);
        Storage::disk('local')->put($material->private_source_path, 'real-pdf-bytes');
        $ocr = new class implements OcrClient
        {
            public int $calls = 0;

            public function extract(string $bytes, string $mimeType): OcrResult
            {
                $this->calls++;

                return new OcrResult([[
                    'page' => 1,
                    'content' => 'The water cycle includes evaporation and condensation.',
                    'extractor' => 'pdftotext',
                ]], null, null, 'pdftotext');
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public int $calls = 0;

            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                $this->calls++;

                return array_map(fn (array $chunk) => [
                    'chunk_id' => $chunk['id'], 'status' => 'related', 'confidence' => .98,
                    'reason' => 'This page explains the water cycle.',
                ], $chunks);
            }
        };
        $failedExtractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                throw new \RuntimeException('OpenAI blocked concept generation because of an API usage or spending limit (HTTP 429, type=insufficient_quota, code=credit_balance_exhausted, request_id=req_test-123).');
            }
        };
        $job = new ProcessMaterial($material->id);
        try {
            $job->handle($ocr, $failedExtractor, $classifier);
            $this->fail('Expected the concept provider to fail.');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $material->refresh();
        $this->assertSame('failed', $material->status);
        $this->assertSame(1, $material->chunks()->count());
        $this->assertSame('related', $material->chunks()->first()->relevance_status);
        $this->assertSame('concepts', $material->metadata['processing_stage']);
        $this->assertSame('concepts', $material->metadata['failed_stage']);
        $this->actingAs($material->learningSpace->user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Pages extracted')
            ->assertSee('Pages checked against your goal')
            ->assertSee('Study guide created')
            ->assertSee('Error generating study guide')
            ->assertSee('AI service has reached its usage limit')
            ->assertSee('Your pages were extracted and checked against your goal.')
            ->assertSee('Your saved pages and relevance checks will be reused if you retry.')
            ->assertDontSee('OpenAI blocked concept generation')
            ->assertDontSee('req_test-123');
        $material->update(['status' => 'pending', 'failure_message' => null]);

        $noOcr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                throw new \LogicException('Retry must reuse stored OCR pages.');
            }
        };
        $noReclassification = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                throw new \LogicException('Retry must reuse persisted relevance decisions.');
            }
        };
        $successExtractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertCount(1, $chunks);

                return ['concepts' => [[
                    'id' => 'water-cycle', 'name' => 'Water cycle', 'description' => 'Water moves through evaporation and condensation.',
                    'source_chunk_ids' => [$chunks[0]['id']],
                ]], 'dependencies' => []];
            }
        };
        (new ProcessMaterial($material->id))->handle($noOcr, $successExtractor, $noReclassification);

        $this->assertSame(1, $ocr->calls);
        $this->assertSame(1, $classifier->calls);
        $this->assertSame('ready', $material->refresh()->status);
        $this->assertArrayNotHasKey('processing_stage', $material->metadata);
        $this->assertSame(1, $material->learningSpace->concepts()->count());
        $this->assertSame(1, $material->chunks()->count());
    }

    public function test_large_pdf_is_extracted_and_finalized_across_idempotent_page_batches(): void
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('services.extraction.page_batch_size', 2);
        $material = Material::factory()->create([
            'private_source_path' => 'materials/long-book.pdf', 'mime_type' => 'application/pdf',
        ]);
        Storage::disk('local')->put($material->private_source_path, 'real-pdf-bytes');
        $ocr = new class implements BatchOcrClient
        {
            public array $ranges = [];

            public function extract(string $bytes, string $mimeType): OcrResult
            {
                throw new \LogicException('PDF extraction should use page batches.');
            }

            public function pageCount(string $bytes, string $mimeType): int
            {
                return 3;
            }

            public function extractBatch(string $bytes, string $mimeType, int $firstPage, int $lastPage): OcrResult
            {
                $this->ranges[] = [$firstPage, $lastPage];
                $pages = [];
                for ($page = $firstPage; $page <= $lastPage; $page++) {
                    $pages[] = ['page' => $page, 'content' => 'Page '.$page.' contains readable learning material.', 'extractor' => 'pdftotext'];
                }

                return new OcrResult($pages, null, null, 'pdftotext');
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                return array_map(fn (array $chunk) => [
                    'chunk_id' => $chunk['id'], 'status' => 'related', 'confidence' => .95,
                    'reason' => 'This page contributes to the study goal.',
                ], $chunks);
            }
        };
        $extractor = new class implements ConceptExtractor
        {
            public function extract(array $chunks): array
            {
                TestCase::assertCount(3, $chunks);

                return ['concepts' => [[
                    'id' => 'book-topic', 'name' => 'Book topic', 'description' => 'A topic from the book.',
                    'source_chunk_ids' => array_column($chunks, 'id'),
                ]], 'dependencies' => []];
            }
        };

        $job = new ProcessMaterial($material->id);
        $job->handle($ocr, $extractor, $classifier);
        $material->refresh();
        $this->assertSame('processing', $material->status);
        $this->assertSame([1, 2], $material->chunks()->orderBy('page')->pluck('page')->all());
        $this->assertSame(0, $material->extraction_version);
        Queue::assertPushed(ProcessMaterial::class);
        $this->actingAs($material->learningSpace->user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('page 2 of 3')
            ->assertSee('<progress', false);

        // Simulate a worker retry after page persistence but before cursor advancement.
        $metadata = $material->metadata;
        $metadata['extraction_progress']['next_page'] = 1;
        $material->update(['metadata' => $metadata]);
        $job->handle($ocr, $extractor, $classifier);
        $this->assertSame(2, $material->chunks()->count());

        // The queued continuation resumes from the saved cursor.
        $job->handle($ocr, $extractor, $classifier);

        $this->assertSame([[1, 2], [1, 2], [3, 3]], $ocr->ranges);
        $this->assertSame('ready', $material->refresh()->status);
        $this->assertSame(1, $material->extraction_version);
        $this->assertSame([1, 2, 3], $material->chunks()->orderBy('page')->pluck('page')->all());
        $this->assertSame(['related', 'related', 'related'], $material->chunks()->orderBy('page')->pluck('relevance_status')->all());
        $this->assertArrayNotHasKey('extraction_progress', $material->metadata);
    }

    public function test_rechecking_pending_saved_pages_reuses_extraction_and_keeps_unrelated_pages_out(): void
    {
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 2]);
        $included = $material->chunks()->create([
            'extraction_version' => 2, 'content' => 'Clock faces tell time.', 'page' => 1, 'locator' => ['page' => 1],
            'relevance_status' => 'related',
        ]);
        $pending = $material->chunks()->create([
            'extraction_version' => 2, 'content' => 'Web pages use HTML and CSS.', 'page' => 2, 'locator' => ['page' => 2],
            'relevance_status' => 'pending',
        ]);
        $ocr = new class implements OcrClient
        {
            public function extract(string $bytes, string $mimeType): OcrResult
            {
                throw new \LogicException('Rechecking saved pages must not run OCR again.');
            }
        };
        $classifier = new class implements MaterialRelevanceClassifier
        {
            public function classify(LearningSpace $space, array $chunks, array $existingConcepts): array
            {
                TestCase::assertCount(1, $chunks);
                TestCase::assertSame(2, $chunks[0]['page']);

                return [[
                    'chunk_id' => $chunks[0]['id'], 'status' => 'separate', 'confidence' => .99,
                    'reason' => 'It teaches a distinct web-development goal.',
                ]];
            }
        };
        $extractor = new class($included->id) implements ConceptExtractor
        {
            public function __construct(private int $includedId) {}

            public function extract(array $chunks): array
            {
                TestCase::assertSame([$this->includedId], array_column($chunks, 'id'));

                return ['concepts' => [[
                    'id' => 'clock', 'name' => 'Clock faces', 'description' => 'Read time from hands.',
                    'source_chunk_ids' => [$this->includedId],
                ]], 'dependencies' => []];
            }
        };

        (new ProcessMaterial($material->id, true))->handle($ocr, $extractor, $classifier);

        $this->assertSame('separate', $pending->refresh()->relevance_status);
        $this->assertSame('related', $included->refresh()->relevance_status);
        $this->assertSame(2, $material->refresh()->extraction_version);
        $this->assertSame('ready', $material->status);
    }
}
