<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

if ($argc !== 4 || ! in_array($argv[3], ['write', 'read', 'cleanup'], true)) {
    fwrite(STDERR, "Usage: php tools/acceptance-probe.php WORKSPACE VALUE write|read|cleanup\n");
    exit(2);
}

$workspace = realpath($argv[1]);
if ($workspace === false || ! is_file($workspace.'/artisan')) {
    fwrite(STDERR, "Acceptance workspace is invalid.\n");
    exit(2);
}

chdir($workspace);
require $workspace.'/vendor/autoload.php';
$application = require $workspace.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$value = $argv[2];
$mode = $argv[3];
$cache = $application->make(CacheFactory::class)->store();
$queue = $application->make(QueueFactory::class)->connection('redis');
$queueName = (string) config('queue.connections.redis.queue', 'default');

if ($mode === 'cleanup') {
    $cache->forget('harbour-acceptance-cache');
    $redis = $application->make('redis')->connection((string) config('queue.connections.redis.connection', 'default'));
    $redis->command('del', [[
        'queues:'.$queueName,
        'queues:'.$queueName.':delayed',
        'queues:'.$queueName.':reserved',
        'queues:'.$queueName.':notify',
    ]]);
    echo "clean\n";
    exit(0);
}

$locked = null;
$lock = null;
if ($mode === 'write') {
    $cache->put('harbour-acceptance-cache', $value, 300);
    $lock = $cache->lock('harbour-acceptance-lock', 30);
    $locked = $lock->get();
    $queue->pushRaw(json_encode(['workspace' => $value], JSON_THROW_ON_ERROR), $queueName);
    sleep(2);
}

$result = [
    'cache' => $cache->get('harbour-acceptance-cache'),
    'lock_acquired' => $locked,
    'queue_size' => $queue->size($queueName),
    'queue_name' => $queueName,
    'redis_prefix' => (string) config('database.redis.options.prefix'),
    'cache_prefix' => (string) config('cache.prefix'),
    'session_cookie' => (string) config('session.cookie'),
];

if ($lock !== null && $locked === true) {
    $lock->release();
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
