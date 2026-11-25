<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('property');
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->unique(['department_id', 'property', 'name']);
        });
        Schema::create('hotel_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_room_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('group_request_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('rooms');
            $table->date('arrival_on');
            $table->date('departure_on');
            $table->string('status');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->index(['hotel_room_type_id', 'status', 'arrival_on']);
        });
        Schema::create('hospitality_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('reference');
            $table->string('status');
            $table->string('message')->nullable();
            $table->timestamp('created_at');
            $table->index(['department_id', 'id']);
        });
        Schema::create('proposal_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('recipient');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['proposal_deliveries', 'hospitality_runs', 'hotel_allocations', 'hotel_room_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
