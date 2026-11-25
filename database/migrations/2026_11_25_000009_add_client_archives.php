<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->index('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['merged_into_id']);
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('archived_at');
        });
    }
};
