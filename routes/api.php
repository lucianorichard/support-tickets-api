<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\TicketAssigneeController;
use App\Http\Controllers\Api\TicketCommentController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketTransitionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => [
    'name' => config('app.name'),
    'version' => '1.0.0',
    'documentation' => 'https://github.com/lucianorichard/support-tickets-api/blob/main/docs/API.md',
]);

Route::middleware('throttle:auth')->prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('technicians', [TechnicianController::class, 'index']);

    Route::apiResource('tickets', TicketController::class)->except('destroy');
    Route::post('tickets/{ticket}/transitions', [TicketTransitionController::class, 'store']);
    Route::put('tickets/{ticket}/assignee', [TicketAssigneeController::class, 'update']);
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index']);
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store']);
});
