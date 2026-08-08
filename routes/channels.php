<?php

use App\Models\Chat;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Подключается вручную внутри routes/tenant.php (не через withRouting(channels: ...) —
// см. комментарий в bootstrap/app.php), поэтому tenancy() тут уже инициализирован и
// Chat::find() бьёт в правильную БД тенанта.
Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    $chat = Chat::find($chatId);

    if (!$chat) {
        return false;
    }

    if ($chat->type === 'internal') {
        return $chat->participants()->where('user_id', $user->id)->exists();
    }

    // Внешний (клиентский) чат — пока доступен любому аутентифицированному
    // сотруднику тенанта; более гранулярные права (например по филиалу
    // клиента) можно добавить сюда позже так же, как в BranchScope.
    return true;
});
