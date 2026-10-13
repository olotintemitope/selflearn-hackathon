<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_practice_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('question_ids');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });

        Schema::table('past_paper_attempts', function (Blueprint $table): void {
            $table->foreignUuid('practice_run_id')->nullable()->after('id')
                ->constrained('past_paper_practice_runs')->cascadeOnDelete();
            $table->unique(['practice_run_id', 'past_paper_question_id'], 'past_paper_run_question_unique');
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_attempts', function (Blueprint $table): void {
            $table->dropUnique('past_paper_run_question_unique');
            $table->dropConstrainedForeignId('practice_run_id');
        });

        Schema::dropIfExists('past_paper_practice_runs');
    }
};
