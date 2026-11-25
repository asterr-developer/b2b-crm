<?php

use App\Jobs\CreateProposalReminders;
use App\Models\DealActivity;
use App\Models\Proposal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || ! in_array(DB::connection()->getDatabaseName(), ['queue_crm_demo', 'queue_release_crm_demo'], true)
    || ! in_array(config('database.redis.options.prefix'), ['crm_demo_queue_', 'crm_demo_queue_release_'], true)) {
    throw new RuntimeException('Use the isolated queue_crm_demo database and crm_demo_queue_ Redis prefix.');
}

$offer = Proposal::where('status', 'sent')->sole();
$key = 'proposal-follow-up:'.$offer->id;
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, $message.PHP_EOL);
};

$queue = Queue::connection('redis');
$work = static function (): void {
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'hospitality-check', '--once' => true, '--tries' => 1]);
    fwrite(STDOUT, Artisan::output());
};
$enqueue = static fn () => $queue->pushOn('hospitality-check', new CreateProposalReminders);
$count = static fn () => DealActivity::where('automation_key', $key)->count();

$assert($count() === 0 && $queue->size('hospitality-check') === 0 && DB::table('failed_jobs')->count() === 0, 'Clean demo fixture and queue verified');
DB::statement('ALTER TABLE deal_activities ADD CONSTRAINT demo_queue_fault CHECK (automation_key IS NULL) NOT VALID');
try {
    $enqueue();
    $work();
    $failed = DB::table('failed_jobs')->sole();
    $assert(str_contains($failed->exception, 'demo_queue_fault') && $count() === 0, 'Worker failure persisted without partial reminder');
} finally {
    DB::statement('ALTER TABLE deal_activities DROP CONSTRAINT demo_queue_fault');
}

Artisan::call('queue:retry', ['id' => [$failed->uuid]]);
$work();
$assert($count() === 1 && DB::table('failed_jobs')->count() === 0, 'Retry recovered the reminder and cleared the failure');
$enqueue();
$work();
$assert($count() === 1, 'Duplicate delivery left exactly one reminder');

$answered = Proposal::create([
    'deal_id' => $offer->deal_id, 'version' => $offer->version + 1, 'snapshot' => $offer->snapshot,
    'status' => 'answered', 'sent_at' => now()->subDays(3), 'follow_up_at' => now()->subDay(),
]);
$enqueue();
$work();
$assert($count() === 1 && ! DealActivity::where('automation_key', 'proposal-follow-up:'.$answered->id)->exists()
    && $queue->size('hospitality-check') === 0, 'A recorded answer suppressed a new reminder for the answered version');
