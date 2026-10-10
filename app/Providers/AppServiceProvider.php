<?php

namespace App\Providers;

use App\Services\Learning\ConceptExtractor;
use App\Services\Learning\OpenAiConceptExtractor;
use App\Services\Learning\OpenAiTutor;
use App\Services\Learning\Tutor;
use App\Services\Ocr\MathpixOcrClient;
use App\Services\Ocr\OcrClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OcrClient::class, MathpixOcrClient::class);
        $this->app->bind(ConceptExtractor::class, OpenAiConceptExtractor::class);
        $this->app->bind(Tutor::class, OpenAiTutor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
