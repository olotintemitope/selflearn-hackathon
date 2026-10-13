<?php

namespace App\Http\Controllers;

use App\Services\Accessibility\OpenAiSpeechGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReadAloudController
{
    public function __invoke(Request $request, OpenAiSpeechGenerator $speech)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
            'language' => ['required', 'string', 'in:en,ha,ig,yo'],
        ]);

        $supportedLanguages = config('services.openai.tts_supported_languages', ['en']);
        if (! in_array($validated['language'], $supportedLanguages, true)) {
            $languageNames = ['ha' => 'Hausa', 'ig' => 'Igbo', 'yo' => 'Yoruba'];

            return response()->json([
                'message' => 'Audio is not available in '.($languageNames[$validated['language']] ?? 'this language').' yet. You can still read it on screen.',
            ], 422);
        }

        try {
            $audio = $speech->generate(trim($validated['text']), $validated['language'], $request->user()->id);
        } catch (Throwable $exception) {
            Log::warning('Learner speech generation unavailable.', [
                'user_id' => $request->user()->id,
                'exception_type' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'Audio could not be prepared right now. The full text remains available on screen. Please try again.',
            ], 503);
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
