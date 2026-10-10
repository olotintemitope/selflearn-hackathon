<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_space_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('difficulty')->default('introductory');
            $table->timestamps();

            $table->unique(['learning_space_id', 'name']);
        });

        Schema::create('concept_sources', function (Blueprint $table) {
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_chunk_id')->constrained()->cascadeOnDelete();
            $table->primary(['concept_id', 'material_chunk_id']);
        });

        Schema::create('concept_dependencies', function (Blueprint $table) {
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->boolean('source_supported')->default(false);
            $table->primary(['concept_id', 'prerequisite_concept_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('concept_dependencies');
        Schema::dropIfExists('concept_sources');
        Schema::dropIfExists('concepts');
    }
};
