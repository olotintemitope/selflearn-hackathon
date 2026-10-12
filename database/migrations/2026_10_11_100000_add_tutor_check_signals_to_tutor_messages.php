<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table): void {
            $table->foreignId('concept_id')->nullable()->after('learning_space_id')->constrained()->nullOnDelete();
            $table->string('understanding_check')->nullable()->after('insufficient_evidence');
            $table->index(['learning_space_id', 'concept_id', 'understanding_check']);
        });
    }

    public function down(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table): void {
            $table->dropIndex(['learning_space_id', 'concept_id', 'understanding_check']);
            $table->dropConstrainedForeignId('concept_id');
            $table->dropColumn('understanding_check');
        });
    }
};
