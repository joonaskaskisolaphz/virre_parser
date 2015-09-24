<?php

/**
 * Retrieves companys data from virre.prh.fi
 */

class VirreParser
{

    /**
     * Constructor
     */

    public function __construct( $yaml_file = null )
    {

        if ( ! $yaml_file )
        {
            $this->yaml_file = __DIR__.'/settings.yaml';
        }
        else
        {
            $this->yaml_file = $yaml_file;
        }

        if ( ! file_exists( $this->yaml_file ) )
        {
            throw new Exception( $this->yaml_file.' is missing' );
        }

        $this->settings = yaml_parse_file( $this->yaml_file, 0 );

        $this->json_data_file = __DIR__.'/data.json';
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
        $this->j_security_check = 'https://virre.prh.fi/novus/j_security_check'; /* j_security_check url */

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

    private function curl_request( $url, $post_data = array(), $referer = null, $page_is_gzipped = FALSE )
    {

        sleep( rand( 5, 20 ) ); /* Sleep 5-20sec so we dont look like a bot so much */

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

        if ( preg_match( '/virre\.prh\.fi/', $url ) )
        {
            $http_header_host = 'virre.prh.fi';
        }
        else if ( preg_match( '/ytj\.fi/', $url ) )
        {
            $http_header_host = 'www.ytj.fi';
        }

        $http_header = array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/'.'*;q=0.8',
            'Accept-Encoding: gzip, deflate',
            'Accept-Language: en-US,en;q=0.8',
            'Connection: keep-alive',
            'Cache-Control: max-age=0',
            'Host: '.$http_header_host,
            'HTTPS: 1',
            'Cache-Control: no-cache, no-store',
            'Pragma: no-cache',
        );

        if ( 0 != count( $post_data ) )
        {

            $post_fields = '';
            foreach ( $post_data as $key => $value )
            {
                $post_fields .= urlencode( $key ) . '=' . urlencode( $value ) . '&';
            }

            $http_header[] = 'Content-Type: application/x-www-form-urlencoded';

            curl_setopt( $ch, CURLOPT_POST, 1 ); /* Switch from GET to POST */
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
        }
        else
        {
            $http_header[1] .= ', sdch'; /* Accept-Encoding: */
        }

        curl_setopt( $ch, CURLOPT_HTTPHEADER, $http_header );

        $retrieved_page = curl_exec( $ch );

        if ( $page_is_gzipped )
        {
            if ( function_exists( 'gzdecode' ) )
            {
                $retrieved_page = gzdecode( $retrieved_page );
            }
            else
            {
                $retrieved_page = gzinflate( substr( $retrieved_page, 10, -8 ) );
            }
        }

        $curl_info = curl_getinfo( $ch );

        curl_close( $ch );

        if ( preg_match( '/<title>Security Check<\/title>/', $retrieved_page ) ) {

            preg_match_all( '/<input type="hidden" value="(.*)" name="(.*)"\/>/', $retrieved_page, $res );

            $login_cred_array = array(
                $res[2][0] => $res[1][0], /* j_username */
                $res[2][1] => $res[1][1], /* j_password */
            );

            $this->curl_request( $this->j_security_check, $login_cred_array, 'https://virre.prh.fi/novus/home' );

        }
        else if ( preg_match( '/WebServer - Error report/', $retrieved_page ) )
        {
            throw new Exception( 'WebServer Error' );
        }

        return array(
            'curl_info' => $curl_info,
            'contents' => $retrieved_page
        );
    }

    /**
     * Retrieves chosen companys data from virre.prh.fi and creates an array of it
     * @param string $business_id Companys businessid (1234567-8)
     * @return array
     * @access public
      */

    public function get_companys_data($business_id = '')
    {

        if ( ! preg_match( '/^[0-9]{7}[-][0-9]{1}$/', $business_id ) ) /* Check if the given businessId is in the right format */
        {
            throw new Exception( 'Invalid businessid!' );
        }
        else
        {

            if ( ! in_array( $business_id, $this->settings['business_ids']['active'] ) )
            {
                if ( ($business_id_key = array_search( $business_id, $this->settings['business_ids']['inactive'] )) !== false )
                {
                    /* Business id is found in the 'inactive' list, moving to 'active' list */
                    unset( $this->settings['business_ids']['inactive'][$business_id_key] );
                }

                $this->settings['business_ids']['active'][] = $business_id;
            }

            $base_url = 'https://virre.prh.fi/novus/publishedEntriesSearch';

            $response = $this->curl_request( $base_url, array(), $base_url );

            if ( $executionId = $this->get_execution_id( $response['curl_info']['url'] ) )
            {

                $search_fields = array(
                    'businessId' => $business_id,
                    'startDate' => '',
                    'endDate' => '',
                    'registrationTypeCode' => '',
                    '_todayRegistered' => 'on',
                    '_domicileCode' => '1',
                    '_eventId_search' => 'Hae',
                    'execution' => $executionId,
                    '_defaultEventId' => '',
                );

                $data = $this->curl_request( $base_url, $search_fields, $base_url );

                /* Fixes & characters that dont have ; with them */
                $amp_fix = preg_replace( '/&(?![A-Za-z]+;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $data['contents'] );

                $DOM = new DOMDocument;
                $DOM->loadHTML( $amp_fix );

                $selector = new DOMXPath( $DOM );
                $results = $selector->query( './/table/tbody/tr/td' );

                $i = 0;
                $ii = 0;

                if ( 0 != $results->length )
                {
                    $this->company_info_array[$business_id] = array();

                    foreach ( $results as $node )
                    {

                        if ( 7 == $i )
                        {
                            $i = 0;
                            $ii++;
                        }

                        $column_name = $this->column_names[$i];
                        $this->company_info_array[$business_id][$ii][$column_name] = trim( $node->nodeValue );

                        $i++;
                    }
                }
                else
                {
                    $this->get_companys_data_from_ytj( $business_id );
                }
            }
        }
    }

    /**
     * Retrieves chosen companys data from ytj.fi and creates an array of it
     * @param string $business_id Companys businessid (1234567-8)
     * @return array
     * @access private
      */

    private function get_companys_data_from_ytj( $business_id )
    {

        $base_url = 'https://www.ytj.fi/yrityshaku.aspx';
        $search_url = 'https://www.ytj.fi/yrityshaku.aspx?path=1547';

        $response = $this->curl_request( $base_url, array(), $base_url, TRUE );

        preg_match_all( '/<input type="hidden" name="(.*)" id=".*" value="(.*)" \/>/', $response['contents'], $res );

        $post_array = array();
        $i = 0;

        foreach ( $res[1] as $post_field )
        {
            $post_array[$post_field] = $res[2][$i];
            $i++;
        }

        $post_array['_ctl0:ContentPlaceHolder:hakusana'] = '';
        $post_array['_ctl0:ContentPlaceHolder:ytunnus'] = $business_id;
        $post_array['_ctl0:ContentPlaceHolder:yrmu'] = '';
        $post_array['_ctl0:ContentPlaceHolder:LEItunnus'] = '';
        $post_array['_ctl0:ContentPlaceHolder:sort'] = 'sort1';
        $post_array['_ctl0:ContentPlaceHolder:suodatus'] = 'suodatus1';
        $post_array['_ctl0:ContentPlaceHolder:Hae'] = 'Hae+yritykset';

        $data = $this->curl_request( $search_url, $post_array, $base_url, TRUE );
        $companys_link_found = preg_match( '/<a id="ContentPlaceHolder_rptHakuTulos_HyperLink1_0" href="(.*)">/', $data['contents'], $companys_link );

        if ( $companys_link_found )
        {
            $companys_data = $this->curl_request( 'https://www.ytj.fi/'.str_replace( '&amp;', '&', $companys_link[1] ), array(), $search_url, TRUE );

            /* Fixes & characters that dont have ; with them */
            $amp_fix = preg_replace( '/&(?![A-Za-z]+;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $companys_data['contents'] );

            $DOM = new DOMDocument;
            $DOM->loadHTML( $amp_fix );

            $xpath = new DOMXPath( $DOM );
            $elements = $xpath->query( "/"."/"."*[@id='detail-result']/table" )->item(1);

            preg_match( '/<span id="ContentPlaceHolder_lblToiminimi">(.*)<\/span>/', $companys_data['contents'], $companys_name );

            $i = 0;

            foreach ( $elements->childNodes as $node )
            {
                if ( 0 != $i ) // Skip the header info
                {
                    $ii = 0;

                    foreach ( $node->childNodes as $trnode )
                    {
                        $explode = explode( PHP_EOL, trim( $trnode->nodeValue ) );

                        foreach ( $explode as $row )
                        {
                            $row = preg_replace( '~\xc2\xa0~', '', trim( $row ) ); // Remove some weird characters

                            if ( ! empty( $row ) )
                            {
                                switch ( $ii )
                                {
                                    case 0:
                                        $this->company_info_array[$business_id][$i]['y_tunnus'] = $business_id;
                                        $this->company_info_array[$business_id][$i]['yrityksen_nimi'] = $companys_name[1];
                                        $this->company_info_array[$business_id][$i]['kotipaikka'] = '';
                                        $this->company_info_array[$business_id][$i]['diaarinumero'] = '';
                                        $this->company_info_array[$business_id][$i]['rekisteroity_asia'] = trim( $row );
                                        break;

                                    case 1:
                                        $this->company_info_array[$business_id][$i]['rekisterointilaji'] = trim( $row );
                                        break;

                                    case 2:
                                        $this->company_info_array[$business_id][$i]['rekisterointiajankohta'] = trim( $row );
                                        $ii = 0;
                                        break;
                                }

                                $ii++;
                            }
                        }
                    }
                }

                $i++;
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
     * Goes through active businessids
     * @access public
     */

    public function search_active_companys_data()
    {
        foreach ( $this->settings['business_ids']['active'] as $business_id )
        {
            $this->get_companys_data( $business_id );
        }
    }

    /**
     * Saves $this->settings to $this->yaml_file
     * @access public
     */

    private function save_settings()
    {
        yaml_emit_file( $this->yaml_file, $this->settings );
    }

    /**
     * Adds companies names to $this->yaml_file for easier editing
     * @access private
     */

    private function add_companies_names_to_yaml_file()
    {
        $yaml_data = file_get_contents( $this->yaml_file );

        foreach ( $this->company_info_array as $business_id => $business_data )
        {
            $last_business_data = end( $business_data );

            $companys_name = $last_business_data['yrityksen_nimi'];

            $yaml_data = preg_replace( '/'.$business_id.'/', $business_id.' # '.$companys_name, $yaml_data );
        }

        file_put_contents( $this->yaml_file, $yaml_data );
    }

    /**
     * Saves new data to $this->json_data_file and send email if necessary
     * @access public
     */

    public function save_data_and_send_mail()
    {
        $this->save_settings();

        $mail_contents = '';

        if ( file_exists( $this->json_data_file ) || ( ! file_exists( $this->json_data_file) && touch( $this->json_data_file ) ) )
        {
            $existing_data = file_get_contents( $this->json_data_file );

            try {
                $existing_data_array = json_decode( $existing_data, TRUE );
            } catch (Exception $e) {
                $existing_data_array = array();
            }

            foreach ( $this->company_info_array as $business_id => $business_data )
            {
                if ( ! array_key_exists( $business_id, $existing_data_array ) )
                {
                    $existing_data_array[$business_id] = md5( json_encode( end( $business_data ) ) );
                }
                else
                {
                    if ( md5( json_encode( end( $business_data ) ) ) != $existing_data_array[$business_id] )
                    {
                        $bd_end = end( $business_data );

                        $mail_contents .= $bd_end['yrityksen_nimi'].' ('.$bd_end['y_tunnus'].') '.$bd_end['rekisterointilaji'].' '.$bd_end['rekisterointiajankohta'].' '.$bd_end['rekisteroity_asia'].PHP_EOL;

                        $existing_data_array[$business_id] = md5( json_encode( end( $business_data ) ) );
                    }
                }
            }

            $this->add_companies_names_to_yaml_file();

            file_put_contents( $this->json_data_file, json_encode( $existing_data_array ) );

            if ( ! empty( $mail_contents ) && ! empty( $this->settings['send_email_to'] ) )
            {
                $mail = new PHPMailer;
                $mail->isSendmail();

                foreach ( $this->settings['send_email_to'] as $mail_to )
                {
                    $mail->addAddress( $mail_to );
                }

                $mail->SetFrom( $this->settings['mail_from_addr'], $this->settings['mail_from_name'] );
                $mail->Subject = utf8_decode( 'Yhden tai useamman yrityksen tietoja päivitetty virreen' );
                $mail->Body = utf8_decode( $mail_contents );

                if ( ! $mail->send() )
                {
                    echo 'Mailer Error: ' . $mail->ErrorInfo;
                }
                else
                {
                    echo 'Message sent!';
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
