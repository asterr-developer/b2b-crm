<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->decimal('amount', 14, 2);
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->string('reference')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['deal_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_payments');
    }
};
