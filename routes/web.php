<?php

use Illuminate\Support\Facades\Route;

// API-only application: point browsers to the API root.
Route::redirect('/', '/api');
