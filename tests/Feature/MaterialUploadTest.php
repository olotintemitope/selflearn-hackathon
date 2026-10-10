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
            'material' => UploadedFile::fake()->create('unsafe.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('material');
        $this->assertDatabaseCount('materials', 0);
    }
}
