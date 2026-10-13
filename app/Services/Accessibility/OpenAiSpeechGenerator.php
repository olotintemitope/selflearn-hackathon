<?php

namespace App\Services\Accessibility;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class OpenAiSpeechGenerator
{
    public function __construct(private readonly Factory $http) {}

    public function generate(string $text, string $language, int|string $learnerId): string
    {
        $apiKey = config('services.openai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('Speech generation is not configured.');
        }

        $languageNames = ['en' => 'English', 'ha' => 'Hausa', 'ig' => 'Igbo', 'yo' => 'Yoruba'];
        $languageName = $languageNames[$language] ?? 'the language used in the text';
        $model = config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $voice = config('services.openai.tts_voice', 'marin');
        $instructions = "Read the supplied learner-facing text clearly and naturally in {$languageName}. Do not add, omit, or explain anything.";
        $cacheKey = 'learner-speech:v1:'.hash('sha256', json_encode([
            'learner' => (string) $learnerId,
            'text' => $text,
            'language' => $language,
            'model' => $model,
            'voice' => $voice,
            'instructions' => $instructions,
            'format' => 'mp3',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $encoded = Cache::remember($cacheKey, now()->addDays(max(1, (int) config('services.openai.tts_cache_days', 30))), function () use ($apiKey, $model, $voice, $text, $instructions): string {
            $response = $this->http->withToken($apiKey)->accept('audio/mpeg')->timeout(45)
                ->post(rtrim(config('services.openai.endpoint'), '/').'/audio/speech', [
                    'model' => $model,
                    'voice' => $voice,
                    'input' => $text,
                    'instructions' => $instructions,
                    'response_format' => 'mp3',
                ]);

            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'audio/')) {
                throw new RuntimeException('Speech generation failed.');
            }

            return base64_encode($response->body());
        });

        $audio = base64_decode($encoded, true);
        if (! is_string($audio) || $audio === '') {
            Cache::forget($cacheKey);

            throw new RuntimeException('Cached speech audio is invalid.');
        }

        return $audio;
    }
}
