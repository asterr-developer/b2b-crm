<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_capacity_overrides', function (Blueprint $table) {
            $table->foreignId('hotel_room_type_id')->constrained()->restrictOnDelete();
            $table->date('night');
            $table->unsignedInteger('capacity');
            $table->string('reason');
            $table->primary(['hotel_room_type_id', 'night']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_capacity_overrides');
    }
};
