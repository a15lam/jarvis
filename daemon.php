<?php
require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set(\a15lam\Jarvis\Workspace::config()->get('timezone'));
$debug = \a15lam\Jarvis\Workspace::config()->get('debug', false);
$refresh = (isset($argv[1])) ? $argv[1] : false;
$int = \a15lam\Jarvis\Workspace::config()->get('run_interval', 3);

$engine = new \a15lam\Jarvis\Engine($refresh);

while (true) {
    try {
        $engine->run();

        if ($debug) {
            echo "=======================[ RUN:" . date('Y-m-d H:i:s', time()) . " ]=======================" . PHP_EOL;
        }
    } catch (\Exception $e) {
        \a15lam\Jarvis\Workspace::log()->error('Error occurred: ' . $e->getMessage());
        throw $e;
    }
    sleep($int);
}

//print_r($engine->dumpRules());