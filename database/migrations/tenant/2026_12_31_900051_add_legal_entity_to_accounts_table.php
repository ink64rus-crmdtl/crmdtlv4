<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('legal_entity_id')->nullable()->after('branch_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->string('bank_name')->nullable()->after('type');
            $table->string('bik')->nullable()->after('bank_name')->comment('BIK / SWIFT');
            $table->string('account_number')->nullable()->after('bik')->comment('Account / IBAN');
            $table->string('corr_account')->nullable()->after('account_number');
            $table->boolean('is_default_for_invoicing')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['legal_entity_id']);
            $table->dropColumn([
                'legal_entity_id',
                'bank_name',
                'bik',
                'account_number',
                'corr_account',
                'is_default_for_invoicing',
            ]);
        });
    }
};
