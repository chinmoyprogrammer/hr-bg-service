<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

// `mysql` and `tracking` as two separate in-memory sqlite DBs with the job tracking tables, logs
// discarded and an array cache, so nothing touches the shared database, Redis or log files.
trait TrackingDatabase
{
    protected function useTrackingDatabase(): void
    {
        config([
            'logging.channels.discard' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
            'logging.default' => 'discard',
        ]);
        $this->app->configure('cache');
        config(['cache.stores.array' => ['driver' => 'array'], 'cache.default' => 'array']);

        $this->app->configure('database');
        $sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        config(['database.connections.mysql' => $sqlite, 'database.connections.tracking' => $sqlite, 'database.default' => 'tracking']);
        foreach (glob(__DIR__ . '/../database/migrations/2026_11_0[1-3]_1000*_*.php') as $migration) {
            (require $migration)->up();
        }
        config(['database.default' => 'mysql']);
        DB::statement('create table probe (id integer primary key autoincrement, v integer)');
    }
}
