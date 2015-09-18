<?php

require 'virreParser.php';
require 'class.phpmailer.php';

$virre = new virreParser();

$given_arguments = $argv;
array_shift($given_arguments);

if ( ! empty($given_arguments)) {
	foreach ($given_arguments as $given_argument) {
		$virre->getCompanysData($given_argument);
	}
}
else
{
	die('Usage: php '.$argv[0].' 1234567-8 2345678-9 3456789-1'.PHP_EOL);
}
