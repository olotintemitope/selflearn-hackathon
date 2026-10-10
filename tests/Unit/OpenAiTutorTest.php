<?php

namespace Tests\Unit;

use App\Services\Learning\OpenAiTutor;
use App\Services\Learning\TutorException;
use App\Services\Learning\TutorResponseValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OpenAiTutorTest extends TestCase
{
    public function test_connection_failure_becomes_safe_timeout_state(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.endpoint', 'https://api.openai.test/v1');
        Config::set('services.openai.model', 'test-model');
        $http = new Factory;
        $http->fake(fn () => Factory::failedConnection('secret transport detail'));

        $this->expectException(TutorException::class);
        $this->expectExceptionMessage('The tutor timed out. Please try again.');

        (new OpenAiTutor($http, new TutorResponseValidator))->answer('Explain gravity', [
            ['id' => 1, 'content' => 'Gravity attracts masses.'],
        ]);
    }
}
