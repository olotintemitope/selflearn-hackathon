<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            $table->string('question_type', 24)->default('multiple_choice');
            $table->json('rubric')->nullable();
            $table->unsignedSmallInteger('max_points')->nullable();
        });

        Schema::table('assessment_answers', function (Blueprint $table) {
            $table->longText('written_response')->nullable();
            $table->string('marking_status', 24)->default('not_applicable');
            $table->unsignedSmallInteger('points_awarded')->nullable();
            $table->unsignedSmallInteger('max_points')->nullable();
            $table->text('marking_feedback')->nullable();
            $table->json('marking_citations')->nullable();
            $table->json('marking_criteria_results')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_answers', function (Blueprint $table) {
            $table->dropColumn(['written_response', 'marking_status', 'points_awarded', 'max_points', 'marking_feedback', 'marking_citations', 'marking_criteria_results']);
        });

        Schema::table('assessment_questions', function (Blueprint $table) {
            $table->dropColumn(['question_type', 'rubric', 'max_points']);
        });
    }
};
