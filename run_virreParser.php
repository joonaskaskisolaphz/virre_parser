<?php

require 'virreParser.php';
require 'class.phpmailer.php';

$x = new virreParser();

$given_arguments = $argv;
array_shift($given_arguments);

if ( ! empty($given_arguments)) {
	foreach ($given_arguments as $given_argument) {
		$x->getCompanysData($given_argument);
	}
}
else
{
	die('Usage: php '.$argv[0].' 1234567-8'.PHP_EOL);
}
