<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tutor_messages', 'reply_language') && ! Schema::hasColumn('tutor_messages', 'language')) {
            Schema::table('tutor_messages', function (Blueprint $table): void {
                $table->renameColumn('reply_language', 'language');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tutor_messages', 'language') && ! Schema::hasColumn('tutor_messages', 'reply_language')) {
            Schema::table('tutor_messages', function (Blueprint $table): void {
                $table->renameColumn('language', 'reply_language');
            });
        }
    }
};
