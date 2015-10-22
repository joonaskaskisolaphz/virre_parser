<?php

require_once __DIR__ . '/businessIdChecker.php';

$given_arguments = $argv;
array_shift($given_arguments);

if (!empty($given_arguments)) {
    foreach ($given_arguments as $given_argument) {
        $company = businessIdChecker::Check($given_argument);
        if ($company) {
            var_dump($company);
        } else {
            echo "ei ole olemassa" . PHP_EOL;
        }
    }
}
