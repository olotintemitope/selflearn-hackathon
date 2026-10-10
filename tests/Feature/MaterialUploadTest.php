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
        Storage::disk('local')->assertExists($material->private_source_path);
        $this->assertStringStartsWith('materials/'.$space->user_id.'/', $material->private_source_path);
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
