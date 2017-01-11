<?php
use a15lam\Jarvis\Workspace as ws;
use a15lam\Workspace\Utility\Logger;

return [
    'debug'        => ws::env('DEBUG', false),
    'log_level'    => Logger::getLevelValue(ws::env('LOG_LEVEL', 'INFO')),
    'log_path'     => ws::env('LOG_PATH', 'storage/logs/'),
    'timezone'     => ws::env('TIME_ZONE', 'America/New_York'),
    'latitude'     => ws::env('LATITUDE', 0),
    'longitude'    => ws::env('LONGITUDE', 0),
    'zenith'       => ws::env('ZENITH', 90 + (50 / 60)), // True sunrise/sunset
    'rule_path'    => __DIR__ . DIRECTORY_SEPARATOR . ws::env('RULE_PATH', 'rule.json'),
    'run_interval' => ws::env('RUN_INTERVAL', 3)  // Seconds.
];