<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('past_paper_practice_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_minutes')->default(30)->after('started_at');
            $table->timestamp('expires_at')->nullable()->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_practice_runs', function (Blueprint $table): void {
            $table->dropColumn(['duration_minutes', 'expires_at']);
        });
    }
};
