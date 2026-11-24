<?php

namespace App\Http\Controllers;

use App\Services\Security;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Páginas e endpoints públicos de captura de leads e feedbacks. */
class PublicCaptureController extends Controller
{
    private function guard(Request $r): void
    {
        if ($r->filled('website')) abort(204);               // honeypot anti-bot (campo escondido)
        Security::strike($r, 'formulario', 30, 60, 120);     // spam em massa -> bloqueio do IP
    }

    public function leadForm() { return view('public.lead'); }

    public function lead(Request $r)
    {
        $this->guard($r);
        $d = $r->validate(['name' => 'required|string|min:2|max:120', 'email' => 'required|email|max:190', 'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:120', 'message' => 'nullable|string|max:1000']);
        SuperAdminController::storeLead($d, $r->ip());
        return $r->is('api/*') ? response()->json(['ok' => true], 201) : back()->with('ok', 'Obrigado! Entraremos em contacto em breve.');
    }

    public function feedbackForm() { return view('public.feedback'); }

    public function feedback(Request $r)
    {
        $this->guard($r);
        $d = $r->validate(['name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:190', 'type' => 'required|in:ELOGIO,SUGESTAO,PROBLEMA,OUTRO',
            'rating' => 'nullable|integer|min:1|max:5', 'message' => 'required|string|min:3|max:3000']);
        $u = $r->user('sanctum');
        DB::table('feedbacks')->insert([
            'tenantId' => $u?->tenantId, 'userId' => $u?->id, 'name' => $d['name'] ?? $u?->name, 'email' => $d['email'] ?? $u?->email,
            'type' => $d['type'], 'rating' => $d['rating'] ?? null, 'message' => $d['message'], 'ip' => $r->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $r->is('api/*') ? response()->json(['ok' => true], 201) : back()->with('ok', 'Obrigado pelo seu feedback!');
    }
}
