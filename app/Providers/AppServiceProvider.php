<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
    public function boot()
    {
        // Set timezone from .env
        date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Dhaka'));
    }

}
