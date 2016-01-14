<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Retrieves companies data from virre.prh.fi and ytj.fi if necessary
 */
class VirreParser
{
    /**
     * Constructor
     * @param string|null $settingsFile
     * @throws Exception
     */
    public function __construct($settingsFile = null)
    {
        if ($settingsFile === null) {
            $this->settingsFile = __DIR__ . '/settings.yaml';
        } else {
            $this->settingsFile = $settingsFile;
        }

        if (!file_exists($this->settingsFile)) {
            throw new Exception($this->settingsFile . ' is missing');
        }

        $this->settings = yaml_parse_file($this->settingsFile, 0);

        $this->jsonData = __DIR__ . '/data.json';
        $this->businessInfo = array();
        $this->column_names = array(
            0 => 'y_tunnus',
            1 => 'yrityksen_nimi',
            2 => 'kotipaikka',
            3 => 'diaarinumero',
            4 => 'rekisterointilaji',
            5 => 'rekisterointiajankohta',
            6 => 'rekisteroity_asia',
        );

        $this->cookieJar = tempnam(sys_get_temp_dir(), 'virre');
        $this->base_url = 'https://virre.prh.fi/novus/publishedEntriesSearch';
        $this->jSecurityCheck = 'https://virre.prh.fi/novus/j_security_check'; /* j_security_check url */

        $this->curl_request($this->base_url);
    }

    /**
     * @param string $url URL
     * @param array $postData POST request data ( array('a' => 1, 'b' => 2, ..) )
     * @param string $referrer HTTP Referer:
     * @param bool $gzippedPage
     * @return mixed - urls contents
     * @throws Exception
     * @access private
     */
    private function curl_request($url, $postData = array(), $referrer = null, $gzippedPage = FALSE)
    {
        sleep(rand(5, 20)); /* sleep 5-20sec so we dont look like a bot so much */

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_VERBOSE, FALSE);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->settings['useragent']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        if (null !== $referrer) {
            curl_setopt($ch, CURLOPT_REFERER, $referrer);
        }

        if (preg_match('/virre\.prh\.fi/', $url)) {
            $http_header_host = 'virre.prh.fi';
        } else if (preg_match('/ytj\.fi/', $url)) {
            $http_header_host = 'www.ytj.fi';
        }

        $http_header = array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/' . '*;q=0.8',
            'Accept-Encoding: gzip, deflate',
            'Accept-Language: en-US,en;q=0.8',
            'Connection: keep-alive',
            'Cache-Control: max-age=0',
            'Host: ' . $http_header_host,
            'HTTPS: 1',
            'Cache-Control: no-cache, no-store',
            'Pragma: no-cache',
        );

        if (0 != count($postData)) {

            $post_fields = '';
            foreach ($postData as $key => $value) {
                $post_fields .= urlencode($key) . '=' . urlencode($value) . '&';
            }

            $http_header[] = 'Content-Type: application/x-www-form-urlencoded';

            curl_setopt($ch, CURLOPT_POST, 1); /* Switch from GET to POST */
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        } else {
            $http_header[1] .= ', sdch'; /* Accept-Encoding: */
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $http_header);

        $result = curl_exec($ch);

        if ($gzippedPage) {
            if (function_exists('gzdecode')) {
                $result = gzdecode($result);
            } else {
                $result = gzinflate(substr($result, 10, -8));
            }
        }

        $curlResult = curl_getinfo($ch);

        curl_close($ch);

        if (preg_match('/<title>Security Check<\/title>/', $result)) {

            preg_match_all('/<input type="hidden" value="(.*)" name="(.*)"\/>/', $result, $res);

            $loginCredentials = array(
                $res[2][0] => $res[1][0], /* j_username */
                $res[2][1] => $res[1][1], /* j_password */
            );

            $this->curl_request($this->jSecurityCheck, $loginCredentials, 'https://virre.prh.fi/novus/home');

        } elseif (preg_match('/WebServer - Error report/', $result)) {
            throw new Exception('WebServer Error');
        }

        return array(
            'curl_info' => $curlResult,
            'contents' => $result
        );
    }

    /**
     * Retrieves chosen companys data from virre.prh.fi and creates an array of it
     * @param string $businessId Companys businessid (1234567-8)
     * @return array
     * @throws Exception
     * @access public
     */
    public function getCompanysData($businessId = '')
    {
        if (!preg_match('/^[0-9]{7}[-][0-9]{1}$/', $businessId)) {
            throw new Exception('Invalid businessid!');
        } else {

            if (!in_array($businessId, $this->settings['business_ids']['active'])) {
                if (($business_id_key = array_search($businessId, $this->settings['business_ids']['inactive'])) !== false) {
                    /* Business id is found in the 'inactive' list, moving to 'active' list */
                    unset($this->settings['business_ids']['inactive'][$business_id_key]);
                }

                $this->settings['business_ids']['active'][] = $businessId;
            }

            $base_url = 'https://virre.prh.fi/novus/publishedEntriesSearch';

            $response = $this->curl_request($base_url, array(), $base_url);

            if ($executionId = $this->get_execution_id($response['curl_info']['url'])) {

                $search_fields = array(
                    'businessId' => $businessId,
                    'startDate' => '',
                    'endDate' => '',
                    'registrationTypeCode' => '',
                    '_todayRegistered' => 'on',
                    '_domicileCode' => '1',
                    '_eventId_search' => 'Hae',
                    'execution' => $executionId,
                    '_defaultEventId' => '',
                );

                $data = $this->curl_request($base_url, $search_fields, $base_url);

                /* Fixes & characters that dont have ; with them */
                $amp_fix = preg_replace('/&(?![A-Za-z]+;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $data['contents']);

                $DOM = new DOMDocument;
                $DOM->loadHTML($amp_fix);

                $selector = new DOMXPath($DOM);
                $results = $selector->query('.//table/tbody/tr/td');

                $i = 0;
                $ii = 0;

                if (0 != $results->length) {
                    $this->businessInfo[$businessId] = array();

                    foreach ($results as $node) {

                        if (7 == $i) {
                            $i = 0;
                            $ii++;
                        }

                        $column_name = $this->column_names[$i];
                        $this->businessInfo[$businessId][$ii][$column_name] = trim($node->nodeValue);

                        $i++;
                    }
                } else {
                    $this->getDataFromYTJ($businessId);
                }
            }
        }
    }

    /**
     * Retrieves chosen companys data from ytj.fi and creates an array of it
     * @param string $businessId Companys businessid (1234567-8)
     * @return array
     * @access private
     */
    private function getDataFromYTJ($businessId)
    {
        $baseUrl = 'https://www.ytj.fi/yrityshaku.aspx';
        $searchUrl = 'https://www.ytj.fi/yrityshaku.aspx?path=1547';

        $response = $this->curl_request($baseUrl, array(), $baseUrl, TRUE);

        preg_match_all('/<input type="hidden" name="(.*)" id=".*" value="(.*)" \/>/', $response['contents'], $res);

        $postArray = array();
        $i = 0;

        foreach ($res[1] as $post_field) {
            $postArray[$post_field] = $res[2][$i];
            $i++;
        }

        $postArray['_ctl0:ContentPlaceHolder:hakusana'] = '';
        $postArray['_ctl0:ContentPlaceHolder:ytunnus'] = $businessId;
        $postArray['_ctl0:ContentPlaceHolder:yrmu'] = '';
        $postArray['_ctl0:ContentPlaceHolder:LEItunnus'] = '';
        $postArray['_ctl0:ContentPlaceHolder:sort'] = 'sort1';
        $postArray['_ctl0:ContentPlaceHolder:suodatus'] = 'suodatus1';
        $postArray['_ctl0:ContentPlaceHolder:Hae'] = 'Hae+yritykset';

        $data = $this->curl_request($searchUrl, $postArray, $baseUrl, TRUE);
        $companyFound = preg_match('/<a id="ContentPlaceHolder_rptHakuTulos_HyperLink1_0" href="(.*)">/', $data['contents'], $companys_link);

        if ($companyFound) {
            $companyData = $this->curl_request('https://www.ytj.fi/' . str_replace('&amp;', '&', $companys_link[1]), array(), $searchUrl, TRUE);

            /* Fixes & characters that dont have ; with them */
            $ampFix = preg_replace('/&(?![A-Za-z]+;|#[0-9]+;|#x[0-9a-fA-F]+;)/', '&amp;', $companyData['contents']);

            $DOM = new DOMDocument;
            $DOM->loadHTML($ampFix);

            $xpath = new DOMXPath($DOM);
            $elements = $xpath->query("/" . "/" . "*[@id='detail-result']/table")->item(1);

            preg_match('/<span id="ContentPlaceHolder_lblToiminimi">(.*)<\/span>/', $companyData['contents'], $companyName);

            $i = 0;

            foreach ($elements->childNodes as $node) {
                if (0 != $i) // Skip the header info
                {
                    $ii = 0;

                    foreach ($node->childNodes as $trNode) {
                        $explode = explode(PHP_EOL, trim($trNode->nodeValue));

                        foreach ($explode as $row) {
                            $row = preg_replace('~\xc2\xa0~', '', trim($row)); // Remove some weird characters

                            if (!empty($row)) {
                                switch ($ii) {
                                    case 0:
                                        $this->businessInfo[$businessId][$i]['y_tunnus'] = $businessId;
                                        $this->businessInfo[$businessId][$i]['yrityksen_nimi'] = $companyName[1];
                                        $this->businessInfo[$businessId][$i]['kotipaikka'] = '';
                                        $this->businessInfo[$businessId][$i]['diaarinumero'] = '';
                                        $this->businessInfo[$businessId][$i]['rekisteroity_asia'] = trim($row);
                                        break;

                                    case 1:
                                        $this->businessInfo[$businessId][$i]['rekisterointilaji'] = trim($row);
                                        break;

                                    case 2:
                                        $this->businessInfo[$businessId][$i]['rekisterointiajankohta'] = trim($row);
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
        preg_match('/execution[=]([a-z0-9]+)/', $url, $res);

        if (isset($res[1])) {
            return $res[1];
        }

        return false;
    }

    /**
     * Goes through active businessids
     * @access public
     */
    public function searchCompanysData()
    {
        foreach ($this->settings['business_ids']['active'] as $business_id) {
            $this->getCompanysData($business_id);
        }
    }

    /**
     * Saves $this->settings to $this->yaml_file
     * @access public
     */
    private function saveSettings()
    {
        yaml_emit_file($this->settingsFile, $this->settings);
    }

    /**
     * Adds companies names to $this->yaml_file for easier editing
     * @access private
     */
    private function companiesNamesToYAML()
    {
        $settingsFileTemp = file_get_contents($this->settingsFile);

        foreach ($this->businessInfo as $business_id => $business_data) {
            $last_business_data = end($business_data);

            $companys_name = $last_business_data['yrityksen_nimi'];

            $settingsFileTemp = preg_replace('/' . $business_id . '/', $business_id . ' # ' . $companys_name, $settingsFileTemp);
        }

        file_put_contents($this->settingsFile, $settingsFileTemp);
    }

    /**
     * Saves new data to $this->json_data_file and send email if necessary
     * @access public
     */
    public function saveData()
    {
        $this->saveSettings();

        $mailBody = '';

        if (file_exists($this->jsonData) || (!file_exists($this->jsonData) && touch($this->jsonData))) {
            $existingData = file_get_contents($this->jsonData);

            try {
                $existing_data_array = json_decode($existingData, TRUE);
            } catch (Exception $e) {
                $existing_data_array = array();
            }

            foreach ($this->businessInfo as $businessId => $business_data) {
                if (!array_key_exists($businessId, $existing_data_array)) {
                    $existing_data_array[$businessId] = md5(json_encode(end($business_data)));
                } else {
                    if (md5(json_encode(end($business_data))) != $existing_data_array[$businessId]) {
                        $bd_end = end($business_data);

                        $mailBody .= $bd_end['yrityksen_nimi'] . ' (' . $bd_end['y_tunnus'] . ') ' . $bd_end['rekisterointilaji'] . ' ' . $bd_end['rekisterointiajankohta'] . ' ' . $bd_end['rekisteroity_asia'] . PHP_EOL . PHP_EOL;

                        $existing_data_array[$businessId] = md5(json_encode(end($business_data)));
                    }
                }
            }

            $this->companiesNamesToYAML();

            file_put_contents($this->jsonData, json_encode($existing_data_array));

            if (!empty($mailBody) && !empty($this->settings['send_email_to'])) {
                $mail = new PHPMailer;
                $mail->isSendmail();

                foreach ($this->settings['send_email_to'] as $mail_to) {
                    $mail->addAddress($mail_to);
                }

                $mail->SetFrom($this->settings['mail_from_addr'], $this->settings['mail_from_name']);
                $mail->Subject = utf8_decode('Yhden tai useamman yrityksen tietoja päivitetty virreen');
                $mail->Body = utf8_decode($mailBody);

                if (!$mail->send()) {
                    echo 'Mailer Error: ' . $mail->ErrorInfo;
                } else {
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
        if (file_exists($this->cookieJar)) {
            unset($this->cookieJar);
        }
    }
}
