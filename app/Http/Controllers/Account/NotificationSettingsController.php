<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateNotificationSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mi cuenta → Notificaciones (A2). Siempre actúa sobre el usuario autenticado: la ruta no lleva id.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.notifications', ['user' => $request->user()]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $chatId = $request->validated('telegram_chat_id');

        // telegram_chat_id no está en Fillable: se asigna explícitamente y solo aquí.
        $request->user()->forceFill(['telegram_chat_id' => $chatId === '' ? null : $chatId])->save();

        return to_route('account.notifications.edit')->with('status', $chatId === null || $chatId === ''
            ? __('Avisos por Telegram desactivados.')
            : __('Avisos por Telegram activados.'));
    }
}
