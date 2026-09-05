<?php

declare(strict_types=1);

/**
 * Lightweight assertions for video progress / chain-release pure logic.
 * Run: docker exec workflow-php-1 php /var/www/html/scripts/test_video_progress_status.php
 */

require __DIR__ . '/../vendor/autoload.php';

use app\support\VideoWorkflowProgress;

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        fwrite(STDERR, "FAIL {$label}\n  expected: {$e}\n  actual:   {$a}\n");
        exit(1);
    }
    echo "OK  {$label}\n";
}

// #1 decideStatus
assertSame('failed', VideoWorkflowProgress::decideStatus(3, 0, 0, 0, 0, 0, 3)['node'], 'all cancelled -> failed');
assertSame('success', VideoWorkflowProgress::decideStatus(3, 2, 0, 0, 0, 0, 1)['node'], 'some success + cancelled -> success');
assertSame('success', VideoWorkflowProgress::decideStatus(2, 1, 0, 0, 0, 0, 1)['node'], '1 success + 1 cancelled -> success');
assertSame('running', VideoWorkflowProgress::decideStatus(3, 1, 0, 0, 1, 0, 1)['node'], 'still active -> running');
assertSame('running', VideoWorkflowProgress::decideStatus(3, 0, 0, 0, 2, 1, 0)['node'], 'queued/running present -> running');
assertSame('failed', VideoWorkflowProgress::decideStatus(3, 1, 0, 0, 0, 2, 0)['node'], 'blocked remainder -> failed');
assertSame('failed', VideoWorkflowProgress::decideStatus(3, 0, 1, 0, 0, 0, 2)['node'], 'failed + cancelled -> failed');
assertSame('success', VideoWorkflowProgress::decideStatus(3, 3, 0, 0, 0, 0, 0)['node'], 'all success -> success');
assertSame('failed', VideoWorkflowProgress::decideStatus(2, 0, 0, 2, 0, 0, 0)['node'], 'all stale -> failed');
assertSame('stale', VideoWorkflowProgress::decideStatus(2, 0, 0, 2, 0, 0, 0)['state'], 'all stale state -> stale');

// #5 resolveBlockedDependent
assertSame('queue', VideoWorkflowProgress::resolveBlockedDependent('success')['action'], 'upstream success -> queue');
assertSame('cancel', VideoWorkflowProgress::resolveBlockedDependent('cancelled')['action'], 'upstream cancelled -> cancel');
assertSame('fail', VideoWorkflowProgress::resolveBlockedDependent('failed')['action'], 'upstream failed -> fail');
assertSame('fail', VideoWorkflowProgress::resolveBlockedDependent('stale')['action'], 'upstream stale -> fail');
assertSame('wait', VideoWorkflowProgress::resolveBlockedDependent('running')['action'], 'upstream running -> wait');
assertSame('wait', VideoWorkflowProgress::resolveBlockedDependent('queued')['action'], 'upstream queued -> wait');
assertSame('wait', VideoWorkflowProgress::resolveBlockedDependent('blocked')['action'], 'upstream blocked -> wait');

echo "All assertions passed.\n";
