<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('mode', 16)->default('guided')->after('prompt_version');
            $table->timestamp('started_at')->nullable()->after('mode');
            $table->unsignedSmallInteger('duration_seconds')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['mode', 'started_at', 'duration_seconds']);
        });
    }
};
