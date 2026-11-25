<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_events', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 80);
            $table->string('body_hash', 64);
            $table->foreignId('group_request_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
            $table->primary(['department_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_events');
    }
};
