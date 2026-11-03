<?php

return [
    'require_background_heartbeats' => (bool) env('REQUIRE_BACKGROUND_HEARTBEATS', false),
    'heartbeat_max_age_seconds' => (int) env('BACKGROUND_HEARTBEAT_MAX_AGE', 180),
];
