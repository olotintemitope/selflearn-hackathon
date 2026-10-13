@props(['sources', 'conceptId'])

@php
    $faces = collect($sources)->flatMap(function ($source) {
        $diagram = $source->locator['clock_diagrams'] ?? null;
        if (! is_array($diagram) || ($diagram['status'] ?? null) !== 'learner_confirmed') {
            return collect();
        }

        return collect($diagram['faces'] ?? [])
            ->filter(fn ($face) => is_array($face)
                && is_numeric($face['index'] ?? null)
                && (int) $face['index'] > 0
                && is_string($face['confirmed_time'] ?? null)
                && preg_match('/^(?:0[1-9]|1[0-2]):[0-5][0-9]$/', $face['confirmed_time']) === 1)
            ->map(fn ($face) => [
                'chunk_id' => $source->id,
                'page' => $source->page,
                'index' => (int) $face['index'],
                'time' => $face['confirmed_time'],
            ]);
    })->unique(fn ($face) => $face['chunk_id'].':'.$face['index'])->values();
@endphp

@if ($faces->isNotEmpty())
    <section class="confirmed-clock-diagrams" aria-labelledby="confirmed-clock-heading-{{ $conceptId }}">
        <h4 id="confirmed-clock-heading-{{ $conceptId }}">Clock examples</h4>
        <p>These clock drawings recreate the times you checked against the original page. They are not images from the source.</p>
        <div class="confirmed-clock-grid">
            @foreach ($faces as $face)
                @php
                    [$hour, $minute] = array_map('intval', explode(':', $face['time']));
                    $minuteAngle = $minute * 6;
                    $hourAngle = (($hour % 12) + ($minute / 60)) * 30;
                    $minuteRadians = deg2rad($minuteAngle);
                    $hourRadians = deg2rad($hourAngle);
                    $minuteX = round(100 + sin($minuteRadians) * 70, 2);
                    $minuteY = round(100 - cos($minuteRadians) * 70, 2);
                    $hourX = round(100 + sin($hourRadians) * 48, 2);
                    $hourY = round(100 - cos($hourRadians) * 48, 2);
                    $clockId = 'study-clock-'.$conceptId.'-'.$face['chunk_id'].'-'.$face['index'];
                @endphp
                <figure class="confirmed-clock-figure">
                    <svg class="confirmed-clock-face" viewBox="0 0 200 200" role="img" aria-labelledby="{{ $clockId }}-title {{ $clockId }}-description">
                        <title id="{{ $clockId }}-title">Clock {{ $face['index'] }} shows {{ $face['time'] }}</title>
                        <desc id="{{ $clockId }}-description">An analogue clock with numbers one to twelve and hands set to {{ $face['time'] }}.</desc>
                        <circle cx="100" cy="100" r="91" class="confirmed-clock-outline" />
                        @foreach (range(0, 11) as $tick)
                            <line x1="100" y1="13" x2="100" y2="22" class="confirmed-clock-tick" transform="rotate({{ $tick * 30 }} 100 100)" />
                        @endforeach
                        @foreach ([1 => [145, 31], 2 => [160, 48], 3 => [168, 103], 4 => [160, 151], 5 => [145, 169], 6 => [100, 178], 7 => [55, 169], 8 => [40, 151], 9 => [32, 103], 10 => [40, 48], 11 => [55, 31], 12 => [100, 22]] as $number => [$x, $y])
                            <text x="{{ $x }}" y="{{ $y }}" class="confirmed-clock-number" text-anchor="middle" dominant-baseline="middle">{{ $number }}</text>
                        @endforeach
                        <line x1="100" y1="100" x2="{{ $minuteX }}" y2="{{ $minuteY }}" class="confirmed-clock-minute-hand" />
                        <line x1="100" y1="100" x2="{{ $hourX }}" y2="{{ $hourY }}" class="confirmed-clock-hour-hand" />
                        <circle cx="100" cy="100" r="4" class="confirmed-clock-pin" />
                    </svg>
                    <figcaption>Clock {{ $face['index'] }} · {{ $face['time'] }}@if($face['page']) · page {{ $face['page'] }}@endif</figcaption>
                </figure>
            @endforeach
        </div>
    </section>
@endif
