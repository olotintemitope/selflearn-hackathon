<?php

namespace Tests\Feature;

use App\Jobs\ProcessMaterial;
use App\Models\LearningSpace;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_authentication_pages_are_separate_and_linked(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('Create an account')
            ->assertDontSee('Confirm password');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create your study space')
            ->assertSee('Already have an account?')
            ->assertDontSee('Welcome back');
    }

    public function test_shared_brand_places_a_decorative_robot_icon_after_the_wordmark(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('materials.index'))
            ->assertOk()
            ->assertSee('SelfLearn')
            ->assertSee('class="brand-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"', false);
    }

    public function test_owner_can_upload_to_private_storage_and_processing_is_queued(): void
    {
        Storage::fake('local');
        Queue::fake();
        $space = LearningSpace::factory()->create();

        $response = $this->actingAs($space->user)->post(route('materials.store'), [
            'learning_space_id' => $space->id,
            'title' => 'Photosynthesis notes',
            'role' => 'study_notes',
            'material' => UploadedFile::fake()->image('leaf-notes.png'),
        ]);

        $material = Material::firstOrFail();
        $response->assertRedirect(route('materials.show', $material));
        $this->assertNotSame((string) $material->getKey(), $material->uuid);
        $this->assertStringContainsString($material->uuid, route('materials.show', $material));
        Storage::disk('local')->assertExists($material->private_source_path);
        $this->assertStringStartsWith('materials/'.$space->user_id.'/', $material->private_source_path);
        Queue::assertPushed(ProcessMaterial::class, fn ($job) => $job->materialId === $material->id);
    }

    public function test_material_routes_resolve_by_uuid_not_sequential_database_id(): void
    {
        $material = Material::factory()->create();
        $this->actingAs($material->learningSpace->user)
            ->get(route('materials.show', $material))
            ->assertOk();

        $this->get('/materials/'.$material->getKey())->assertNotFound();
    }

    public function test_extracted_section_commands_are_formatted_for_reading_without_changing_saved_text(): void
    {
        $material = Material::factory()->create(['status' => 'ready', 'extraction_version' => 1]);
        $sourceText = "Elements formed inside stars.\n\\section*{II. The Cosmic Origin of the Elements}\nStars create heavier elements through fusion.";
        $chunk = $material->chunks()->create([
            'extraction_version' => 1,
            'page' => 2,
            'content' => $sourceText,
            'locator' => ['page' => 2],
            'relevance_status' => 'pending',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('<h4>II. The Cosmic Origin of the Elements</h4>', false)
            ->assertDontSee('\\section*{II. The Cosmic Origin of the Elements}')
            ->assertSee('Section headings are formatted for readability. The saved transcription is unchanged.');

        $this->assertSame($sourceText, $chunk->refresh()->content);
    }

    public function test_raw_recognition_confidence_is_not_shown_as_page_quality(): void
    {
        $material = Material::factory()->create([
            'status' => 'ready',
            'extraction_version' => 1,
            'metadata' => ['ocr_confidence' => .02, 'ocr_confidence_rate' => .96],
        ]);
        $material->chunks()->create([
            'extraction_version' => 1,
            'page' => 1,
            'content' => 'The law of conservation of energy explains this example.',
            'locator' => ['page' => 1, 'extractor' => 'mathpix', 'confidence' => .02, 'confidence_rate' => .96],
            'relevance_status' => 'related',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertDontSee('OCR confidence is low')
            ->assertDontSee('Mathpix')
            ->assertDontSee('confidence rate');
    }

    public function test_missing_confidence_rate_shows_plain_review_guidance_without_provider_terms(): void
    {
        $material = Material::factory()->create([
            'status' => 'ready',
            'extraction_version' => 1,
            'metadata' => [
                'ocr_confidence' => .02,
                'ocr_confidence_rate' => null,
                'extraction_warnings' => ['Page 1 has no Mathpix OCR confidence rate and needs review.'],
            ],
        ]);
        $material->chunks()->create([
            'extraction_version' => 1,
            'page' => 1,
            'content' => 'A physics source mentions kinetic energy.',
            'locator' => ['page' => 1, 'extractor' => 'mathpix', 'confidence' => .02, 'confidence_rate' => null],
            'relevance_status' => 'possibly_related',
            'relevance_reason' => 'Mathpix did not provide a confidence rate for this page. Compare the extracted text with the original before including it in your study guide.',
        ]);

        $this->actingAs($material->learningSpace->user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('We could not tell whether all the words on this page were read clearly.')
            ->assertSee('A few pages may need checking')
            ->assertDontSee('Mathpix')
            ->assertDontSee('OCR')
            ->assertDontSee('confidence rate');
    }

    public function test_upload_accepts_large_pdf_above_the_previous_ten_megabyte_limit(): void
    {
        Storage::fake('local');
        Queue::fake();
        $space = LearningSpace::factory()->create();

        $response = $this->actingAs($space->user)->post(route('materials.store'), [
            'learning_space_id' => $space->id,
            'title' => 'Country history book',
            'role' => 'textbook',
            'material' => UploadedFile::fake()->create('country-history.pdf', 11 * 1024, 'application/pdf'),
        ]);

        $material = Material::firstOrFail();
        $response->assertRedirect(route('materials.show', $material));
        $this->assertGreaterThan(10 * 1024 * 1024, $material->size_bytes);
        Queue::assertPushed(ProcessMaterial::class, fn ($job) => $job->materialId === $material->id);
    }

    public function test_user_cannot_upload_into_or_view_another_users_space(): void
    {
        Storage::fake('local');
        Queue::fake();
        $ownerSpace = LearningSpace::factory()->create();
        $other = User::factory()->create();
        $material = Material::factory()->for($ownerSpace)->create();

        $this->actingAs($other)->post(route('materials.store'), [
            'learning_space_id' => $ownerSpace->id,
            'title' => 'Stolen',
            'role' => 'study_notes',
            'material' => UploadedFile::fake()->image('notes.png'),
        ])->assertForbidden();
        $this->actingAs($other)->get(route('materials.show', $material))->assertForbidden();
        $this->actingAs($other)->get(route('materials.download', $material))->assertForbidden();
    }

    public function test_upload_rejects_unsupported_files(): void
    {
        Storage::fake('local');
        $space = LearningSpace::factory()->create();
        $this->actingAs($space->user)->post(route('materials.store'), [
            'learning_space_id' => $space->id,
            'title' => 'Executable',
            'role' => 'study_notes',
            'material' => UploadedFile::fake()->create('unsafe.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('material');
        $this->assertDatabaseCount('materials', 0);
    }

    public function test_learner_can_create_goal_oriented_space_and_add_role_classified_materials(): void
    {
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('spaces.store'), [
            'title' => 'Biology exam revision',
            'purpose' => 'exam_prep',
            'subject' => 'Biology',
            'level' => 'Senior secondary',
            'exam_date' => '2027-06-01',
            'goal' => 'Prepare for essay and practical questions',
        ])->assertRedirect(route('materials.index'));

        $space = LearningSpace::firstOrFail();
        $this->assertSame($user->id, $space->user_id);
        $this->assertSame('exam_prep', $space->purpose);
        $this->assertSame('2027-06-01', $space->exam_date->toDateString());

        foreach ([['Revision notes', 'study_notes'], ['Past paper 2025', 'past_paper']] as [$title, $role]) {
            $this->post(route('materials.store'), [
                'learning_space_id' => $space->id,
                'title' => $title,
                'role' => $role,
                'material' => UploadedFile::fake()->image(str($title)->slug().'.png'),
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('materials', 2);
        $this->assertSame(['past_paper', 'study_notes'], $space->materials()->orderBy('role')->pluck('role')->all());
        $this->actingAs($user)->get(route('materials.index'))
            ->assertOk()
            ->assertSee('Add another material')
            ->assertSee('Past paper')
            ->assertSee('add more later');
    }

    public function test_space_creation_rejects_unknown_purposes_and_keeps_spaces_private(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post(route('spaces.store'), [
            'title' => 'Invalid',
            'purpose' => 'school_subject',
        ])->assertSessionHasErrors('purpose');

        $space = LearningSpace::factory()->for($owner)->create();
        $this->actingAs($other)->get(route('study.show', $space))->assertForbidden();
    }

    public function test_pending_material_shows_learner_focused_automatic_progress(): void
    {
        $material = Material::factory()->create(['status' => 'processing']);

        $this->actingAs($material->learningSpace->user)->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Preparing your study guide')
            ->assertSee('This page will update automatically')
            ->assertSee('window.location.reload()', false)
            ->assertDontSee('queue:work')
            ->assertDontSee('Run the queue worker');
    }
}
