<?php

return [
    // Cache is never the source of truth. Keep this short so newly approved
    // results become visible quickly while absorbing concurrent dashboard reads.
    'dashboard_cache_ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL_SECONDS', 0),
];
