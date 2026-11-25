<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'deals'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
                $table->index('department_id');
            });
        }

        DB::statement('UPDATE companies SET department_id = (SELECT department_id FROM users WHERE users.id = companies.user_id)');
        DB::statement('UPDATE deals SET department_id = (SELECT department_id FROM users WHERE users.id = deals.assignee_id)');

        Schema::create('group_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('source');
            $table->string('status')->default('received');
            $table->date('arrival_on')->nullable();
            $table->date('departure_on')->nullable();
            $table->unsignedInteger('guests')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('sales_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->json('details');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_events');
        Schema::dropIfExists('group_requests');
        foreach (['companies', 'deals'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['department_id']);
                $table->dropConstrainedForeignId('department_id');
            });
        }
    }
};
