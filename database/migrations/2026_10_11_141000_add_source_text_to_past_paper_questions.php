<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('past_paper_questions', 'source_text')) {
            Schema::table('past_paper_questions', function (Blueprint $table): void {
                $table->longText('source_text')->after('prompt');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('past_paper_questions', 'source_text')) {
            Schema::table('past_paper_questions', function (Blueprint $table): void {
                $table->dropColumn('source_text');
            });
        }
    }
};
