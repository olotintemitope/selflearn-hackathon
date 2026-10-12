<x-layouts.app title="Clock-reading practice: SelfLearn">
    @vite(['resources/css/clock-practice.css', 'resources/js/clock-practice.js'])
    <header class="hero compact">
        <p class="eyebrow">Standalone demo · generated practice</p>
        <h1>Read the clock</h1>
        <p>This is a new practice clock, generated for this activity. It is not taken from an uploaded page or a learning guide.</p>
    </header>

    @if (! $question)
        <section class="clock-panel" aria-labelledby="clock-start-heading">
            <h2 id="clock-start-heading">Practise telling the time</h2>
            <p>Look at the hands, then choose the time. You can ask for a hint before you answer.</p>
            <form method="POST" action="{{ route('clock-practice.create') }}">@csrf<button>Make a practice clock</button></form>
        </section>
    @else
        <section class="clock-practice-layout" aria-label="Clock-reading practice">
            <article class="clock-panel" aria-labelledby="clock-question-heading">
                <p class="eyebrow">New practice question</p>
                <h2 id="clock-question-heading">What time does this clock show?</h2>
                <figure class="clock-figure">
                    @php($minuteHandNumber = $question['minute'] === 0 ? 12 : intdiv($question['minute'], 5))
                    @php($nextHour = $question['hour'] === 12 ? 1 : $question['hour'] + 1)
                    @php($hourHandPosition = $question['minute'] === 0 ? 'at '.$question['hour'] : 'between '.$question['hour'].' and '.$nextHour)
                    <svg class="practice-clock" viewBox="0 0 200 200" role="img" aria-labelledby="clock-title-{{ $questionId }} clock-description-{{ $questionId }}">
                        <title id="clock-title-{{ $questionId }}">Generated analogue clock</title>
                        <desc id="clock-description-{{ $questionId }}">A generated clock face with numbers one to twelve. The long minute hand points to {{ $minuteHandNumber }}. The short hour hand is {{ $hourHandPosition }}. Read the hands to find the time.</desc>
                        <circle cx="100" cy="100" r="91" class="clock-face" />
                        @foreach (range(0, 59) as $tick)
                            <line x1="100" y1="13" x2="100" y2="{{ $tick % 5 === 0 ? 22 : 17 }}" class="clock-tick {{ $tick % 5 === 0 ? 'clock-tick-major' : '' }}" transform="rotate({{ $tick * 6 }} 100 100)" />
                        @endforeach
                        @foreach ([1 => [145, 22], 2 => [168, 45], 3 => [177, 103], 4 => [168, 153], 5 => [145, 177], 6 => [100, 183], 7 => [55, 177], 8 => [32, 153], 9 => [23, 103], 10 => [32, 45], 11 => [55, 22], 12 => [100, 16]] as $number => [$x, $y])
                            <text x="{{ $x }}" y="{{ $y }}" class="clock-number" text-anchor="middle" dominant-baseline="middle">{{ $number }}</text>
                        @endforeach
                        <line x1="100" y1="100" x2="{{ $question['minute_hand']['x'] }}" y2="{{ $question['minute_hand']['y'] }}" class="clock-hand clock-hand-minute" />
                        <line x1="100" y1="100" x2="{{ $question['hour_hand']['x'] }}" y2="{{ $question['hour_hand']['y'] }}" class="clock-hand clock-hand-hour" />
                        <circle cx="100" cy="100" r="4" class="clock-pin" />
                    </svg>
                    <figcaption>Generated clock for practice. It is not copied from a source document.</figcaption>
                </figure>

                @if ($question['hint_level'] > 0 && $question['result'] === null)
                    <p class="clock-hint" role="status">
                        @if ($question['hint_level'] === 1)
                            The long hand counts minutes. Each number it passes is 5 minutes.
                        @else
                            First read the long minute hand, then check which number the shorter hour hand has passed.
                        @endif
                    </p>
                @endif

                @if ($question['result'])
                    <div class="clock-feedback" role="status" aria-live="polite">
                        <h3>{{ $question['result']['correct'] ? 'That’s right!' : 'Let’s work it out' }}</h3>
                        <p id="clock-learning-text">{{ $question['result']['feedback'] }}</p>
                        <div class="clock-read-aloud" data-read-aloud>
                            <label for="clock-voice">Read this explanation aloud <span class="optional-label">English voice</span>
                                <select id="clock-voice" data-voice-choice aria-describedby="voice-help">
                                    <option value="">Default English voice</option>
                                </select>
                            </label>
                            <p id="voice-help" class="muted">Speech is optional. If your browser has no English voice, the explanation stays available to read.</p>
                            <div class="clock-actions">
                                <button type="button" data-speech-play>Read aloud</button>
                                <button type="button" class="clock-secondary" data-speech-pause disabled>Pause</button>
                                <button type="button" class="clock-secondary" data-speech-stop disabled>Stop speech</button>
                            </div>
                            <p class="muted" data-speech-status role="status" aria-live="polite">Read-aloud is optional.</p>
                        </div>
                    </div>
                @else
                    <div class="clock-actions">
                        <form method="POST" action="{{ route('clock-practice.hint', $questionId) }}">@csrf<button type="submit" class="clock-secondary">{{ $question['hint_level'] ? 'Another hint' : 'Give me a hint' }}</button></form>
                        @if ($question['hint_level'] >= 2)<p class="muted">Use the hint, then have a go. You can answer whenever you’re ready.</p>@endif
                    </div>
                    <form class="clock-answer-form" method="POST" action="{{ route('clock-practice.answer', $questionId) }}">
                        @csrf
                        <fieldset class="clock-choice-group" aria-describedby="clock-answer-help{{ $errors->has('time') ? ' clock-answer-error' : '' }}">
                            <legend>Choose the time</legend>
                            <div class="clock-choice-grid">
                                @foreach ($question['options'] as $option)
                                    <label class="clock-time-option">
                                        <input type="radio" name="time" value="{{ $option }}" required @checked(old('time') === $option) @error('time') aria-invalid="true" @enderror>
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <p id="clock-answer-help" class="muted">Choose one of the quarter-hour times.</p>
                        @error('time')<p class="clock-field-error" id="clock-answer-error">{{ $message }}</p>@enderror
                        <button type="submit">Check my answer</button>
                    </form>
                @endif
            </article>
            <aside class="clock-teaching" aria-labelledby="clock-how-heading">
                <h2 id="clock-how-heading">How to read it</h2>
                <ol>
                    <li>The long hand shows the minutes.</li>
                    <li>Count 5 minutes for each number the long hand passes.</li>
                    <li>The short hand shows which hour it is in.</li>
                </ol>
                <p>These clocks practise quarter hours. Read the long hand first, then the short hand.</p>
            </aside>
        </section>
        @if ($question['result'])
            <form method="POST" action="{{ route('clock-practice.create') }}" class="clock-next">@csrf<button>Try another clock</button></form>
        @endif
    @endif
</x-layouts.app>
