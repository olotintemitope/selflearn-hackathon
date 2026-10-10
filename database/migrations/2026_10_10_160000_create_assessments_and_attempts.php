<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('learning_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->string('prompt_version', 40);
            $table->timestamps();
            $table->index(['learning_space_id', 'concept_id']);
        });

        Schema::create('assessment_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->json('options');
            $table->string('correct_option_id', 8);
            $table->text('explanation');
            $table->json('source_refs');
            $table->timestamps();
            $table->index(['concept_id', 'created_at']);
        });

        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_attempt_uuid');
            $table->unsignedSmallInteger('correct_count');
            $table->unsignedSmallInteger('question_count');
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['user_id', 'client_attempt_uuid']);
            $table->index(['user_id', 'assessment_id', 'submitted_at']);
        });

        Schema::create('assessment_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->foreignUuid('assessment_question_id')->constrained()->cascadeOnDelete();
            $table->string('selected_option_id', 8);
            $table->boolean('correct');
            $table->unique(['assessment_attempt_id', 'assessment_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_answers');
        Schema::dropIfExists('assessment_attempts');
        Schema::dropIfExists('assessment_questions');
        Schema::dropIfExists('assessments');
    }
};
