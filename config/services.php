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
    ],

    'extraction' => [
        'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),
        'pdfinfo_binary' => env('PDFINFO_BINARY', 'pdfinfo'),
        'magick_binary' => env('MAGICK_BINARY', 'magick'),
        'tesseract_binary' => env('TESSERACT_BINARY', 'tesseract'),
        'tesseract_language' => env('TESSERACT_LANGUAGE', 'eng'),
        'process_timeout' => (int) env('EXTRACTION_PROCESS_TIMEOUT', 30),
        'max_pdf_pages' => (int) env('EXTRACTION_MAX_PDF_PAGES', 50),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'endpoint' => env('OPENAI_API_ENDPOINT', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-6-astra'),
    ],

];
