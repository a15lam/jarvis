<?php
return [
    'debug'        => false,
    'timezone'     => 'America/New_York',
    'latitude'     => 34.1939770,      //North
    'longitude'    => -84.2247560,     //West
    'zenith'       => 90 + (50 / 60),  // True sunrise/sunset
    'rule_path'    => __DIR__ . DIRECTORY_SEPARATOR . 'rule.json',
    'run_interval' => 3 // seconds;
];