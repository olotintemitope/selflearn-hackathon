<?php

namespace App\Http\Controllers;

use App\Services\Learning\ClockPracticeQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClockPracticeController extends Controller
{
    public function index(Request $request): View
    {
        $questionId = $request->query('question');
        $question = is_string($questionId) ? $request->session()->get($this->key($questionId, $request->user()->id)) : null;

        return view('clock-practice.show', [
            'questionId' => is_array($question) ? $questionId : null,
            'question' => $question,
        ]);
    }

    public function create(Request $request, ClockPracticeQuestion $questions): RedirectResponse
    {
        $question = $questions->random();
        $question['hint_level'] = 0;
        $question['result'] = null;
        $questionId = (string) Str::uuid();
        $request->session()->put($this->key($questionId, $request->user()->id), $question);

        return redirect()->route('clock-practice.show', ['question' => $questionId]);
    }

    public function hint(Request $request, string $question): RedirectResponse
    {
        $state = $this->sessionQuestion($request, $question);
        abort_if($state['result'] !== null, 409);
        $state['hint_level'] = min(2, $state['hint_level'] + 1);
        $request->session()->put($this->key($question, $request->user()->id), $state);

        return redirect()->route('clock-practice.show', ['question' => $question]);
    }

    public function answer(Request $request, string $question): RedirectResponse
    {
        $state = $this->sessionQuestion($request, $question);
        abort_if($state['result'] !== null, 409);

        $validated = $request->validate([
            'time' => ['required', 'string', Rule::in($state['options'])],
        ]);
        [$submittedHour, $submittedMinute] = array_map('intval', explode(':', trim($validated['time'])));
        $submitted = sprintf('%d:%02d', $submittedHour, $submittedMinute);
        $correct = $submitted === $state['answer'];
        $hour = $state['hour'];
        $minute = $state['minute'];
        $state['result'] = [
            'correct' => $correct,
            'feedback' => $correct
                ? 'That is right. The long minute hand shows '.$this->minutePhrase($minute).', and the short hour hand shows '.$hour.'.'
                : 'Not quite. The long hand points to '.$this->minutePhrase($minute).'. '.($minute === 0
                    ? 'The short hand points to hour '.$hour.'.'
                    : 'The short hand has moved partway from hour '.$hour.' toward '.$this->nextHour($hour).', so it is still in hour '.$hour.'.').' The time is '.$state['answer'].'.',
        ];
        $request->session()->put($this->key($question, $request->user()->id), $state);

        return redirect()->route('clock-practice.show', ['question' => $question]);
    }

    private function sessionQuestion(Request $request, string $questionId): array
    {
        abort_unless(Str::isUuid($questionId), 404);
        $state = $request->session()->get($this->key($questionId, $request->user()->id));
        abort_unless(is_array($state) && isset($state['hour'], $state['minute'], $state['answer'], $state['hint_level']), 404);

        return $state;
    }

    private function key(string $questionId, int $userId): string
    {
        return 'clock_practice.'.$userId.'.'.$questionId;
    }

    private function minutePhrase(int $minute): string
    {
        return match ($minute) {
            0 => '0 minutes',
            15 => '15 minutes',
            30 => '30 minutes',
            45 => '45 minutes',
            default => $minute.' minutes',
        };
    }

    private function nextHour(int $hour): int
    {
        return $hour === 12 ? 1 : $hour + 1;
    }
}
