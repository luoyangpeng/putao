<?php

use Putao\Worker;

if(!extension_loaded('swoole'))
{
    exit("Please install swoole extension. \n");
}

require_once __DIR__.'/vendor/autoload.php';

$worker = new Worker();

Worker::runAll();
