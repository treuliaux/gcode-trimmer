#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use Console\RestartPrintCommand;
use Symfony\Component\Console\Application;

$app = new Application('Console App', 'v1.0.0');
$app->add(new RestartPrintCommand());

$app->run();
