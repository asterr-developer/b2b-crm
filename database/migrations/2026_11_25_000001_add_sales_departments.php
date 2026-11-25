<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sales_role')->default('manager');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('assignee_id');
        });

        DB::statement('UPDATE deals SET assignee_id = (
            SELECT companies.user_id FROM contacts
            JOIN companies ON companies.id = contacts.company_id
            WHERE contacts.id = deals.contact_id
        )');
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['assignee_id']);
            $table->dropConstrainedForeignId('assignee_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn('sales_role');
        });

        Schema::dropIfExists('departments');
    }
};
