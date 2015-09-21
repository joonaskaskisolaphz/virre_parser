<?php

require 'VirreParser.php';
require 'class.phpmailer.php';

$virre = new VirreParser();

$given_arguments = $argv;
array_shift( $given_arguments );

// Käydään ensin läpi vanhat, sitten uudet

$virre->search_active_companys_data();

if ( ! empty( $given_arguments ))
{
    foreach ( $given_arguments as $given_argument )
    {
        $virre->get_companys_data( $given_argument );
    }
}

$virre->save_data_and_send_mail();
