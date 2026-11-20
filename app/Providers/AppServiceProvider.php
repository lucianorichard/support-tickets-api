<?php

namespace App\Providers;

use App\Models\Ticket;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Brute-force protection for login and registration.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // {ticket} only resolves to tickets the current user may see. Anything
        // else is a 404, so the API never reveals that another user's ticket
        // exists. Authentication runs before route binding, so user() is set.
        Route::pattern('ticket', '[0-9]+');
        Route::bind('ticket', fn (string $id, $route) => Ticket::query()
            ->visibleTo(request()->user())
            ->findOrFail($id));
    }
}
