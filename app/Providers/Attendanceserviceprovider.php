<?php
 
namespace App\Providers;
 
use Illuminate\Support\ServiceProvider;
use App\Services\AttendanceProcessingService;
 
class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttendanceProcessingService::class, function () {
            return new AttendanceProcessingService();
        });
    }
}