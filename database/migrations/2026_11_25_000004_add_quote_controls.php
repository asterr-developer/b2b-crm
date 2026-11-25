<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('normalized_name')->nullable();
            $table->index(['department_id', 'normalized_name']);
        });
        DB::table('companies')->select('id', 'name')->orderBy('id')->chunkById(250, function ($companies) {
            foreach ($companies as $company) {
                DB::table('companies')->where('id', $company->id)->update(['normalized_name' => Str::lower(Str::squish($company->name))]);
            }
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
        });
        DB::statement("UPDATE deals SET closed_at = (
            SELECT MAX(created_at) FROM deal_transitions
            WHERE deal_transitions.deal_id = deals.id AND deal_transitions.to_stage = deals.stage
        ) WHERE stage IN ('won', 'lost')");

        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('follow_up_at')->nullable();
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->unique(['department_id', 'name']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['rejection_reason', 'follow_up_at']);
        });
        Schema::table('deals', fn (Blueprint $table) => $table->dropColumn('closed_at'));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
