<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_guest_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained('hotel_allocations')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('guests');
            $table->timestamp('created_at');
            $table->unique(['allocation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_guest_lists');
    }
};
