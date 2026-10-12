<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table): void {
            $table->string('reply_language', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table): void {
            $table->dropColumn('reply_language');
        });
    }
};
