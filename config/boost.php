<?php

declare(strict_types=1);

return [
    // This project uses generated guidance and skills, not browser instrumentation.
    'browser_logs_watcher' => false,

    // infer-conventions writes .ai/rules via MCP; neither is supported here.
    'skills' => [
        'exclude' => ['infer-conventions'],
    ],

    // Project rules live in AGENTS.md; .ai/rules is not a supported source.
    'rules' => [
        'enabled' => false,
        'scoped_guidelines' => false,
    ],
];
