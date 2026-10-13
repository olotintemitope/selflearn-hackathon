<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
        });

        DB::table('materials')->whereNull('uuid')->orderBy('id')->chunkById(250, function ($materials): void {
            foreach ($materials as $material) {
                DB::table('materials')->where('id', $material->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });

        Schema::table('materials', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
