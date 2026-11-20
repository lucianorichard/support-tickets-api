<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// Used by the Docker entrypoint: seed demo data once, never on top of existing data.
Artisan::command('app:seed-demo-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('Database already has data; skipping demo seed.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
})->purpose('Seed demo data only when the database is empty');
