<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['last_notifications_read_at' => now()])->save();

        return back();
    }
}
