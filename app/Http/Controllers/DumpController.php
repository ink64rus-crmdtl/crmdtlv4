<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DumpController extends Controller
{
    public function download()
    {
        $content = "# CRM DUMP FOR LLM\n\n";
        $content .= "## 1. DATABASE STRUCTURE (Tenant)\n\n";

        $tables = DB::select('SHOW TABLES');
        $dbName = DB::connection()->getDatabaseName();
        $key = "Tables_in_" . $dbName;

        foreach ($tables as $table) {
            $tableName = $table->$key;
            $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`")[0]->{'Create Table'};
            $content .= "### Table: {$tableName}\n```sql\n{$createTable}\n```\n\n";
        }

        $content .= "## 2. MODIFIED FILES\n\n";

        $directories = [
            app_path(),
            base_path('routes'),
            base_path('database/migrations'),
            base_path('database/seeders'),
            base_path('resources/js'),
            base_path('lang'),
        ];

        foreach ($directories as $dir) {
            if (File::exists($dir)) {
                $files = File::allFiles($dir);
                foreach ($files as $file) {
                    $fullPath = $file->getRealPath();
                    $fileContent = File::get($fullPath);
                    $content .= "### File: " . str_replace(base_path() . '/', '', $fullPath) . "\n```\n{$fileContent}\n```\n\n";
                }
            }
        }

        $singleFiles = [
            base_path('config/tenancy.php'),
            base_path('config/permission.php'),
            base_path('config/app.php'),
            base_path('config/horizon.php'),
            base_path('config/laragent.php'),
            base_path('bootstrap/app.php'),
            base_path('composer.json'),
            base_path('package.json'),
            base_path('vite.config.js'),
            base_path('tailwind.config.js'),
            base_path('phpunit.tenant.xml'),
        ];

        foreach ($singleFiles as $singleFile) {
            if (File::exists($singleFile)) {
                $fileContent = File::get($singleFile);
                $content .= "### File: " . str_replace(base_path() . '/', '', $singleFile) . "\n```\n{$fileContent}\n```\n\n";
            }
        }

        $fileName = 'crm_dump_' . date('Y_m_d_His') . '.md';
        $tempPath = storage_path('app/temp/' . $fileName);

        if (!File::exists(storage_path('app/temp'))) {
            File::makeDirectory(storage_path('app/temp'), 0755, true);
        }

        File::put($tempPath, $content);

        return response()->download($tempPath)->deleteFileAfterSend(true);
    }
}