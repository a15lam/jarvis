<?php
require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set(\a15lam\Jarvis\Workspace::config()->get('timezone'));
$debug = \a15lam\Jarvis\Workspace::config()->get('debug', false);

$engine = new \a15lam\Jarvis\Engine();

while(true) {
    $engine->run();

    if($debug) {
        echo "NOW: " . date('Y-m-d H:i:s', time()) . PHP_EOL;
    }

    if($debug) {
        sleep(5);
    } else {
        sleep(10);
    }
}
//print_r($engine->dumpRules());