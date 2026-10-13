<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReadAloudTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_learner_can_generate_ai_audio_for_question_text(): void
    {
        config(['cache.default' => 'array']);
        config([
            'services.openai.api_key' => 'test-token',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
            'services.openai.tts_model' => 'gpt-4o-mini-tts',
            'services.openai.tts_voice' => 'marin',
        ]);
        Http::fake(['api.openai.test/v1/audio/speech' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('read-aloud'), ['text' => 'Which answer describes conservation of energy?', 'language' => 'en'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertContent('fake-mp3-bytes');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.test/v1/audio/speech'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['model'] === 'gpt-4o-mini-tts'
            && $request['voice'] === 'marin'
            && $request['input'] === 'Which answer describes conservation of energy?'
            && $request['response_format'] === 'mp3'
            && str_contains($request['instructions'], 'English'));
    }

    public function test_repeating_the_same_text_reuses_audio_for_the_same_learner(): void
    {
        config([
            'cache.default' => 'array',
            'services.openai.api_key' => 'test-token',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
        ]);
        Http::fake(['api.openai.test/v1/audio/speech' => Http::response('repeatable-mp3', 200, ['Content-Type' => 'audio/mpeg'])]);
        $learner = User::factory()->create();
        $payload = ['text' => 'The same question, read again.', 'language' => 'en'];

        $this->actingAs($learner)->postJson(route('read-aloud'), $payload)->assertOk()->assertContent('repeatable-mp3');
        $this->postJson(route('read-aloud'), $payload)->assertOk()->assertContent('repeatable-mp3');

        Http::assertSentCount(1);
    }

    public function test_cached_audio_is_isolated_between_learners(): void
    {
        config([
            'cache.default' => 'array',
            'services.openai.api_key' => 'test-token',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
        ]);
        Http::fake(['api.openai.test/v1/audio/speech' => Http::sequence()
            ->push('learner-one-audio', 200, ['Content-Type' => 'audio/mpeg'])
            ->push('learner-two-audio', 200, ['Content-Type' => 'audio/mpeg'])]);
        $payload = ['text' => 'Shared text must not share private audio.', 'language' => 'en'];

        $this->actingAs(User::factory()->create())->postJson(route('read-aloud'), $payload)->assertOk()->assertContent('learner-one-audio');
        $this->actingAs(User::factory()->create())->postJson(route('read-aloud'), $payload)->assertOk()->assertContent('learner-two-audio');

        Http::assertSentCount(2);
    }

    public function test_speech_generation_rejects_oversized_or_unsupported_requests_before_provider_call(): void
    {
        config(['services.openai.api_key' => 'test-token']);
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson(route('read-aloud'), ['text' => str_repeat('a', 4097), 'language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');

        $this->postJson(route('read-aloud'), ['text' => 'Hello', 'language' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('language');

        Http::assertNothingSent();
    }

    public function test_speech_in_unlisted_languages_gets_a_plain_text_fallback_without_provider_call(): void
    {
        config(['services.openai.api_key' => 'test-token']);
        Http::fake();
        $learner = User::factory()->create();

        foreach (['ha' => 'Hausa', 'ig' => 'Igbo', 'yo' => 'Yoruba'] as $language => $name) {
            $this->actingAs($learner)
                ->postJson(route('read-aloud'), ['text' => 'Read this tutor reply.', 'language' => $language])
                ->assertStatus(422)
                ->assertJsonPath('message', "Audio is not available in {$name} yet. You can still read it on screen.");
        }

        Http::assertNothingSent();
    }

    public function test_provider_failure_returns_a_safe_fallback_message(): void
    {
        config([
            'services.openai.api_key' => 'test-token',
            'services.openai.endpoint' => 'https://api.openai.test/v1',
        ]);
        Http::fake(['api.openai.test/v1/audio/speech' => Http::response(['error' => ['message' => 'private provider detail']], 429)]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('read-aloud'), ['text' => 'Read this exam question.', 'language' => 'en'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Audio could not be prepared right now. The full text remains available on screen. Please try again.')
            ->assertDontSee('private provider detail');
    }

    public function test_guests_cannot_request_speech_audio(): void
    {
        $this->postJson(route('read-aloud'), ['text' => 'A question.', 'language' => 'en'])
            ->assertUnauthorized();
    }
}
