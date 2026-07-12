<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman panduan penggunaan aplikasi (in-app user guide). Konten statis
 * dirender di frontend (`Pages/Panduan/Index.vue`); role-gating tampilan
 * memakai `auth.can` dari shared Inertia props. Invokable agar aman di-`route:cache`.
 */
class PanduanController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Panduan/Index');
    }
}
