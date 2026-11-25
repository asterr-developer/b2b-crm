<?php

use App\Models\GroupRequest;
use App\Models\User;
use App\Services\HotelInventory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || DB::connection()->getDatabaseName() !== 'inventory_crm_demo') {
    throw new RuntimeException('Use the isolated inventory_crm_demo PostgreSQL database.');
}

$manager = User::whereEmail('manager@hotel.example.test')->sole();
if (($argv[1] ?? null) === 'worker') {
    DB::selectOne('SELECT set_config(?, ?, false)', ['application_name', 'crm-room-'.$argv[2]]);
    try {
        app(HotelInventory::class)->reserve($manager, (int) $argv[2], ['category' => (int) $argv[3], 'rooms' => 1, 'hours' => 1]);
        echo 'held';
    } catch (ValidationException $error) {
        if (! str_starts_with($error->errors()['rooms'][0] ?? '', 'На одну или несколько ночей не хватает номеров.')) {
            throw $error;
        }
        echo 'no room';
    }
    exit;
}

$groups = GroupRequest::visibleTo($manager)->whereIn('title', ['Группа на выставку', 'Группа на семинар'])->get();
$category = DB::table('hotel_room_types')->where('department_id', $manager->department_id)->where('capacity', 1)->sole();
if ($groups->count() !== 2 || DB::table('hotel_allocations')->where('hotel_room_type_id', $category->id)->exists()) {
    throw new RuntimeException('Seed a fresh verification database.');
}

$workers = [];
DB::beginTransaction();
try {
    DB::table('hotel_room_types')->lockForUpdate()->find($category->id);
    foreach ($groups as $group) {
        $worker = new Process([PHP_BINARY, __FILE__, 'worker', (string) $group->id, (string) $category->id], timeout: 25);
        $worker->start();
        $workers[] = $worker;
    }

    $deadline = microtime(true) + 15;
    do {
        DB::selectOne('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) DB::selectOne("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()
            AND application_name LIKE 'crm-room-%' AND wait_event_type = 'Lock'")->count;
        if ($waiting === 2) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    if ($waiting !== 2) {
        throw new RuntimeException('Both sessions must reach the same inventory lock.');
    }
    DB::commit();
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

$results = [];
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
    }
    $results[] = trim($worker->getOutput());
}
sort($results);
if ($results !== ['held', 'no room'] || DB::table('hotel_allocations')->where('hotel_room_type_id', $category->id)->count() !== 1) {
    throw new RuntimeException('Expected one reservation and one rejection.');
}
echo "Two PostgreSQL sessions, one reservation, one rejection.\n";
