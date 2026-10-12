<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibilityPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_use_dialog_offers_listening_and_reading_without_requesting_a_disability_disclosure(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('materials.index'))
            ->assertOk()
            ->assertSee('data-accessibility-dialog data-has-preference="false"', false)
            ->assertSee('How would you like to use SelfLearn?')
            ->assertSee('Listen to content')
            ->assertSee('Read on screen')
            ->assertSee('Not now')
            ->assertSee('This choice does not change screen-reader or keyboard support.')
            ->assertDontSee('blind')
            ->assertDontSee('deaf');
    }

    public function test_learner_can_save_and_later_change_their_private_learning_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('accessibility-preferences.update'), ['experience' => 'listen'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Your learning preference has been saved. You can change it anytime.');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'accessibility_experience' => 'listen']);

        $this->post(route('accessibility-preferences.update'), ['experience' => 'read'])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'accessibility_experience' => 'read']);

        $this->get(route('materials.index'))
            ->assertOk()
            ->assertSee('data-accessibility-dialog data-has-preference="true"', false)
            ->assertSee('value="read" checked', false)
            ->assertSee('Accessibility preferences');
    }

    public function test_invalid_preference_is_rejected_and_guests_cannot_save_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('materials.index'))
            ->post(route('accessibility-preferences.update'), ['experience' => 'blind'])
            ->assertSessionHasErrors('experience');
        $this->assertNull($user->fresh()->accessibility_experience);

        auth()->logout();
        $this->post(route('accessibility-preferences.update'), ['experience' => 'listen'])
            ->assertRedirect(route('login'));
    }
}
