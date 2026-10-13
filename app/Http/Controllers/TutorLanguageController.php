<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTutorLanguageRequest;
use App\Models\LearningSpace;

class TutorLanguageController extends Controller
{
    public function update(UpdateTutorLanguageRequest $request, LearningSpace $learningSpace)
    {
        $learningSpace->update($request->validated());

        return back()->with('status', 'Tutor language updated. This applies to new tutor replies only.');
    }
}
