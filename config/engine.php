<?php

return [
    /*
     * Internal Go engine HTTP endpoint used for setup previews and create/edit
     * validation. It is never exposed through nginx; Compose reaches it on the
     * internal service network. Leave empty to make plan evaluation fail closed.
     */
    'plan_url' => rtrim((string) env('ENGINE_PLAN_URL', 'http://127.0.0.1:8080'), '/'),

    /*
     * Optional shared token. Required only when the engine HTTP port is
     * published beyond the internal network.
     */
    'internal_token' => env('ENGINE_INTERNAL_TOKEN'),

    'plan_timeout' => (float) env('ENGINE_PLAN_TIMEOUT', 5),
];
