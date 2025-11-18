<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserTypeController;

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

$router->get('/api', function () use ($router) {
    return $router->app->version();
});

Route::group(['prefix' => 'api'], function () {

    //.... Test RabbitMQ
    Route::post('/publish-rabbitmq', 'RabbitMQController@publishMessage');

    
    

    //...should be maintained in another service/controller
    Route::get('/cache', 'CacheController@index');

});
