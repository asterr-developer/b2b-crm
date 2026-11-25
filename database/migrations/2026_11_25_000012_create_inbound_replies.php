<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_key', 80)->nullable();
            $table->string('body_hash', 64)->nullable();
            $table->string('sender');
            $table->string('subject');
            $table->text('body');
            $table->string('in_reply_to')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('linked_at')->nullable();
            $table->unique(['department_id', 'event_key']);
            $table->index(['department_id', 'proposal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_replies');
    }
};
