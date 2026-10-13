<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_chunks', function (Blueprint $table) {
            $table->string('relevance_status')->default('pending')->after('locator');
            $table->decimal('relevance_confidence', 4, 3)->nullable()->after('relevance_status');
            $table->text('relevance_reason')->nullable()->after('relevance_confidence');
            $table->index(['relevance_status', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::table('material_chunks', function (Blueprint $table) {
            $table->dropIndex(['relevance_status', 'material_id']);
            $table->dropColumn(['relevance_status', 'relevance_confidence', 'relevance_reason']);
        });
    }
};
