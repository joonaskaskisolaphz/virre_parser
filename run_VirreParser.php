<?php

require 'VirreParser.php';
require 'class.phpmailer.php';

$virre = new VirreParser();

$given_arguments = $argv;
array_shift( $given_arguments );

// Lets first go through the active customers list and fetch their data
$virre->search_active_companys_data();

// Then check if we have any new customers (from cli) and fetch their data also
if ( ! empty( $given_arguments ))
{
    foreach ( $given_arguments as $given_argument )
    {
        $virre->get_companys_data( $given_argument );
    }
}

$virre->save_data_and_send_mail();
