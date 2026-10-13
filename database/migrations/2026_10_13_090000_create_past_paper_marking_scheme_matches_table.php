<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_paper_marking_scheme_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('marking_scheme_material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('paper_extraction_version');
            $table->unsignedInteger('marking_scheme_extraction_version');
            $table->timestamps();

            $table->unique([
                'paper_material_id',
                'marking_scheme_material_id',
                'paper_extraction_version',
                'marking_scheme_extraction_version',
            ], 'past_paper_scheme_match_versions_unique');
            $table->index(['paper_material_id', 'paper_extraction_version'], 'past_paper_scheme_match_paper_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_paper_marking_scheme_matches');
    }
};
