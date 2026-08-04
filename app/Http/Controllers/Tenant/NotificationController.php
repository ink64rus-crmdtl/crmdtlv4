<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class NotificationController extends Controller
{
    /**
     * Получить список последних уведомлений пользователя.
     */
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->notifications()->take(15)->get()
        );
    }

    /**
     * Пометить уведомление(я) как прочитанное.
     */
    public function markAsRead(Request $request, $id)
    {
        if ($id === 'all') {
            $request->user()->unreadNotifications->markAsRead();
        } else {
            $notification = $request->user()->notifications()->where('id', $id)->first();
            if ($notification) {
                $notification->markAsRead();
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Скачать сгенерированный файл экспорта.
     */
    public function downloadExport($filename)
    {
        $path = "exports/{$filename}";
        
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Файл не найден, срок его хранения истек или он был удален.');
        }

        return Storage::disk('local')->download($path);
    }
}