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
        Schema::create('material_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('extraction_version');
            $table->longText('content');
            $table->unsignedInteger('page')->nullable();
            $table->string('section')->nullable();
            $table->json('locator');
            $table->timestamps();

            $table->index(['material_id', 'extraction_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_chunks');
    }
};
