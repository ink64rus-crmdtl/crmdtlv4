<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->nullable()->after('type')->comment('Комиссия эквайринга в %, применимо для type=acquiring');
        });

        // Системный виртуальный счёт для оплаты бонусами клиента.
        // Не привязан к юрлицу/филиалу — живёт по общим правилам ABAC (role_accounts/user_accounts).
        if (!DB::table('accounts')->where('type', 'bonus')->exists()) {
            DB::table('accounts')->insert([
                'branch_id' => null,
                'legal_entity_id' => null,
                'name' => 'Бонусы клиентов',
                'type' => 'bonus',
                'commission_percent' => null,
                'balance' => 0,
                'is_active' => true,
                'is_default_for_invoicing' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('accounts')->where('type', 'bonus')->delete();

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
