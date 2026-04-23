<?php

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

$router->group(['prefix' => 'api'], function () use ($router) {
    $router->get('pull-raw-data-from-device-to-temp-table', 'AttendanceController@pullRawDataFromDeviceToTempTable');
    $router->post('publish-rabbitmq', 'RabbitMQController@publishMessage');
    $router->get('consume-process-temp-data', 'AttendanceController@consumeProcessTempData');
    $router->get('cache', 'CacheController@index');
});
