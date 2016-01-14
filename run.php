<?php

require_once __DIR__ . '/vendor/autoload.php';

$virre = new VirreParser();

$arguments = $argv;
array_shift($arguments);

$virre->searchCompanysData();

if (!empty($arguments)) {
    foreach ($arguments as $argument) {
        $virre->getCompanysData($argument);
    }
}

$virre->saveData();
