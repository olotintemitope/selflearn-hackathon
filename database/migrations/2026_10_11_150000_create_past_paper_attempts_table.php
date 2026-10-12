<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('past_paper_question_id')->constrained('past_paper_questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('response');
            $table->string('outcome', 32);
            $table->string('marking_basis', 40);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['user_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_paper_attempts');
    }
};
