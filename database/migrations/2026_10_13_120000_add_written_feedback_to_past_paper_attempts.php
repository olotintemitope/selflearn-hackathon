<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('past_paper_attempts', function (Blueprint $table): void {
            $table->string('written_feedback_status', 24)->nullable()->after('marking_basis');
            $table->text('written_feedback')->nullable()->after('written_feedback_status');
            $table->json('written_feedback_steps')->nullable()->after('written_feedback');
            $table->json('written_feedback_source_chunk_ids')->nullable()->after('written_feedback_steps');
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'written_feedback_status', 'written_feedback', 'written_feedback_steps', 'written_feedback_source_chunk_ids',
            ]);
        });
    }
};
