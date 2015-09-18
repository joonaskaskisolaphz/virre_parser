<?php

require 'virreParser.php';
require 'class.phpmailer.php';

$x = new virreParser();

$company_ids_to_check = array(
	'1234567-8',
	'2345678-9',
);

foreach ($company_ids_to_check as $company_id) {
	$x->getCompanysData($company_id);
}
