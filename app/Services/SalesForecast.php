<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesForecast
{
    private const WEIGHTED = "CAST(ROUND(amount * CASE stage WHEN 'new' THEN 10 WHEN 'qualified' THEN 35 ELSE 70 END) AS BIGINT)";

    public function current(Builder $visible): array
    {
        return $this->eligible($visible)
            ->selectRaw('substr(CAST(expected_close_on AS TEXT), 1, 7) AS month, SUM('.self::WEIGHTED.') AS weighted_minor')
            ->groupBy('month')->orderBy('month')->pluck('weighted_minor', 'month')->map(fn ($amount) => (int) $amount)->all();
    }

    public function capture(): ?int
    {
        $date = today()->toDateString();

        return DB::transaction(function () use ($date) {
            if (! DB::table('forecast_runs')->insertOrIgnore(['captured_on' => $date, 'created_at' => now()])) {
                return null;
            }

            $dateParameter = DB::connection()->getDriverName() === 'pgsql' ? 'CAST(? AS DATE)' : '?';
            $rows = $this->eligible(Deal::query())->whereNotNull('assignee_id')
                ->selectRaw($dateParameter.' AS captured_on, id, department_id, assignee_id, expected_close_on, '.self::WEIGHTED, [$date]);

            return DB::table('forecast_snapshots')->insertUsing([
                'captured_on', 'deal_id', 'department_id', 'assignee_id', 'expected_close_on', 'weighted_minor',
            ], $rows);
        }, 3);
    }

    public function history(User $user, ?string $selected): array
    {
        $history = DB::table('forecast_snapshots')->where('department_id', $user->department_id);
        if (! $user->leadsSalesDepartment()) {
            $history->where('assignee_id', $user->id);
        }

        $days = (clone $history)->distinct()->orderByDesc('captured_on')->limit(30)->pluck('captured_on');
        $day = $selected ?? $days->first();
        $totals = $day === null ? [] : $history->where('captured_on', $day)
            ->selectRaw('substr(CAST(expected_close_on AS TEXT), 1, 7) AS month, SUM(weighted_minor) AS weighted_minor')
            ->groupBy('month')->orderBy('month')->pluck('weighted_minor', 'month')->map(fn ($amount) => (int) $amount)->all();

        return ['days' => $days, 'active' => $day, 'totals' => (object) $totals];
    }

    private function eligible(Builder $query): Builder
    {
        return $query->whereIn('stage', ['new', 'qualified', 'proposal'])->whereNotNull('expected_close_on');
    }
}
