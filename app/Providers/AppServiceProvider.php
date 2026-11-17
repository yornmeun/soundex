<?php

namespace App\Providers;

use App\Support\WorkerHeartbeat;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fires on every iteration of `queue:work`, including idle polling.
        Queue::looping(fn () => WorkerHeartbeat::beat());
    }
}
