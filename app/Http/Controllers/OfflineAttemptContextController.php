<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineAttemptContextController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user_id' => (string) $request->user()->getAuthIdentifier(),
            'csrf_token' => csrf_token(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
