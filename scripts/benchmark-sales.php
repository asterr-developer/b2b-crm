<?php

use App\Http\Controllers\PipelineController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\TaskController;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || DB::connection()->getDatabaseName() !== 'perf_crm_demo') {
    throw new RuntimeException('Use the isolated perf_crm_demo database.');
}

if (Deal::count() === 0) {
    DB::transaction(function () {
        $department = Department::create(['name' => 'Synthetic performance']);
        $user = User::create(['name' => 'Performance fixture', 'email' => 'benchmark@example.test', 'password' => Str::random(32)]);
        $user->forceFill(['department_id' => $department->id, 'sales_role' => 'lead'])->save();
        $company = Company::create(['department_id' => $department->id, 'user_id' => $user->id, 'name' => 'Benchmark Travel']);
        $contact = $company->contacts()->create(['name' => 'Synthetic contact', 'email' => 'contact@example.test']);

        DB::insert(<<<'SQL'
            INSERT INTO deals (contact_id, assignee_id, department_id, title, amount, stage, expected_close_on, closed_at, created_at, updated_at)
            SELECT ?, ?, ?, 'Synthetic deal ' || n, n, (ARRAY['new','qualified','proposal','won','lost'])[1 + n % 5],
                CURRENT_DATE + (n % 91), CASE WHEN n % 5 IN (3, 4) THEN CURRENT_TIMESTAMP ELSE NULL END,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM generate_series(1, 50000) n
            SQL, [$contact->id, $user->id, $department->id]);

        DB::insert(<<<'SQL'
            INSERT INTO deal_transitions (deal_id, user_id, from_stage, to_stage, created_at)
            SELECT deals.id, ?, transitions.from_stage, transitions.to_stage, CURRENT_TIMESTAMP
            FROM deals
            CROSS JOIN (VALUES
                ('new', 'qualified', ARRAY['qualified','proposal','won']),
                ('qualified', 'proposal', ARRAY['proposal','won']),
                ('proposal', 'won', ARRAY['won']),
                ('new', 'lost', ARRAY['lost'])
            ) AS transitions(from_stage, to_stage, stages)
            WHERE deals.stage = ANY(transitions.stages)
            SQL, [$user->id]);

        DB::insert(<<<'SQL'
            INSERT INTO deal_activities (deal_id, kind, body, due_at, completed_at, created_at, updated_at)
            SELECT deals.id, 'task', 'Synthetic task ' || n, CURRENT_TIMESTAMP - (n || ' days')::interval,
                CASE WHEN n % 2 = 0 THEN CURRENT_TIMESTAMP ELSE NULL END, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM deals CROSS JOIN generate_series(1, 4) n
            SQL);
    });
    DB::statement('ANALYZE');
}

$actor = User::whereEmail('benchmark@example.test')->sole();
echo 'Fixture: '.Deal::count().' deals, '.DB::table('deal_activities')->count()." activities.\n";

foreach ([
    'board' => [PipelineController::class, 'index', '/dashboard'],
    'tasks' => [TaskController::class, '__invoke', '/tasks?filter=overdue'],
    'reports' => [SalesReportController::class, '__invoke', '/reports'],
] as $label => [$controller, $method, $path]) {
    $ms = [];
    for ($sample = 0; $sample < 22; $sample++) {
        $request = Request::create($path, 'GET', server: ['HTTP_X_INERTIA' => 'true']);
        $request->setUserResolver(fn () => $actor);
        $request->setLaravelSession(app('session.store'));
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);
        $response = app()->call([app($controller), $method], ['request' => $request])->toResponse($request);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        if ($sample >= 2) {
            $ms[] = $elapsed;
        }
    }
    sort($ms);
    printf("%s | p50 %.1f ms | p95 %.1f ms | %d queries | %d bytes\n",
        $label, $ms[9], $ms[18], $queries, strlen($response->getContent()));
}
