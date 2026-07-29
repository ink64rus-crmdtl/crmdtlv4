<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\File;

class SystemController extends Controller
{
    public function index(): Response
    {
        $logPath = storage_path('logs/laravel.log');
        $logs = "Файл логов пуст или не существует.";

        if (File::exists($logPath)) {
            // Читаем последние 1000 строк, чтобы не перегрузить память
            $fileLines = file($logPath);
            $lastLines = array_slice($fileLines, -1000);
            $logs = implode("", $lastLines);
        }

        return Inertia::render('System/Index', [
            'logs' => $logs,
        ]);
    }
}