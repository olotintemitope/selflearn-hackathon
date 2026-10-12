<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            $table->string('knowledge_basis', 24)->default('source_supported');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_questions', function (Blueprint $table) {
            $table->dropColumn('knowledge_basis');
        });
    }
};
