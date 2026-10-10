<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $command, ?string $input = null, int $timeout = 30): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        if ($input !== null) {
            $process->setInput($input);
        }

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessResult(false, '', 'Local extraction timed out.');
        } catch (Throwable) {
            return new ProcessResult(false, '', 'Local extraction could not start.');
        }

        return new ProcessResult($process->isSuccessful(), $process->getOutput(), $process->getErrorOutput());
    }
}
