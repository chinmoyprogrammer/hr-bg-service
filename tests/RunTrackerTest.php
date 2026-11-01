<?php

namespace Tests;

use App\Support\RunTracker;
use Illuminate\Queue\TimeoutExceededException;
use PHPUnit\Framework\TestCase as PlainTestCase;
use RuntimeException;

// No DB: a tracker without a job_runs id never writes.
class RunTrackerTest extends PlainTestCase
{
    private function tracker(): RunTracker
    {
        return new RunTracker(null, 'test-run');
    }

    public function test_status_decision(): void
    {
        $t = $this->tracker();
        $this->assertSame('success', $t->status(false));
        $this->assertSame('failed', $t->status(true, new RuntimeException('x')));
        $this->assertSame('timed_out', $t->status(true, new TimeoutExceededException('x')));

        $t->log('error', 'swallowed', []);
        $this->assertSame('completed_with_errors', $t->status(false));

        $t = $this->tracker();
        $t->begin('mysql');
        $t->query('mysql', 'insert into a values (1)');
        $t->rolledBack('mysql', 0);
        $this->assertSame('rolled_back', $t->status(false));
        $this->assertSame(['writes_kept' => 0, 'writes_undone' => 1], array_intersect_key($t->counts, ['writes_kept' => 0, 'writes_undone' => 0]));

        $t->begin('mysql');
        $t->query('mysql', 'update a set x = 1');
        $t->committed('mysql', 0);
        $this->assertSame('partial', $t->status(false));
        $this->assertSame('failed_retry_unsafe', $t->status(true, new RuntimeException('x')));
    }

    public function test_reads_are_not_writes(): void
    {
        $t = $this->tracker();
        $t->query('mysql', 'select * from a for update');
        $t->query('mysql', '  DELETE FROM a');
        $this->assertSame(1, $t->counts['writes_kept']);
    }

    public function test_nested_savepoint_rollback_keeps_outer_writes(): void
    {
        $t = $this->tracker();
        $t->begin('mysql');                        // level 1
        $t->query('mysql', 'insert into a values (1)');
        $t->begin('mysql');                        // savepoint, level 2
        $t->query('mysql', 'insert into a values (2)');
        $t->query('mysql', 'insert into a values (3)');
        $t->rolledBack('mysql', 1);                // back to level 1
        $t->begin('mysql');                        // savepoint, level 2
        $t->query('mysql', 'insert into a values (4)');
        $t->committed('mysql', 1);                 // folds into level 1
        $t->committed('mysql', 0);

        $this->assertSame(2, $t->counts['writes_kept']);
        $this->assertSame(2, $t->counts['writes_undone']);
        $this->assertSame(1, $t->counts['tx_committed']);
        $this->assertSame('partial', $t->status(false));
    }

    public function test_rollback_to_level_undoes_every_level_above(): void
    {
        $t = $this->tracker();
        $t->begin('mysql');
        $t->query('mysql', 'insert into a values (1)');
        $t->begin('mysql');
        $t->query('mysql', 'insert into a values (2)');
        $t->begin('mysql');
        $t->query('mysql', 'insert into a values (3)');
        $t->rolledBack('mysql', 0);                // DB::rollBack(0) from level 3

        $this->assertSame(3, $t->counts['writes_undone']);
        $this->assertSame(0, $t->counts['writes_kept']);
        $t->query('mysql', 'insert into a values (4)');  // autocommit again
        $this->assertSame(1, $t->counts['writes_kept']);
    }

    public function test_open_transaction_counts_as_undone(): void
    {
        $t = $this->tracker();
        $t->begin('mysql');
        $t->query('mysql', 'insert into a values (1)');
        $t->abandonOpen();
        $this->assertSame(1, $t->counts['writes_undone']);
        $this->assertSame('rolled_back', $t->status(false));
    }

    public function test_repeats_collapse(): void
    {
        $t = $this->tracker();
        for ($i = 0; $i < 5; $i++) {
            $t->log('info', 'same', ['k' => 1]);
        }
        $t->log('info', 'other', []);
        [$lines] = $t->lines(false);
        $this->assertCount(2, $lines);
        $this->assertSame(5, $lines[0]['n']);
        $this->assertSame(6, $t->counts['log_lines']);
    }

    public function test_keeps_head_and_tail(): void
    {
        $t = $this->tracker();
        $total = RunTracker::KEEP_LINES * 2 + 101;  // the newest line is held outside the tail ring
        for ($i = 0; $i < $total; $i++) {
            $t->log('info', "line {$i}", []);
        }
        [$lines, $dropped] = $t->lines(false);
        $this->assertSame(100, $dropped);
        $this->assertSame('line 0', $lines[0]['m']);
        $this->assertSame('... 100 lines dropped ...', $lines[RunTracker::KEEP_LINES]['m']);
        $this->assertSame('line ' . ($total - 1), end($lines)['m']);
        $this->assertSame('line ' . (RunTracker::KEEP_LINES + 100), $lines[RunTracker::KEEP_LINES + 1]['m']);
    }

    public function test_success_keeps_warnings_and_errors_plus_first_and_last_infos(): void
    {
        $t = $this->tracker();
        for ($i = 0; $i < 200; $i++) {
            $t->log('info', "info {$i}", []);
            if ($i === 100) {
                $t->log('warning', 'careful', []);
            }
        }
        [$lines, $dropped] = $t->lines(true);
        $this->assertCount(2 * RunTracker::SUCCESS_KEEP + 1, $lines);
        $this->assertSame(200 - 2 * RunTracker::SUCCESS_KEEP, $dropped);
        $this->assertContains('careful', array_column($lines, 'm'));
        $this->assertSame('info 199', end($lines)['m']);

        // The row only trims on success
        $this->assertSame(0, $t->row('failed')['log_dropped']);
        $this->assertSame(150, $t->row('success')['log_dropped']);
        $this->assertCount(51, json_decode(gzdecode($t->row('success')['log']), true));
    }

    public function test_redaction_and_truncation(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
        $t = $this->tracker();
        $t->log('info', "token = {$jwt}", [
            'password' => 'hunter2',
            'headers' => ['Authorization' => "JWT {$jwt}", 'Accept' => 'json'],
            'api_key' => 'k',
            'note' => "got {$jwt}",
            'e' => new RuntimeException('boom'),
            'run_id' => 'test-run',
        ]);
        $t->log('info', str_repeat('x', 10000), ['big' => str_repeat('y', 10000)]);
        [$lines] = $t->lines(false);

        $this->assertSame('token = [jwt]', $lines[0]['m']);
        $c = $lines[0]['c'];
        $this->assertSame('[redacted]', $c['password']);
        $this->assertSame('[redacted]', $c['headers']['Authorization']);
        $this->assertSame('json', $c['headers']['Accept']);
        $this->assertSame('[redacted]', $c['api_key']);
        $this->assertSame('got [jwt]', $c['note']);
        $this->assertStringStartsWith('RuntimeException: boom at ', $c['e']);
        $this->assertArrayNotHasKey('run_id', $c);
        $this->assertStringNotContainsString('hunter2', json_encode($lines));

        $this->assertLessThan(RunTracker::FIELD_MAX + 50, strlen($lines[1]['m']));
        $this->assertIsString($lines[1]['c']);  // over 4 KB: kept as cut JSON text
        $this->assertLessThan(RunTracker::FIELD_MAX + 50, strlen($lines[1]['c']));
    }

    public function test_first_error_becomes_error_message(): void
    {
        $t = $this->tracker();
        $t->log('info', 'start', []);
        $t->log('error', 'first problem', []);
        $t->log('critical', 'second problem', []);
        $this->assertSame('first problem', $t->row()['error_message']);
        $this->assertSame(2, $t->counts['errors']);
    }

    public function test_job_name(): void
    {
        $this->assertSame('processTempData', RunTracker::jobName('processTempData_queue'));
        $this->assertSame('processTempData', RunTracker::jobName('processTempData_trigger_queue'));
        $this->assertSame('default', RunTracker::jobName('default'));
    }
}
