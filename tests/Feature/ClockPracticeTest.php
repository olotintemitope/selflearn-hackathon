<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Learning\ClockPracticeQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClockPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_generated_clock_is_explicit_about_not_being_source_material(): void
    {
        $this->get(route('clock-practice.show'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('clock-practice.show'))
            ->assertOk()
            ->assertSeeText('Standalone demo · generated practice')
            ->assertSeeText('It is not taken from an uploaded page or a learning guide.')
            ->assertDontSee('answer-key');
    }

    public function test_clock_practice_keeps_answer_in_user_session_and_gives_progressive_hint_and_correction(): void
    {
        $user = User::factory()->create();
        $this->app->instance(ClockPracticeQuestion::class, new class extends ClockPracticeQuestion
        {
            public function random(): array
            {
                return $this->make(3, 15);
            }
        });

        $response = $this->actingAs($user)->post(route('clock-practice.create'));
        $response->assertRedirect();
        $questionUrl = $response->headers->get('Location');
        parse_str((string) parse_url($questionUrl, PHP_URL_QUERY), $query);
        $questionId = $query['question'];

        $this->get($questionUrl)->assertOk()
            ->assertSee('clock-hand-minute', false)
            ->assertSee('clock-description-'.$questionId, false)
            ->assertSeeText('What time does this clock show?')
            ->assertDontSee('The time is 3:15.')
            ->assertSee('type="radio"', false)
            ->assertDontSee('<select id="clock-answer"', false);

        $this->post(route('clock-practice.hint', $questionId))->assertRedirect();
        $this->get($questionUrl)->assertSeeText('Each number it passes is 5 minutes.');
        $this->post(route('clock-practice.hint', $questionId))->assertRedirect();
        $this->get($questionUrl)->assertSeeText('check which number the shorter hour hand has passed.');

        $this->post(route('clock-practice.answer', $questionId), ['time' => '10:45'])->assertSessionHasErrors('time');
        $this->post(route('clock-practice.answer', $questionId), ['time' => '4:15'])->assertRedirect();
        $this->get($questionUrl)->assertSeeText('Let’s work it out')
            ->assertSeeText('The long hand points to 15 minutes.')
            ->assertSeeText('partway from hour 3 toward 4')
            ->assertSeeText('The time is 3:15.')
            ->assertSeeText('Read this explanation aloud');
    }

    public function test_correct_answer_is_accepted_once_and_question_cannot_be_replayed_by_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->app->instance(ClockPracticeQuestion::class, new class extends ClockPracticeQuestion
        {
            public function random(): array
            {
                return $this->make(12, 0);
            }
        });

        $response = $this->actingAs($user)->post(route('clock-practice.create'));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $questionId = $query['question'];

        $this->post(route('clock-practice.answer', $questionId), ['time' => '12:00'])->assertRedirect();
        $this->get(route('clock-practice.show', ['question' => $questionId]))->assertSeeText('That’s right!');
        $this->post(route('clock-practice.answer', $questionId), ['time' => '12:00'])->assertStatus(409);

        $this->actingAs($other)->get(route('clock-practice.show', ['question' => $questionId]))->assertOk()->assertDontSeeText('That’s right!');
        $this->actingAs($other)->post(route('clock-practice.hint', $questionId))->assertNotFound();
    }

    public function test_invalid_answers_are_rejected_by_server(): void
    {
        $user = User::factory()->create();
        $this->app->instance(ClockPracticeQuestion::class, new class extends ClockPracticeQuestion
        {
            public function random(): array
            {
                return $this->make(1, 0);
            }
        });
        $response = $this->actingAs($user)->post(route('clock-practice.create'));
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $questionId = $query['question'];

        $this->from(route('clock-practice.show', ['question' => $questionId]))
            ->post(route('clock-practice.answer', $questionId), ['time' => '13:99'])
            ->assertSessionHasErrors('time');
    }
}
