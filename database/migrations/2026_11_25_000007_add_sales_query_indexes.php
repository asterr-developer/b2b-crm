<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'deals' => [
            'deals_board_department_index' => ['department_id', 'sales_pipeline_id', 'stage', 'id'],
            'deals_board_assignee_index' => ['assignee_id', 'department_id', 'sales_pipeline_id', 'stage', 'id'],
        ],
        'deal_transitions' => ['transitions_deal_history_index' => ['deal_id', 'id']],
        'deal_activities' => ['activities_deal_task_index' => ['deal_id', 'kind', 'completed_at', 'due_at']],
        'proposals' => ['proposals_pending_reminder_index' => ['status', 'sent_at']],
        'sales_events' => [
            'sales_events_deal_history_index' => ['deal_id', 'id'],
            'sales_events_request_history_index' => ['group_request_id', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
