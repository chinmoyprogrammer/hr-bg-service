<?php

use Illuminate\Support\Facades\Route;


/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return "Hello";
});

$router->get('/api', function () use ($router) {
    return $router->app->version();
});

Route::group(['prefix' => 'api'], function () {

    // CRON==> pull raw data from device to temp table
    // after pulling it pushes data to rabbit mq for further processing by this service again
    Route::get('/pull-raw-data-from-device-to-temp-table', 'AttendanceController@pullRawDataFromDeviceToTempTable');

    //.... Test RabbitMQ
    Route::post('/publish-rabbitmq', 'RabbitMQController@publishMessage');

    // Consume one message from RabbitMQ 'processTempData' queue (for testing)
    Route::get('/consume-process-temp-data', 'AttendanceController@consumeProcessTempData');
    
    

    //...should be maintained in another service/controller
    Route::get('/cache', 'CacheController@index');

});
