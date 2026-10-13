<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mathpix' => [
        'app_id' => env('MATHPIX_APP_ID'),
        'app_key' => env('MATHPIX_APP_KEY'),
        'image_endpoint' => env('MATHPIX_IMAGE_ENDPOINT', 'https://api.mathpix.com/v3/text'),
        'pdf_endpoint' => env('MATHPIX_PDF_ENDPOINT', 'https://api.mathpix.com/v3/pdf'),
        'pdf_max_poll_attempts' => (int) env('MATHPIX_PDF_MAX_POLL_ATTEMPTS', 45),
        'pdf_poll_interval_ms' => (int) env('MATHPIX_PDF_POLL_INTERVAL_MS', 1000),
        'confidence_rate_review_threshold' => (float) env('MATHPIX_CONFIDENCE_RATE_REVIEW_THRESHOLD', 0.75),
    ],

    'extraction' => [
        'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),
        'pdfinfo_binary' => env('PDFINFO_BINARY', 'pdfinfo'),
        'pdfimages_binary' => env('PDFIMAGES_BINARY', 'pdfimages'),
        'pdfimages_page_batch' => (int) env('PDFIMAGES_PAGE_BATCH', 10),
        'page_batch_size' => (int) env('MATERIAL_PAGE_BATCH_SIZE', 5),
        'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'magick_binary' => env('MAGICK_BINARY', 'magick'),
        'process_timeout' => (int) env('EXTRACTION_PROCESS_TIMEOUT', 30),
        'max_pdf_pages' => (int) env('EXTRACTION_MAX_PDF_PAGES', 500),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'endpoint' => env('OPENAI_API_ENDPOINT', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'tts_voice' => env('OPENAI_TTS_VOICE', 'marin'),
        'tts_cache_days' => (int) env('OPENAI_TTS_CACHE_DAYS', 30),
        'tts_supported_languages' => ['en'],
    ],

];
