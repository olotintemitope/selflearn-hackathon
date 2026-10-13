<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_verified_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('past_paper_question_id')->constrained('past_paper_questions')->cascadeOnDelete();
            $table->foreignId('marking_scheme_match_id')->constrained('past_paper_marking_scheme_matches')->cascadeOnDelete();
            $table->foreignId('marking_scheme_chunk_id')->constrained('material_chunks')->cascadeOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('paper_extraction_version');
            $table->unsignedInteger('marking_scheme_extraction_version');
            $table->unsignedInteger('confirmation_version');
            $table->unsignedInteger('source_page');
            $table->json('option_ids');
            $table->text('answer_excerpt');
            $table->timestamps();

            $table->index([
                'past_paper_question_id',
                'marking_scheme_match_id',
                'paper_extraction_version',
                'marking_scheme_extraction_version',
            ], 'past_paper_verified_answer_version_idx');
            $table->index(['past_paper_question_id', 'created_at'], 'past_paper_verified_answer_history_idx');
            $table->unique(['past_paper_question_id', 'confirmation_version'], 'past_paper_verified_answer_sequence_unique');
            $table->index(['past_paper_question_id', 'paper_extraction_version'], 'past_paper_verified_answer_question_version_idx');
        });

        Schema::table('past_paper_attempts', function (Blueprint $table): void {
            $table->foreignUuid('verified_answer_id')->nullable()
                ->after('marking_basis')
                ->constrained('past_paper_verified_answers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_answer_id');
        });

        Schema::dropIfExists('past_paper_verified_answers');
    }
};
