<?php

namespace App\Services\Learning;

interface Tutor
{
    public function answer(string $question, array $chunks, ?string $language = null): array;
}
