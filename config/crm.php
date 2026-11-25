<?php

return [
    'require_verified_email' => env('CRM_REQUIRE_VERIFIED_EMAIL', env('APP_ENV', 'production') === 'production'),
    'intake_webhook_secret' => env('CRM_INTAKE_WEBHOOK_SECRET'),
    'intake_webhook_assignee' => env('CRM_INTAKE_WEBHOOK_ASSIGNEE'),
    'proposal_email' => env('CRM_PROPOSAL_EMAIL', false),
    'reply_webhook_secret' => env('CRM_REPLY_WEBHOOK_SECRET'),
    'reply_webhook_account' => env('CRM_REPLY_WEBHOOK_ACCOUNT'),
    'ollama_model' => env('CRM_OLLAMA_MODEL'),
    'erp' => [
        'department_id' => env('CRM_ERP_DEPARTMENT_ID'),
        'read_token' => env('CRM_ERP_READ_TOKEN'),
        'write_secret' => env('CRM_ERP_WRITE_SECRET'),
    ],
];
