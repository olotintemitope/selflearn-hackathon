<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('past_paper_questions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('paper_year')->nullable();
            $table->unsignedSmallInteger('marks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_questions', function (Blueprint $table): void {
            $table->dropColumn(['paper_year', 'marks']);
        });
    }
};
