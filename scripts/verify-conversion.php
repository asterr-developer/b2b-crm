<?php

use App\Models\Deal;
use App\Models\GroupRequest;
use App\Models\SalesEvent;
use App\Models\User;
use App\Services\ConvertGroupRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || DB::connection()->getDatabaseName() !== 'concurrency_crm_demo') {
    throw new RuntimeException('Use the isolated concurrency_crm_demo database.');
}

if (($argv[1] ?? null) === 'worker') {
    $actor = User::whereEmail($argv[2])->sole();
    DB::selectOne('SELECT set_config(?, ?, false)', ['application_name', 'crm-convert-'.$actor->id]);
    echo $app->make(ConvertGroupRequest::class)->handle($actor, (int) $argv[3]);
    exit;
}

$group = GroupRequest::where('status', 'received')->sole();
$group->update(['status' => 'qualified', 'arrival_on' => '2027-02-10', 'departure_on' => '2027-02-12', 'guests' => 24]);
$before = Deal::count();
$workers = [];
DB::beginTransaction();
try {
    GroupRequest::lockForUpdate()->findOrFail($group->id);
    foreach (['manager@hotel.example.test', 'lead@hotel.example.test'] as $email) {
        $worker = new Process([PHP_BINARY, __FILE__, 'worker', $email, (string) $group->id]);
        $worker->setTimeout(20);
        $worker->start();
        $workers[] = $worker;
    }

    $deadline = microtime(true) + 10;
    do {
        DB::selectOne('SELECT pg_stat_clear_snapshot()');
        $blocked = (int) DB::selectOne(
            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE 'crm-convert-%' AND wait_event_type = 'Lock'"
        )->count;

        if ($blocked === 2) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    if ($blocked !== 2) {
        throw new RuntimeException('Both sessions did not reach the row lock.');
    }
    echo "Both independent PostgreSQL sessions are waiting on the row lock.\n";
    DB::commit();
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

$ids = [];
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
    }
    $ids[] = (int) trim($worker->getOutput());
}

if ($ids !== [$group->refresh()->deal_id, $group->deal_id] || Deal::count() !== $before + 1
    || SalesEvent::where('group_request_id', $group->id)->where('action', 'request.converted')->count() !== 1) {
    throw new RuntimeException('Expected both sessions to receive the same new deal and record one event.');
}
echo "One deal, one conversion event, the same deal ID in both sessions.\n";
