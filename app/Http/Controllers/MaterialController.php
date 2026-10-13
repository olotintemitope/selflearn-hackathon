<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMaterialRequest;
use App\Jobs\ProcessMaterial;
use App\Models\Material;
use App\Models\PastPaperMarkingSchemeMatch;
use App\Models\PastPaperQuestion;
use App\Services\Learning\PastPaperQuestionIndexer;
use App\Services\Learning\PostUploadChoiceGuide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'role' => $request->string('role')->toString(),
            'private_source_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
        ProcessMaterial::dispatch($material->id)->onQueue('materials');

        return redirect()->route('materials.show', $material)->with('status', 'Material added. We’ll prepare its concepts for your study guide.');
    }

    public function show(Request $request, Material $material, PostUploadChoiceGuide $choiceGuide)
    {
        $this->authorize('view', $material);
        $material->load(['chunks', 'learningSpace.concepts.sources', 'learningSpace.concepts.prerequisites']);
        $pastPaperQuestions = $material->role === 'past_paper' && $material->status === 'ready'
            ? $material->pastPaperQuestions()->where('extraction_version', $material->extraction_version)->orderBy('page')->orderBy('question_number')->get()
            : collect();
        $markingSchemeMatches = $material->role === 'past_paper' && $material->status === 'ready'
            ? $material->confirmedMarkingSchemes()
                ->with('markingScheme')
                ->where('paper_extraction_version', $material->extraction_version)
                ->whereHas('markingScheme', fn ($query) => $query
                    ->where('role', 'marking_scheme')
                    ->where('status', 'ready')
                    ->whereColumn('materials.extraction_version', 'past_paper_marking_scheme_matches.marking_scheme_extraction_version'))
                ->latest()
                ->get()
            : collect();
        $availableMarkingSchemes = $material->role === 'past_paper' && $material->status === 'ready'
            ? $material->learningSpace->materials()
                ->where('role', 'marking_scheme')
                ->where('status', 'ready')
                ->orderBy('title')
                ->get()
            : collect();
        $readyPastPaperQuestionCount = $pastPaperQuestions->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)->count();
        $hasSourceLinkedConcept = $material->learningSpace->concepts()
            ->whereHas('sources', fn ($query) => $query->where('material_chunks.material_id', $material->id)
                ->where('material_chunks.relevance_status', 'related'))
            ->exists();
        $sourceLinkedConcepts = $material->learningSpace->concepts
            ->filter(fn ($concept) => $concept->sources->contains(fn ($source) => (int) $source->material_id === (int) $material->id
                && (int) $source->extraction_version === (int) $material->extraction_version
                && $source->relevance_status === 'related'))
            ->values();
        $heldPastPaperQuestionCount = $pastPaperQuestions
            ->whereNotIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES)
            ->count();
        $otherReadyPaperCount = $material->role === 'past_paper' && $material->status === 'ready'
            ? $material->learningSpace->materials()
                ->where('materials.id', '!=', $material->getKey())
                ->where('role', 'past_paper')
                ->where('status', 'ready')
                ->whereHas('pastPaperQuestions', fn ($query) => $query
                    ->whereColumn('past_paper_questions.extraction_version', 'materials.extraction_version')
                    ->whereIn('review_status', PastPaperQuestion::PRACTICE_READY_STATUSES))
                ->count()
            : 0;
        $mixedReadyPaperCount = $otherReadyPaperCount + ($readyPastPaperQuestionCount > 0 ? 1 : 0);
        $otherReadyMaterialCount = in_array($material->status, ['pending', 'processing', 'failed'], true)
            ? $material->learningSpace->materials()
                ->where('materials.id', '!=', $material->getKey())
                ->where('status', 'ready')
                ->count()
            : 0;
        $choiceIntent = $request->query('learning_intent');
        if (! in_array($choiceIntent, ['practise', 'learn_first', 'unsure'], true)) {
            $choiceIntent = null;
        }
        $learningChoice = $material->role === 'past_paper' && $material->status === 'ready'
            && $choiceIntent !== null
                ? $choiceGuide->recommend(
                    $choiceIntent,
                    $readyPastPaperQuestionCount,
                    $heldPastPaperQuestionCount,
                    $sourceLinkedConcepts->count(),
                )
                : null;

        return view('materials.show', compact(
            'material', 'pastPaperQuestions', 'markingSchemeMatches', 'availableMarkingSchemes', 'readyPastPaperQuestionCount', 'heldPastPaperQuestionCount',
            'hasSourceLinkedConcept', 'sourceLinkedConcepts', 'otherReadyPaperCount', 'mixedReadyPaperCount',
            'choiceIntent', 'learningChoice', 'otherReadyMaterialCount',
        ));
    }

    public function indexPastPaperQuestions(Request $request, Material $material, PastPaperQuestionIndexer $indexer)
    {
        $this->authorize('update', $material);
        abort_unless($material->status === 'ready' && $material->role === 'past_paper', 409);

        $count = $indexer->index($material);
        $message = $count > 0
            ? $count.' possible question(s) found. Check the wording and question type against the original before practising.'
            : 'No clear question boundaries were found. The original and extracted pages are still available; nothing was turned into a question.';

        return back()->with('status', $message);
    }

    public function reviewPastPaperQuestion(Request $request, Material $material, PastPaperQuestion $question)
    {
        $this->authorize('update', $material);
        abort_unless($material->status === 'ready' && $material->role === 'past_paper', 409);
        abort_unless($question->material_id === $material->id
            && $question->extraction_version === $material->extraction_version
            && $question->review_status === 'needs_review', 404);

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:8', 'max:10000'],
            'question_type' => ['required', 'in:single_choice,multiple_select,true_false,fill_gap,structured_response'],
            'options' => ['sometimes', 'array', 'max:8'],
            'options.*' => ['nullable', 'string', 'max:2000'],
        ]);
        $choiceTypes = ['single_choice', 'multiple_select', 'true_false'];
        $options = collect(range('A', 'H'))
            ->map(fn (string $id) => ['id' => $id, 'text' => trim((string) ($validated['options'][$id] ?? ''))])
            ->filter(fn (array $option) => $option['text'] !== '')
            ->values();
        if (in_array($validated['question_type'], $choiceTypes, true) && $options->count() < 2) {
            return back()->withErrors(['options' => 'Add at least two answer choices before including this question.'])->withInput();
        }

        DB::transaction(function () use ($material, $question, $validated, $choiceTypes, $options): void {
            $lockedMaterial = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            $candidate = $lockedMaterial->pastPaperQuestions()->whereKey($question->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedMaterial->status === 'ready'
                && $lockedMaterial->role === 'past_paper'
                && $candidate->extraction_version === $lockedMaterial->extraction_version
                && $candidate->review_status === 'needs_review', 409);

            $candidate->update([
                'prompt' => trim($validated['prompt']),
                'question_type' => $validated['question_type'],
                'options' => in_array($validated['question_type'], $choiceTypes, true) ? $options->all() : null,
                // Reviewing the wording does not verify an answer key. Keep it ungraded.
                'expected_answer' => null,
                'answer_source' => 'not_detected',
                'review_status' => 'owner_checked',
                'review_reason' => null,
            ]);
        });

        return back()->with('status', 'Your checked question is ready to practise. It will not be automatically scored because its answer key has not been verified.');
    }

    public function matchMarkingScheme(Request $request, Material $material)
    {
        $this->authorize('update', $material);
        abort_unless($material->role === 'past_paper' && $material->status === 'ready', 409);

        $validated = $request->validate([
            'marking_scheme_uuid' => [
                'required',
                'uuid',
                Rule::exists('materials', 'uuid')
                    ->where('learning_space_id', $material->learning_space_id)
                    ->where('role', 'marking_scheme')
                    ->where('status', 'ready'),
            ],
            'confirm_match' => ['accepted'],
        ]);

        $scheme = $material->learningSpace->materials()->where('uuid', $validated['marking_scheme_uuid'])->firstOrFail();
        abort_unless($scheme->role === 'marking_scheme' && $scheme->status === 'ready', 404);

        PastPaperMarkingSchemeMatch::query()->firstOrCreate([
            'paper_material_id' => $material->id,
            'marking_scheme_material_id' => $scheme->id,
            'paper_extraction_version' => $material->extraction_version,
            'marking_scheme_extraction_version' => $scheme->extraction_version,
        ], [
            'confirmed_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Answer key linked to this paper. It will not affect practice scores until each question is checked against the key.');
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
        $metadata = $material->metadata ?? [];
        unset($metadata['failed_stage']);
        $material->update(['status' => 'pending', 'failure_message' => null, 'metadata' => $metadata]);
        ProcessMaterial::dispatch($material->id)->onQueue('materials');

        return back()->with('status', 'Processing queued again.');
    }

    public function rebuildGuide(Request $request, Material $material)
    {
        $this->authorize('update', $material);
        abort_unless($material->status === 'ready', 409);
        $hasRelatedPages = $material->chunks()->where('relevance_status', 'related')->exists();
        $hasSourceLinkedConcept = $material->learningSpace->concepts()
            ->whereHas('sources', fn ($query) => $query
                ->where('material_chunks.material_id', $material->id)
                ->where('material_chunks.relevance_status', 'related'))
            ->exists();
        abort_unless($hasRelatedPages && ! $hasSourceLinkedConcept, 409);

        ProcessMaterial::dispatch($material->id, true)->onQueue('materials');

        return back()->with('status', 'We’re rebuilding the study guide from your checked pages. Your original and confirmed readings are kept.');
    }

    public function resolveRelevance(Request $request, Material $material)
    {
        $this->authorize('update', $material);
        $validated = $request->validate([
            'chunk_id' => ['required', 'integer'],
            'decision' => ['required', 'in:related,separate,recheck'],
        ]);
        $chunk = $material->chunks()->whereKey($validated['chunk_id'])->firstOrFail();
        if ($validated['decision'] === 'recheck') {
            abort_unless($chunk->relevance_status === 'pending', 409);
            ProcessMaterial::dispatch($material->id, true)->onQueue('materials');

            return back()->with('status', 'We’re checking this saved page against your study goal.');
        }
        abort_unless($chunk->relevance_status === 'possibly_related', 409);

        if ($validated['decision'] === 'related') {
            $locator = $chunk->locator ?? [];
            $faces = $locator['clock_diagrams']['faces'] ?? [];
            if (is_array($faces) && $faces !== []) {
                $rules = ['clock_readings' => ['required', 'array', 'size:'.count($faces)]];
                foreach ($faces as $face) {
                    $index = $face['index'] ?? null;
                    if (! is_int($index)) {
                        abort(422, 'Clock analysis data is invalid. Keep this page separate and contact support.');
                    }
                    $rules['clock_readings.'.$index] = ['required', 'date_format:H:i'];
                }
                $readings = $request->validate($rules)['clock_readings'];
                if (array_diff(array_map('strval', array_column($faces, 'index')), array_map('strval', array_keys($readings))) !== []) {
                    abort(422, 'Confirm every detected clock before including this page.');
                }

                $confirmedFaces = [];
                foreach ($faces as $face) {
                    $face['confirmed_time'] = $readings[$face['index']];
                    $face['confirmation'] = 'learner_checked_against_original';
                    $confirmedFaces[] = $face;
                }
                $locator['ocr_content'] = $locator['ocr_content'] ?? $chunk->content;
                $locator['clock_diagrams']['faces'] = $confirmedFaces;
                $locator['clock_diagrams']['status'] = 'learner_confirmed';
                $locator['clock_diagrams']['confirmed_at'] = now()->toISOString();
                $lines = array_map(fn (array $face): string => 'Clock face '.$face['index'].' shows '.$face['confirmed_time'], $confirmedFaces);
                $chunk->update([
                    'content' => $locator['ocr_content']."\n\nLearner-confirmed local clock diagram readings (not Mathpix OCR): ".implode('; ', $lines).'.',
                    'locator' => $locator,
                    'relevance_status' => 'related',
                ]);
            } else {
                $chunk->update(['relevance_status' => 'related']);
            }
            ProcessMaterial::dispatch($material->id, true)->onQueue('materials');
            $message = 'Page included. We’re adding its concepts to this study guide.';
        } else {
            $chunk->update(['relevance_status' => 'separate']);
            $message = 'Page kept with the original material but left out of this study guide.';
        }

        return back()->with('status', $message);
    }
}
