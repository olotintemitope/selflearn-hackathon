<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const UUID_COLUMNS = [
        'assessments' => ['id'],
        'assessment_questions' => ['id', 'assessment_id'],
        'assessment_attempts' => ['id', 'assessment_id'],
        'assessment_answers' => ['id', 'assessment_attempt_id', 'assessment_question_id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assessments') || ! $this->hasLegacyIntegerIdentifiers()) {
            return;
        }

        $assessmentIds = $this->idMap('assessments');
        $questionIds = $this->idMap('assessment_questions');
        $attemptIds = $this->idMap('assessment_attempts');
        $answerIds = $this->idMap('assessment_answers');

        // Existing installations may have these four tables created before the
        // UUID migration was corrected. Disable constraints only while their
        // identifiers and matching references are converted in place.
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::UUID_COLUMNS as $table => $columns) {
                Schema::table($table, function ($blueprint) use ($columns) {
                    foreach ($columns as $column) {
                        $blueprint->uuid($column)->change();
                    }
                });
            }

            $this->replaceReferences('assessment_questions', 'assessment_id', $assessmentIds);
            $this->replaceReferences('assessment_attempts', 'assessment_id', $assessmentIds);
            $this->replaceReferences('assessment_answers', 'assessment_attempt_id', $attemptIds);
            $this->replaceReferences('assessment_answers', 'assessment_question_id', $questionIds);

            $this->replaceIdentifiers('assessments', $assessmentIds);
            $this->replaceIdentifiers('assessment_questions', $questionIds);
            $this->replaceIdentifiers('assessment_attempts', $attemptIds);
            $this->replaceIdentifiers('assessment_answers', $answerIds);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        // UUIDs are assigned during this data-preserving repair. Reverting to
        // integer keys would require another identity remap and break external
        // references, so this corrective migration is intentionally irreversible.
    }

    private function hasLegacyIntegerIdentifiers(): bool
    {
        foreach (self::UUID_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! in_array(strtolower(Schema::getColumnType($table, $column)), ['char', 'varchar', 'string', 'uuid'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function idMap(string $table): array
    {
        return DB::table($table)->pluck('id')->mapWithKeys(
            fn ($id) => [(string) $id => (string) Str::uuid()]
        )->all();
    }

    /** @param array<string, string> $ids */
    private function replaceReferences(string $table, string $column, array $ids): void
    {
        foreach ($ids as $old => $new) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }
    }

    /** @param array<string, string> $ids */
    private function replaceIdentifiers(string $table, array $ids): void
    {
        foreach ($ids as $old => $new) {
            DB::table($table)->where('id', $old)->update(['id' => $new]);
        }
    }
};
