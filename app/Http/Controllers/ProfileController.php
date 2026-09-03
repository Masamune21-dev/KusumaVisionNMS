<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman profil kini bersifat baca-saja.
 *
 * Nama, email, password, dan 2FA adalah milik identitas terpusat, jadi
 * pengubahannya terjadi di sso.kusumavision.net — bukan di sini. Kalau app ini
 * ikut bisa mengubahnya, data yang sama akan punya dua sumber kebenaran dan
 * cepat atau lambat keduanya berbeda.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'status' => $request->session()->get('status'),
            'ssoProfileUrl' => config('sso.issuer').'/profile',
            'role' => $user->role?->value,
            // Sesi darurat lewat CLI tidak punya sesi hub; tampilkan peringatannya.
            'isEmergencySession' => $request->session()->get('sso.emergency') === true,
        ]);
    }
}
