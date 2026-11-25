<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('customer_decision', 24)->nullable();
        });
        Schema::table('hotel_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_proposal_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_allocations', fn (Blueprint $table) => $table->dropColumn('confirmed_proposal_id'));
        Schema::table('proposals', fn (Blueprint $table) => $table->dropColumn('customer_decision'));
    }
};
