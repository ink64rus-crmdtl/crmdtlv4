<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('middle_name')->nullable()->after('last_name');
            $table->string('personal_email')->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('personal_email');
            $table->date('hire_date')->nullable()->after('birth_date');
            $table->date('termination_date')->nullable()->after('hire_date');
            $table->json('passport_data')->nullable()->after('termination_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'middle_name',
                'personal_email',
                'birth_date',
                'hire_date',
                'termination_date',
                'passport_data'
            ]);
        });
    }
};