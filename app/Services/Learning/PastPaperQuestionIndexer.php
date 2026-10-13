<?php

namespace App\Services\Learning;

use App\Models\Material;
use App\Models\PastPaperQuestion;
use Illuminate\Support\Facades\DB;

final class PastPaperQuestionIndexer
{
    public function __construct(private PastPaperQuestionParser $parser) {}

    public function index(Material $material): int
    {
        if ($material->role !== 'past_paper' || $material->status !== 'ready') {
            return 0;
        }

        $version = $material->extraction_version;
        $paperYear = $this->paperYearFromUploadLabel($material);
        $chunks = $material->chunks()
            ->where('extraction_version', $version)
            ->where('relevance_status', 'related')
            ->orderBy('page')
            ->orderBy('id')
            ->get();
        $createdOrUpdated = 0;

        DB::transaction(function () use ($material, $version, $paperYear, $chunks, &$createdOrUpdated): void {
            $currentChunkIds = $chunks->modelKeys();
            $stale = $material->pastPaperQuestions()->where('extraction_version', $version);
            if ($currentChunkIds === []) {
                $stale->delete();
            } else {
                $stale->whereNotIn('material_chunk_id', $currentChunkIds)->delete();
            }

            foreach ($chunks as $chunk) {
                $confidence = $chunk->locator['confidence'] ?? ($material->metadata['ocr_confidence'] ?? null);
                $candidates = $this->parser->parse(
                    $chunk->content,
                    is_numeric($confidence) ? (float) $confidence : null,
                );
                $questionNumbers = [];

                foreach ($candidates as $candidate) {
                    $questionNumbers[] = $candidate['question_number'];
                    $existing = PastPaperQuestion::query()->where([
                        'material_chunk_id' => $chunk->id,
                        'extraction_version' => $version,
                        'question_number' => $candidate['question_number'],
                    ])->first();
                    if ($existing?->review_status === 'owner_checked') {
                        continue;
                    }

                    PastPaperQuestion::query()->updateOrCreate([
                        'material_chunk_id' => $chunk->id,
                        'extraction_version' => $version,
                        'question_number' => $candidate['question_number'],
                    ], [
                        'material_id' => $material->id,
                        'page' => $chunk->page,
                        'paper_year' => $paperYear,
                        'prompt' => $candidate['prompt'],
                        'source_text' => $candidate['source_text'],
                        'marks' => $candidate['marks'],
                        'question_type' => $candidate['question_type'],
                        'options' => $candidate['options'] === [] ? null : $candidate['options'],
                        'expected_answer' => $candidate['expected_answer'],
                        'answer_source' => $candidate['answer_source'],
                        'review_status' => $candidate['review_status'],
                        'review_reason' => $candidate['review_reason'],
                        'confidence' => $candidate['confidence'],
                        'parser_version' => PastPaperQuestionParser::VERSION,
                    ]);
                    $createdOrUpdated++;
                }

                $stalePageQuestions = $material->pastPaperQuestions()
                    ->where('extraction_version', $version)
                    ->where('material_chunk_id', $chunk->id)
                    ->where('review_status', '!=', 'owner_checked');
                if ($questionNumbers === []) {
                    $stalePageQuestions->delete();
                } else {
                    $stalePageQuestions->whereNotIn('question_number', array_values(array_unique($questionNumbers)))->delete();
                }
            }
        });

        return $createdOrUpdated;
    }

    private function paperYearFromUploadLabel(Material $material): ?int
    {
        $yearsInTitle = $this->yearsIn($material->title);
        if (count($yearsInTitle) === 1) {
            return $yearsInTitle[0];
        }
        if (count($yearsInTitle) > 1) {
            return null;
        }

        $yearsInFilename = $this->yearsIn($material->original_filename ?? '');

        return count($yearsInFilename) === 1 ? $yearsInFilename[0] : null;
    }

    /** @return list<int> */
    private function yearsIn(string $label): array
    {
        preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/', $label, $matches);

        return array_values(array_unique(array_map('intval', $matches[0])));
    }
}
