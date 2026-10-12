<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('past_paper_practice_runs', function (Blueprint $table): void {
            $table->json('selected_material_ids')->nullable()->after('material_id');
        });
    }

    public function down(): void
    {
        Schema::table('past_paper_practice_runs', function (Blueprint $table): void {
            $table->dropColumn('selected_material_ids');
        });
    }
};
