<?php

namespace App\Providers;

use App\Services\Learning\ConceptExtractor;
use App\Services\Learning\OpenAiConceptExtractor;
use App\Services\Learning\OpenAiQuizGenerator;
use App\Services\Learning\OpenAiTutor;
use App\Services\Learning\QuizGenerator;
use App\Services\Learning\Tutor;
use App\Services\Ocr\CloudOcrClient;
use App\Services\Ocr\MaterialExtractionRouter;
use App\Services\Ocr\MathpixOcrClient;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\ProcessRunner;
use App\Services\Ocr\SymfonyProcessRunner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);
        $this->app->bind(CloudOcrClient::class, MathpixOcrClient::class);
        $this->app->bind(OcrClient::class, MaterialExtractionRouter::class);
        $this->app->bind(ConceptExtractor::class, OpenAiConceptExtractor::class);
        $this->app->bind(Tutor::class, OpenAiTutor::class);
        $this->app->bind(QuizGenerator::class, OpenAiQuizGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
