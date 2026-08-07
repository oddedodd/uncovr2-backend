<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ApiResponse::success([
    'service' => 'uncovr',
    'version' => 'v1',
]))->name('index');
