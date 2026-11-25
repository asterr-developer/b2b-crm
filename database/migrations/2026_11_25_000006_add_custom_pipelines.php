<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->json('stages');
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('sales_pipeline_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('pipeline_step')->nullable();
            $table->index(['sales_pipeline_id', 'pipeline_step']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['sales_pipeline_id', 'pipeline_step']);
            $table->dropConstrainedForeignId('sales_pipeline_id');
            $table->dropColumn('pipeline_step');
        });
        Schema::dropIfExists('sales_pipelines');
    }
};
