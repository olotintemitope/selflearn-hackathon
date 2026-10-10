<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMaterialRequest;
use App\Jobs\ProcessMaterial;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $spaces = $request->user()->learningSpaces()
            ->with(['materials' => fn ($query) => $query->latest()])->get();

        return view('materials.index', compact('spaces'));
    }

    public function store(StoreMaterialRequest $request)
    {
        $file = $request->file('material');
        $path = $file->storeAs('materials/'.$request->user()->id.'/'.Str::uuid(), Str::uuid().'.'.$file->extension(), 'local');
        $space = $request->user()->learningSpaces()->findOrFail($request->integer('learning_space_id'));
        $material = $space->materials()->create([
            'title' => $request->string('title')->toString(),
            'private_source_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
        ProcessMaterial::dispatch($material->id);

        return redirect()->route('materials.show', $material)->with('status', 'Upload saved privately and queued for processing.');
    }

    public function show(Request $request, Material $material)
    {
        $this->authorize('view', $material);
        $material->load(['chunks', 'learningSpace.concepts.sources', 'learningSpace.concepts.prerequisites']);

        return view('materials.show', compact('material'));
    }

    public function download(Request $request, Material $material)
    {
        $this->authorize('view', $material);

        return Storage::disk('local')->download($material->private_source_path, $material->original_filename);
    }

    public function retry(Request $request, Material $material)
    {
        $this->authorize('update', $material);
        abort_unless($material->status === 'failed', 409);
        $material->update(['status' => 'pending', 'failure_message' => null]);
        ProcessMaterial::dispatch($material->id);

        return back()->with('status', 'Processing queued again.');
    }
}
