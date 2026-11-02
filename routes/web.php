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

// Job tracking (docs/job-failure-tracking-plan.html). health stays open: the scheduler's cross-watch
// must keep working while the auth service is down, and it only returns heartbeat ages.
$router->get('/api/jobs/health', 'JobHealthController@health');
// The dashboard page holds no data (it calls the API below with the user's token), so it's served without auth
$router->get('/jobs', function () {
    return response(file_get_contents(base_path('resources/jobs-dashboard.html')))->header('Content-Type', 'text/html; charset=utf-8');
});
$router->group(['prefix' => 'api/jobs', 'middleware' => 'jobs.auth'], function () use ($router) {
    $router->get('runs', 'JobRunsController@index');
    $router->get('runs/{id:[0-9]+}', 'JobRunsController@show');
    $router->get('summary', 'JobRunsController@summary');
});
$router->group(['prefix' => 'api/jobs', 'middleware' => 'jobs.auth:admin'], function () use ($router) {
    $router->post('runs/{id:[0-9]+}/retry', 'JobRunsController@retry');
    $router->post('runs/{id:[0-9]+}/replay', 'JobRunsController@replay');
    $router->post('missed/{id:[0-9]+}/run-now', 'JobRunsController@runNow');
});

$router->group(['prefix' => 'api'], function () use ($router) {
    $router->get('pull-raw-data-from-device-to-temp-table', 'AttendanceController@pullRawDataFromDeviceToTempTable');
    $router->post('publish-rabbitmq', 'RabbitMQController@publishMessage');
    $router->get('consume-process-temp-data', 'AttendanceController@consumeProcessTempData');
    $router->get('cache', 'CacheController@index');
});
