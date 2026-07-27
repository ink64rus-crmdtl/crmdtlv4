<?php

namespace App\Services\AiAgent;

class ArchitectEscalationService
{
    public function log(string $question): string
    {
        \Illuminate\Support\Facades\Log::channel('ai_agent')->warning('Agent escalation', [
            'question' => $question,
            'at' => now(),
        ]);

        return 'Вопрос зафиксирован для архитектора/человека. Продолжай только после ответа.';
    }
}