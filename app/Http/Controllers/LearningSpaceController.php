<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLearningSpaceRequest;

class LearningSpaceController extends Controller
{
    public function store(StoreLearningSpaceRequest $request)
    {
        $request->user()->learningSpaces()->create($request->validated());

        return redirect()->route('materials.index')->with('status', 'Study space created. Add materials whenever you’re ready.');
    }
}
