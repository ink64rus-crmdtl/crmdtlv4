<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            $table->decimal('cashback_percent', 5, 2)->default(0)->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            $table->dropColumn('cashback_percent');
        });
    }
};
