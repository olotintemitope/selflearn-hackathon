<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_spaces', function (Blueprint $table) {
            $table->string('purpose', 40)->default('general')->after('title');
            $table->string('subject', 120)->nullable()->after('goal');
            $table->string('level', 120)->nullable()->after('subject');
            $table->date('exam_date')->nullable()->after('level');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->string('role', 40)->default('study_notes')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('learning_spaces', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'subject', 'level', 'exam_date']);
        });
    }
};
