<?php
require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set(\a15lam\Jarvis\Workspace::config()->get('timezone'));
$debug = \a15lam\Jarvis\Workspace::config()->get('debug', false);
$refresh = (isset($argv[1])) ? $argv[1] : false;
$int = \a15lam\Jarvis\Workspace::config()->get('run_interval', 3);

$engine = new \a15lam\Jarvis\Engine($refresh);

while(true) {
    $engine->run();

    if($debug) {
        echo "NOW: " . date('Y-m-d H:i:s', time()) . PHP_EOL;
    }

    sleep($int);
}

//print_r($engine->dumpRules());