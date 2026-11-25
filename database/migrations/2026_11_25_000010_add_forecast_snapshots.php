<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->date('captured_on')->primary();
            $table->timestamp('created_at');
        });
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->date('captured_on');
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->date('expected_close_on');
            $table->unsignedBigInteger('weighted_minor');
            $table->primary(['captured_on', 'deal_id']);
            $table->index(['department_id', 'captured_on']);
            $table->index(['assignee_id', 'department_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
        Schema::dropIfExists('forecast_runs');
    }
};
