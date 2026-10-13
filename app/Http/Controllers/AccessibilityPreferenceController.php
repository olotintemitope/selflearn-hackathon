<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AccessibilityPreferenceController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'experience' => ['required', 'string', 'in:listen,read'],
        ]);

        $request->user()->update(['accessibility_experience' => $validated['experience']]);

        return back()->with('status', 'Your learning preference has been saved. You can change it anytime.');
    }
}
