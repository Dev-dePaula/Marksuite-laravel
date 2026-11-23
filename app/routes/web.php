<?php

use Illuminate\Support\Facades\Route;

// O ERP (React) é servido a partir de public/app; "/" e qualquer rota fora de /api abrem a aplicação.
Route::get('/{any?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 503, 'Frontend não compilado. Execute: cd frontend && npm install && npm run build');
    return response()->file($index, ['Content-Type' => 'text/html; charset=utf-8']);
})->where('any', '^(?!api|up|app/).*$');
