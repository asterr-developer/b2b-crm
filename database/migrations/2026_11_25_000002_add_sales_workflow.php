<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->json('pipeline_settings')->nullable();
        });
        Schema::table('deals', function (Blueprint $table) {
            $table->date('expected_close_on')->nullable();
            $table->string('loss_reason')->nullable();
        });
        Schema::create('deal_contacts', function (Blueprint $table) {
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->primary(['deal_id', 'contact_id']);
        });
        Schema::create('deal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->text('body');
            $table->dateTime('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('automation_key')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('status');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['deal_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('deal_activities');
        Schema::dropIfExists('deal_contacts');
        Schema::table('deals', fn (Blueprint $table) => $table->dropColumn(['expected_close_on', 'loss_reason']));
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('pipeline_settings'));
    }
};
