<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/queue.php';

/**
 * src/queue.php (prototype): submit with dedupe and positions, the start rules (max per pool, one job per module,
 * exclusive alone, no starvation), recovery of jobs of a worker that is gone, and the command line.
 */
final class QueueTest extends TestCase
{
    private string $dir = '';

    /**
     * An empty queue folder per test.
     */
    protected function setUp(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/queue-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    /**
     * Removes the queue folder.
     */
    protected function tearDown(): void
    {
        putenv('PS_MODULE_LOCK_DIR');
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    /**
     * A job array for the start rules.
     *
     * @return array<string, mixed>
     */
    private static function job(string $id, string $module, string $state = 'queued', bool $exclusive = false): array
    {
        return ['id' => $id, 'module' => $module, 'state' => $state, 'exclusive' => $exclusive];
    }

    public function testSubmitDedupesAQueuedCommandAndGivesPositions(): void
    {
        $a = queue_submit($this->dir, 'docs', 'heavena', '/x', ['php', 'a'], false, 'thread-a', 0, 1000.0);
        $b = queue_submit($this->dir, 'docs', 'heavenb', '/x', ['php', 'b'], false, '', 0, 1001.0);
        $again = queue_submit($this->dir, 'docs', 'heavena', '/x', ['php', 'a']);

        self::assertSame($a['id'], $again['id']);
        self::assertSame(1, queue_position($this->dir, $a));
        self::assertSame(2, queue_position($this->dir, $b));
        self::assertSame('thread-a', queue_job($this->dir, $a['id'])['thread'] ?? null);
        self::assertSame('queued, position 2 in docs', queue_status_line($this->dir, $b));
    }

    public function testPriorityGoesFirst(): void
    {
        queue_submit($this->dir, 'shops', 'heavena', '/x', ['a'], false, '', 0, 1000.0);
        $urgent = queue_submit($this->dir, 'shops', 'heavenb', '/x', ['b'], false, '', 5, 1001.0);

        self::assertSame(1, queue_position($this->dir, $urgent));
    }

    public function testWrongInputIsRefused(): void
    {
        foreach ([['nope', 'heavena', ['a']], ['docs', 'Heaven A', ['a']], ['docs', 'heavena', []]] as [$pool, $module, $command]) {
            try {
                queue_submit($this->dir, $pool, $module, '/x', $command);
                self::fail("$pool/$module should be refused");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStartRules(): void
    {
        $three = [self::job('1', 'a'), self::job('2', 'b'), self::job('3', 'a'), self::job('4', 'c'), self::job('5', 'd')];
        self::assertSame(['1', '2', '4'], array_column(queue_startable($three, 3), 'id'), 'max 3, never the same module twice');

        $busy = [self::job('1', 'a', 'running'), self::job('2', 'a'), self::job('3', 'b')];
        self::assertSame(['3'], array_column(queue_startable($busy, 3), 'id'), 'a module that runs waits');

        $exclusiveFirst = [self::job('1', 'a', 'queued', true), self::job('2', 'b')];
        self::assertSame(['1'], array_column(queue_startable($exclusiveFirst, 3), 'id'), 'an exclusive job runs alone');

        $exclusiveWaits = [self::job('0', 'z', 'running'), self::job('1', 'a', 'queued', true), self::job('2', 'b')];
        self::assertSame([], queue_startable($exclusiveWaits, 3), 'an exclusive job waits for the pool to empty; later jobs wait behind it');

        $whileExclusive = [self::job('1', 'a', 'running', true), self::job('2', 'b')];
        self::assertSame([], queue_startable($whileExclusive, 3), 'nothing starts next to an exclusive job');

        self::assertSame([], queue_startable([self::job('1', 'a', 'running'), self::job('2', 'b', 'running'), self::job('3', 'c')], 2), 'no free slot');
    }

    public function testPoolSettingsOverrideTheDefault(): void
    {
        self::assertSame(['max' => 3], queue_pool($this->dir, 'docs'));
        file_put_contents("$this->dir/pools.json", '{"docs": {"max": 1}, "shops": {"max": 0}}');
        self::assertSame(['max' => 1], queue_pool($this->dir, 'docs'));
        self::assertSame(['max' => 1], queue_pool($this->dir, 'shops'), 'never below 1');
    }

    public function testTheLimitCanChangeWhileRunning(): void
    {
        queue_set_max($this->dir, 'shops', 5);
        self::assertSame(['max' => 5], queue_pool($this->dir, 'shops'));
        self::assertSame(['max' => 3], queue_pool($this->dir, 'docs'), 'the other pool keeps its own limit');

        $jobs = [self::job('1', 'a', 'running'), self::job('2', 'b', 'running'), self::job('3', 'c', 'running'), self::job('4', 'd')];
        queue_set_max($this->dir, 'shops', 2);
        self::assertSame([], queue_startable($jobs, queue_pool($this->dir, 'shops')['max']), 'lowered: nothing new starts while above the limit');
        queue_set_max($this->dir, 'shops', 4);
        self::assertSame(['4'], array_column(queue_startable($jobs, queue_pool($this->dir, 'shops')['max']), 'id'), 'raised: a waiting job starts');

        foreach ([0, 17] as $wrong) {
            try {
                queue_set_max($this->dir, 'shops', $wrong);
                self::fail("$wrong is not a limit");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAJobOfAGoneWorkerIsRequeuedOnceThenFailed(): void
    {
        $job = queue_submit($this->dir, 'docs', 'heavena', '/x', ['a']);
        $job['state'] = 'running';
        $job['worker'] = 'other-host:1';
        queue_save($this->dir, $job);
        $processes = [];
        $never = static fn (array $j, string $log): ?array => null;
        file_put_contents("$this->dir/pools.json", '{"docs": {"max": 1}}');

        // The pass requeues it, then (no real spawn) the start fails: failed with exit -1 after the second attempt.
        queue_tick($this->dir, 'docs', $processes, $never);
        $after = queue_job($this->dir, $job['id']);
        self::assertSame('failed', $after['state'] ?? null);
        self::assertSame(1, $after['attempts'] ?? null);

        $again = queue_submit($this->dir, 'docs', 'heavenb', '/x', ['b']);
        $again['state'] = 'running';
        $again['worker'] = 'other-host:1';
        $again['attempts'] = 1;
        queue_save($this->dir, $again);
        queue_tick($this->dir, 'docs', $processes, $never);
        self::assertSame(-1, queue_job($this->dir, $again['id'])['exit'] ?? null);
    }

    public function testARealJobRunsToDoneThroughTheWorkerPass(): void
    {
        $job = queue_submit($this->dir, 'docs', 'heavena', sys_get_temp_dir(), [PHP_BINARY, '-r', 'echo "hello";']);
        $processes = [];
        queue_tick($this->dir, 'docs', $processes);
        self::assertSame('running', queue_job($this->dir, $job['id'])['state'] ?? null);
        for ($i = 0; $i < 50 && $processes !== []; ++$i) {
            usleep(100000);
            queue_tick($this->dir, 'docs', $processes);
        }

        $done = queue_job($this->dir, $job['id']);
        self::assertSame('done', $done['state'] ?? null);
        self::assertSame(0, $done['exit'] ?? null);
        self::assertSame('hello', file_get_contents("$this->dir/docs/{$job['id']}.log"));
    }

    public function testTheCommandLine(): void
    {
        $job = queue_submit($this->dir, 'shops', 'heavena', '/x', ['a'], false, 'thread-a');
        $run = static function (array $args): array {
            ob_start();
            $code = @queue_main('/x', $args);

            return [$code, (string) ob_get_clean()];
        };
        $q = "--queue-dir=$this->dir";

        self::assertSame([0, "{$job['id']} queued, position 1 in shops\n"], $run(['status', $job['id'], $q]));
        [$code, $out] = $run(['wait', $job['id'], '--timeout=0', $q]);
        self::assertSame(3, $code, 'still waiting');
        [$code, $out] = $run(['list', $q]);
        self::assertSame(0, $code);
        self::assertStringContainsString('thread-a', $out);
        [$code, $out] = $run(['list', '--json', $q]);
        self::assertSame(1, json_decode($out, true)[0]['position'] ?? null);
        self::assertSame([0, "Cancelled {$job['id']}.\n"], $run(['cancel', $job['id'], $q]));
        self::assertSame(1, $run(['status', $job['id'], $q])[0], 'a cancelled job');
        self::assertSame(1, $run(['result', $job['id'], $q])[0]);
        self::assertTrue(queue_job($this->dir, $job['id'])['result_read'] ?? false, 'result marks the job as read');
        self::assertSame(2, $run(['status', 'no-such-job', $q])[0]);
        self::assertSame(2, $run(['cancel', $job['id'], $q])[0], 'only open jobs can be cancelled');
        self::assertSame(2, $run(['nope', $q])[0]);
    }
}
