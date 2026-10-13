<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_chunk_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('extraction_version');
            $table->unsignedInteger('page')->nullable();
            $table->string('question_number', 24);
            $table->longText('prompt');
            $table->string('question_type', 32);
            $table->json('options')->nullable();
            $table->longText('expected_answer')->nullable();
            $table->string('answer_source', 40)->default('not_detected');
            $table->string('review_status', 24);
            $table->string('review_reason')->nullable();
            $table->decimal('confidence', 4, 3);
            $table->string('parser_version', 40);
            $table->timestamps();

            $table->unique(['material_chunk_id', 'extraction_version', 'question_number'], 'past_paper_question_source_unique');
            $table->index(['material_id', 'extraction_version', 'review_status'], 'past_paper_questions_material_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_paper_questions');
    }
};
