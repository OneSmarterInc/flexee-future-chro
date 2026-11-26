<?php

return [
    /*
    | The AI behind the voices at the table, the reply to a team's paragraph, the class reading and the
    | debrief text. With no API key every AI feature is off and the fixed replies still work. The key lives
    | only in the server's .env. The model is set here so it can change without a code change.
    */
    'ai' => [
        'anthropic_key' => env('ANTHROPIC_API_KEY', ''),
        // On when the key is set. ASHBY_AI_ENABLED=false turns every AI feature off for the whole server.
        'enabled' => filter_var(env('ASHBY_AI_ENABLED', env('ANTHROPIC_API_KEY', '') !== ''), FILTER_VALIDATE_BOOL),
        'model' => env('ASHBY_AI_MODEL', 'claude-sonnet-5-5'),
        'usd_per_million_input' => (float) env('ASHBY_AI_USD_PER_MILLION_INPUT', 3.0),
        'usd_per_million_output' => (float) env('ASHBY_AI_USD_PER_MILLION_OUTPUT', 15.0),
    ],

    /*
    | Whether the site sends email: a participant's sign-in when an instructor adds them, a note when they're added
    | with a sign-in they already have, and reminders before a year closes. Off until the server's mail lines are set
    | (MAIL_MAILER other than log); the screens say so.
    */
    'mail_enabled' => filter_var(env('ASHBY_MAIL', env('MAIL_MAILER', 'log') !== 'log'), FILTER_VALIDATE_BOOL),

    /*
    | The read-only reporting connector at /mcp/reporting (Flexee family contract v1). Only administrators can
    | connect. Claude's published identity is read from these hosts only, and sign-ins return only to these hosts.
    */
    'reporting' => [
        'enabled' => filter_var(env('ASHBY_REPORTING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'sim' => 'future_chro',
        'contract' => 'v1',
        'client_metadata_hosts' => ['claude.ai', 'claude.com'],
        'redirect_hosts' => ['claude.ai', 'claude.com', 'localhost', '127.0.0.1'],
        'access_minutes' => 60,
        'refresh_days' => 30,
        'calls_per_minute' => 120,
        'closes_days' => 90,
    ],
];
