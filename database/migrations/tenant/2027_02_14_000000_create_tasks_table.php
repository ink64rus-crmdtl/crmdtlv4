<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза 17, этап 2 — задачи. ОБЩЕСИСТЕМНЫЕ И ПОЛИМОРФНЫЕ (решение владельца):
 * задача «перезвонить по гарантии» относится к заказ-наряду, а не к сделке —
 * заводить их только внутри Deal было бы неправильной границей с первого дня.
 *
 * taskable nullable — задача не обязана быть привязана к записи (личное
 * напоминание менеджера).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->nullableMorphs('taskable');

            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Lookup(type=task_type) — тот же паттерн, что work_order_status:
            // обычная строка, сверяемая со справочником, а не FK.
            $table->string('type')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Чтобы напоминание по due_at не отправилось повторно при
            // каждом прогоне планировщика — тот же приём, что у
            // Appointment.reminder_sent_at.
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'assigned_to_user_id', 'completed_at']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
