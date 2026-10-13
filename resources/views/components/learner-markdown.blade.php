@props(['content'])

<div {{ $attributes->class(['learner-markdown']) }} data-render-math>{!! app(\App\Support\LearnerMarkdown::class)->render((string) $content) !!}</div>
