<?php

namespace App\Services\Ocr;

interface ProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, ?string $input = null, int $timeout = 30): ProcessResult;
}
