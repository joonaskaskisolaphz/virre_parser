<?php

/**
 * Checks if business exists or not according to virre &/ ytj
 */
class businessIdChecker
{
    public static function settings()
    {
        return array(
            'cookie_jar' => '/tmp/bidc.cookies',
            'base_url' => 'https://virre.prh.fi/novus/publishedEntriesSearch',
            'j_security_check' => 'https://virre.prh.fi/novus/j_security_check', /* j_security_check url */
            'useragent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/44.0.2403.89 Chrome/44.0.2403.89 Safari/537.36',
            'column_names' => array(
                0 => 'y_tunnus',
                1 => 'yrityksen_nimi',
                2 => 'kotipaikka',
                3 => 'diaarinumero',
                4 => 'rekisterointilaji',
                5 => 'rekisterointiajankohta',
                6 => 'rekisteroity_asia',
            )
        );
    }

    public static function Check($business_id)
    {
        $settings = self::settings();
        return self::get_companys_data($settings, $business_id);
    }

    /**
     * Retrieves contents of a webpage
     */
    private static function curl_request($settings, $url, $post_data = array(), $referer = null, $page_is_gzipped = FALSE)
    {
        $ch = curl_init();

        curl_setopt( $ch, CURLOPT_VERBOSE, FALSE );
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_COOKIEJAR, $settings['cookie_jar'] );
        curl_setopt( $ch, CURLOPT_COOKIEFILE, $settings['cookie_jar'] );
        curl_setopt( $ch, CURLOPT_USERAGENT, $settings['useragent'] );
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

            self::curl_request($settings, $settings['j_security_check'], $login_cred_array, 'https://virre.prh.fi/novus/home');

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
     * @access private
     */
    private static function get_companys_data($settings, $business_id = '')
    {
        $company_info_array = array();

        if ( ! preg_match( '/^[0-9]{7}[-][0-9]{1}$/', $business_id ) ) /* Check if the given businessId is in the right format */
        {
            throw new Exception( 'Invalid businessid!' );
        }
        else
        {

            $base_url = 'https://virre.prh.fi/novus/publishedEntriesSearch';

            $response = self::curl_request($settings, $base_url, array(), $base_url);

            if ( $executionId = self::get_execution_id( $response['curl_info']['url'] ) )
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

                $data = self::curl_request($settings, $base_url, $search_fields, $base_url);

                /* Fixes & characters that dont have ; with them */
                $amp_fix = preg_replace( '/&(?![A-Za-z]+;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $data['contents'] );

                $DOM = new DOMDocument;
                @$DOM->loadHTML( $amp_fix );

                $selector = new DOMXPath( $DOM );
                $results = $selector->query( './/table/tbody/tr/td' );

                $i = 0;
                $ii = 0;

                if ( 0 != $results->length )
                {
                    $company_info_array[$business_id] = array();

                    foreach ( $results as $node )
                    {

                        if ( 7 == $i )
                        {
                            $i = 0;
                            $ii++;
                        }

                        $column_name = $settings['column_names'][$i];
                        $company_info_array[$business_id][$ii][$column_name] = trim( $node->nodeValue );

                        $i++;
                    }

                    return $company_info_array[$business_id];
                }
                else
                {
                    self::get_companys_data_from_ytj($settings, $business_id);
                }
            }
        }

        return false;
    }

    /**
     * Retrieves chosen companys data from ytj.fi and creates an array of it
     * @param string $business_id Companys businessid (1234567-8)
     * @return array
     * @access private
     */
    private static function get_companys_data_from_ytj($settings, $business_id)
    {
        $company_info_array = array();

        $base_url = 'https://www.ytj.fi/yrityshaku.aspx';
        $search_url = 'https://www.ytj.fi/yrityshaku.aspx?path=1547';

        $response = self::curl_request($settings, $base_url, array(), $base_url, TRUE);

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

        $data = self::curl_request($settings, $search_url, $post_array, $base_url, TRUE);
        $companys_link_found = preg_match( '/<a id="ContentPlaceHolder_rptHakuTulos_HyperLink1_0" href="(.*)">/', $data['contents'], $companys_link );

        if ( $companys_link_found )
        {
            $companys_data = self::curl_request($settings, 'https://www.ytj.fi/'.str_replace( '&amp;', '&', $companys_link[1] ), array(), $search_url, TRUE);

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
                                        $company_info_array[$business_id][$i]['y_tunnus'] = $business_id;
                                        $company_info_array[$business_id][$i]['yrityksen_nimi'] = $companys_name[1];
                                        $company_info_array[$business_id][$i]['kotipaikka'] = '';
                                        $company_info_array[$business_id][$i]['diaarinumero'] = '';
                                        $company_info_array[$business_id][$i]['rekisteroity_asia'] = trim( $row );
                                        break;

                                    case 1:
                                        $company_info_array[$business_id][$i]['rekisterointilaji'] = trim( $row );
                                        break;

                                    case 2:
                                        $company_info_array[$business_id][$i]['rekisterointiajankohta'] = trim( $row );
                                        $ii = 0;
                                        break;
                                }

                                $ii++;
                            }
                        }

                        return $company_info_array[$business_id];
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
    private static function get_execution_id($url)
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
