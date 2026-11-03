<?php

namespace App\Http\Controllers;

use App\Support\RunTracker;
use Illuminate\Http\Request;

class JobHealthController extends Controller
{
    /**
     * Heartbeat ages in seconds. task-scheduler polls this every minute (its cross-watch) and
     * the poll counts as the scheduler's own heartbeat.
     */
    public function health(Request $request)
    {
        // ponytail: unauthenticated like the other /api routes; a forged header can only fake the scheduler beat
        if ($request->header('X-Caller') === 'task-scheduler') {
            RunTracker::beat('scheduler', ['via' => 'health']);
        }

        $heartbeats = [];
        $now = microtime(true);
        foreach (RunTracker::db()->table('heartbeats')->get(['name', 'beat_at']) as $beat) {
            $heartbeats[$beat->name] = (int) round($now - strtotime($beat->beat_at));
        }

        return response()->json(['status' => 'ok', 'heartbeats' => $heartbeats]);
    }
}
