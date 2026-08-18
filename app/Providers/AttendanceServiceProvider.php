<?php
 
namespace App\Providers;
 
use Illuminate\Support\ServiceProvider;
use App\Services\AttendanceProcessingService;
use App\Services\PreviousDayOutPunchUpdateService;
 
class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttendanceProcessingService::class, function () {
            return new AttendanceProcessingService();
        });

        $this->app->singleton(PreviousDayOutPunchUpdateService::class, function () {
            return new PreviousDayOutPunchUpdateService();
        });
    }
}