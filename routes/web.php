<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'project' => 'MedelFiguri',
        'status' => 'ok',
        'frontend' => 'http://localhost:8080',
        'message' => 'API do MedelFiguri — o frontend estático fica em /frontend.',
    ]);
});
