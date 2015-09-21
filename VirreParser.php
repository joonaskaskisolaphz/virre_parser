<?php

/**
 * Retrieves companys data from virre.prh.fi
 */

class VirreParser
{

    /**
     * Constructor
     */

    public function __construct( $yaml_file = 'settings.yaml' )
    {
        $this->yaml_file = $yaml_file;

        if ( ! file_exists( $this->yaml_file ) )
        {
            throw new Exception($this->yaml_file.' is missing');
        }

        $this->settings = yaml_parse_file( $this->yaml_file, 0 );
        var_dump($this->settings);

        $this->json_data_file = 'data.json';
        $this->company_info_array = array();
        $this->column_names = array(
            0 => 'y_tunnus',
            1 => 'yrityksen_nimi',
            2 => 'kotipaikka',
            3 => 'diaarinumero',
            4 => 'rekisterointilaji',
            5 => 'rekisterointiajankohta',
            6 => 'rekisteroity_asia',
        );
        $this->cookie_jar = tempnam( sys_get_temp_dir(), 'virre' );
        $this->base_url = 'https://virre.prh.fi/novus/publishedEntriesSearch';
        $this->jssu = 'https://virre.prh.fi/novus/j_security_check'; // j_security_check url

        $this->curl_request( $this->base_url );
    }

    /**
     * Retrieves contents of a webpage
     * @param string $url URL
     * @param array $post_data POST request data ( array('a' => 1, 'b' => 2, ..) )
     * @param string $referer HTTP Referer:
     * @return mixed - urls contents
     * @access private
     */

    private function curl_request( $url, $post_data = array(), $referer = null )
    {
        $ch = curl_init();

        curl_setopt( $ch, CURLOPT_VERBOSE, FALSE );
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_COOKIEJAR, $this->cookie_jar );
        curl_setopt( $ch, CURLOPT_COOKIEFILE, $this->cookie_jar );
        curl_setopt( $ch, CURLOPT_USERAGENT, $this->settings['useragent'] );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );

        if ( null !== $referer )
        {
            curl_setopt( $ch, CURLOPT_REFERER, $referer );
        }

        $http_header = array(
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/"."*;q=0.8",
            "Accept-Encoding: gzip, deflate",
            "Accept-Language: en-US,en;q=0.8",
            "Connection: keep-alive",
            "Cache-Control: max-age=0",
            "Host: virre.prh.fi",
            "HTTPS: 1",
            "Cache-Control: no-cache, no-store",
            "Pragma: no-cache",
        );

        if ( 0 != count( $post_data ) )
        {

            $post_fields = '';
            foreach ( $post_data as $key => $value )
            {
                $post_fields .= $key . "=" . urlencode( $value ) . "&";
            }


            $http_header[] = "Content-Type: application/x-www-form-urlencoded";

            curl_setopt( $ch, CURLOPT_POST, 1 ); // Switch from GET to POST
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
        }
        else
        {
            $http_header[1] .= ", sdch"; // Accept-Encoding:
        }

        curl_setopt( $ch, CURLOPT_HTTPHEADER, $http_header );

        $retrieved_page = curl_exec( $ch );

        $curl_info = curl_getinfo( $ch );

        curl_close( $ch );

        if ( preg_match( '/<title>Security Check<\/title>/', $retrieved_page ) ) {

            preg_match_all( '/<input type="hidden" value="(.*)" name="(.*)"\/>/', $retrieved_page, $res );

            $login_cred_array = array(
                $res[2][0] => $res[1][0], // j_username
                $res[2][1] => $res[1][1], // j_password
            );

            $this->curl_request( $this->jssu, $login_cred_array, "https://virre.prh.fi/novus/home" );

        }
        else if ( preg_match( '/WebServer - Error report/', $retrieved_page ) )
        {
            throw new Exception( "WebServer Error" );
        }

        return array(
            'curl_info' => $curl_info,
            'contents' => $retrieved_page
        );
    }

    /**
     * Retrieves chosen companys data from virre.prh.fi and creates an array of it
     * @param string $businessId Companys businessid (1234567-8)
     * @return array
     * @access public
      */

    public function get_companys_data($businessId = '')
    {

        if ( ! preg_match( '/^[0-9]{7}[-][0-9]{1}$/', $businessId ) ) // check if the given businessId is in the right format
        {
            throw new Exception( "Invalid businessid!" );
        }
        else
        {

            if ( ! in_array( $businessId, $this->settings['business_ids']['active'] ) )
            {
                if ( ($businessId_key = array_search( $businessId, $this->settings['business_ids']['inactive'] )) !== false )
                {
                    // Löytyi 'inactive' listasta, siirretään aktiivisiin
                    unset( $this->settings['business_ids']['inactive'][$businessId_key] );
                }

                $this->settings['business_ids']['active'][] = $businessId;
            }

            $base_url = 'https://virre.prh.fi/novus/publishedEntriesSearch';

            $response = $this->curl_request( $base_url, array(), $base_url );

            if ( $executionId = $this->get_execution_id( $response['curl_info']['url'] ) )
            {

                $search_fields = array(
                    "businessId" => $businessId,
                    "startDate" => "",
                    "endDate" => "",
                    "registrationTypeCode" => "",
                    "_todayRegistered" => "on",
                    "_domicileCode" => "1",
                    "_eventId_search" => "Hae",
                    "execution" => $executionId,
                    "_defaultEventId" => "",
                );

                $data = $this->curl_request( $base_url, $search_fields, $base_url );

                $DOM = new DOMDocument;

                $fixed_html = str_replace( '&', '&amp;', $data['contents'] );
                $DOM->loadHTML( $fixed_html );

                $selector = new DOMXPath( $DOM );
                $results = $selector->query( './/table/tbody/tr/td' );

                $i = 0;
                $ii = 0;

                $this->company_info_array[$businessId] = array();

                foreach ( $results as $node )
                {

                    if ( 7 == $i )
                    {
                        $i = 0;
                        $ii++;
                    }

                    $column_name = $this->column_names[$i];
                    $this->company_info_array[$businessId][$ii][$column_name] = trim( $node->nodeValue );

                    $i++;
                }
            }
        }
    }

    /**
     * Extracts 'execution' id from url for next request
     * @param string $url Current URL
     * @return string Returns the execution id
     * @access private
     */

    private function get_execution_id($url)
    {
        preg_match( '/execution[=]([a-z0-9]+)/', $url, $res );

        if ( isset( $res[1] ) )
        {
            return $res[1];
        }
        else
        {
            return false;
        }
    }

    /**
     * todo
     *
     * @access public
     */

    public function search_active_companys_data()
    {
        foreach ( $this->settings['business_ids']['active'] as $businessId)
        {
            $this->get_companys_data( $businessId );
            echo $businessId.PHP_EOL;
        }
    }

    /**
     * Saves $this->settings to $this->yaml_file
     *
     */

    private function save_settings()
    {
        yaml_emit_file( $this->yaml_file, $this->settings, YAML_UTF8_ENCODING );
    }

    /**
     * Saves new data to $this->json_data_file and send email if necessary
     * @access public
     */

    public function save_data_and_send_mail()
    {

        $mail_contents = '';

        if ( file_exists( $this->json_data_file ) || ( ! file_exists( $this->json_data_file) && touch( $this->json_data_file ) ) )
        {
            $existing_data = file_get_contents( $this->json_data_file );

            try {
                $existing_data_array = json_decode( $existing_data, TRUE );
            } catch (Exception $e) {
                $existing_data_array = array();
            }

            foreach ( $this->company_info_array as $businessId => $businessData )
            {
                if ( ! array_key_exists( $businessId, $existing_data_array ) )
                {
                    $existing_data_array[$businessId] = md5( json_encode( end( $businessData ) ) );
                }
                else
                {
                    if ( md5( json_encode( end( $businessData ) ) ) != $existing_data_array[$businessId] )
                    {
                        $bd_end = end( $businessData );

                        $mail_contents .= $bd_end['yrityksen_nimi'].' ('.$bd_end['y_tunnus'].') '.$bd_end['rekisterointilaji'].' '.$bd_end['rekisterointiajankohta'].' '.$bd_end['rekisteroity_asia'].PHP_EOL;

                        $existing_data_array[$businessId] = md5( json_encode( end( $businessData ) ) );
                    }
                }
            }

            file_put_contents( $this->json_data_file, json_encode( $existing_data_array ) );

            if ( ! empty( $mail_contents ) && ! empty( $this->settings['send_email_to'] ) )
            {
                $mail = new PHPMailer;
                $mail->isSendmail();

                foreach ( $this->settings['send_email_to'] as $mail_to )
                {
                    $mail->addAddress( $mail_to );
                }

                $mail->Subject = utf8_decode( 'Yhden tai useamman yrityksen tietoja päivitetty virreen' );
                $mail->Body = utf8_decode( $mail_contents );

                if ( ! $mail->send() )
                {
                    echo "Mailer Error: " . $mail->ErrorInfo;
                }
                else
                {
                    echo "Message sent!";
                }
            }
        }
    }

    /**
     * Deletes the cookie file we created earlier
     * @access public
     */

    public function __destruct()
    {
        if ( file_exists( $this->cookie_jar ) )
        {
            unset( $this->cookie_jar );
        }
    }
}
