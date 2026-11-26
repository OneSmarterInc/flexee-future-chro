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
    ],
];
