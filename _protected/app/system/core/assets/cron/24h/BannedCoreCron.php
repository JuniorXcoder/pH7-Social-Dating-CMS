<?php
/**
 * @title            BannedIP Cron Class
 *
 * @author           Pierre-Henry Soria <ph7software@gmail.com>, Polyna-Maude R.-Summerside <polynamaude@gmail.com>
 * @copyright        (c) 2013-2018, Pierre-Henry Soria. All Rights Reserved.
 * @license          GNU General Public License; See PH7.LICENSE.txt and PH7.COPYRIGHT.txt in the root directory.
 * @package          PH7 / App / System / Core / Asset / Cron / 24H
 * @version          1.0
 */

namespace PH7;

defined('PH7') or exit('Restricted access');

use Exception;
use PH7\Framework\Security\Ban\Ban;
use PH7\Framework\Error\Logger;

class BannedCoreCron extends Cron
{
    const BANNED_IP_FILE_PATH = PH7_PATH_APP_CONFIG . Ban::DIR . Ban::IP_FILE;

    const ERROR_CALLING_WEB_SERVICE_MESSAGE = 'Error calling web service for banned IP URL name: %s';
    const ERROR_ADD_BANNED_IP_MESSAGE = 'Error writing new banned IP file';

    /**
     * Web client used to fetch IPs
     *
     * @var \GuzzleHttp\Client
     */
    private $oWebClient;

    /**
     * Contain new blocked IP just fetched
     *
     * @var array
     */
    private $aNewIps;

    /**
     * Contain existing blocked IP
     *
     * @var array
     */
    private $aOldIps;

    /**
     * IP extracting regular expression.
     *
     * @var string
     */
    private $sIpRegExp;

    /**
     * Contain the URL of the service we call to get banned IP
     * Currently filled at instantiation statically, will use config file later
     *
     * @var array
     */
    const SVC_URLS = [
        'https://www.blocklist.de/downloads/export-ips_all.txt',
        'http://www.badips.com/get/list/ssh/2'
    ];

    public function __construct()
    {
        parent::__construct();

        /**
         * Set valid IP regular expression using lazy mode (false)
         *
         * @var \PH7\BannedCoreCron $sIpRegExp
         */
        $this->sIpRegExp = self::regexpIP();

        $this->doProcess();
    }

    /**
     * Get the job done !
     */
    protected function doProcess()
    {
        /**
         * Process each web url we have in the $svcUrl array
         */
        foreach (self::SVC_URLS as $sUrl) {
            /**
             * Each url we have for Web Service.
             */
            try {

                /**
                 * If we don't get true then we have an error
                 */
                if (!$this->callWebService($sUrl)) {
                    (new Logger())->msg(
                        sprintf(self::ERROR_CALLING_WEB_SERVICE_MESSAGE, $sUrl)
                    );
                }

                /**
                 * We catch exception so we can continue if one service fail
                 */
            } catch (Exception $oExcept) {
                (new Logger())->msg(
                    sprintf(self::ERROR_CALLING_WEB_SERVICE_MESSAGE, $sUrl)
                );
            }
        }

        /**
         * Process the currently banned IP
         */
        $this->processExistingIP();

        /**
         * Merge both IPs and filter out doubles
         */
        $this->processIP();

        /**
         * Write the new banned IP file
         */
        if (!$this->writeIP()) {
            (new Logger())->msg(self::ERROR_ADD_BANNED_IP_MESSAGE);
        }
    }

    /**
     * Call the web service with the given url and add received IP into $aNewIps
     *
     * @param string $sUrl
     *
     * @return bool
     */
    private function callWebService($sUrl)
    {
        if (is_null($this->oWebClient)) {
            $this->oWebClient = new \GuzzleHttp\Client();
        }

        /**
         * If we don't have a valid array to put address into, we create it.
         */
        if (!is_array($this->aNewIps)) {
            $this->aNewIps = [];
        }
        /**
         * Call the oWebClient with the url
         */
        $oInBound = $this->oWebClient->get($sUrl);

        /**
         * Check we get a valid response
         */
        if ($oInBound->getStatusCode() !== 200) {
            return false;
        }

        /**
         * Get the body and detach into a stream
         */
        $rBannedIps = $oInBound->getBody()->detach();

        /**
         * Process the received IP
         */
        while ($sBannedIp = fgets($rBannedIps)) {
            /**
             * Trim the ip from return carriage and new line then add to the current array
             */
            $this->aNewIps[] = rtrim($sBannedIp, "\n\r");
        }

        return true;
    }

    /**
     * Process existing banned IP file and only keep validating IP addresses.
     */
    private function processExistingIP()
    {
        /**
         * We fill a temporary array with current address
         */
        $aBans = file(self::BANNED_IP_FILE_PATH);
        $this->aOldIps = [];

        foreach ($aBans as $ban) {
            /**
             * Array containing return IP address
             *
             * @var array $ips
             */
            $ips = preg_grep($this->sIpRegExp, $ban);
            /**
             * check if $ip empty in case we processed a text line
             */
            if (!empty($ips)) {
                /**
                 * Use a foreach loop in case we have more than one IP per line
                 */
                foreach ($ips as $ip) {
                    $this->aOldIps[] = $ip;
                }
            }
        }
    }

    /**
     * Read both IPs array, merge and extract only unique one
     */
    private function processIP()
    {
        $aNewIps = array_unique(array_merge($this->aNewIps, $this->aOldIps), SORT_STRING);
        $this->aNewIps = $aNewIps;
    }

    /**
     * Return a valid IPv4 regular expression
     * Using strict reject octal form (leading zero)
     *
     * @param bool $bStrict
     * @return string
     */
    public static function regexpIP($bStrict = false)
    {
        if ($bStrict) {
            /**
             * Regular Expression representing a valid IPv4 class address
             * Rejecting octal form (leading zero)
             *
             * @var string $sIpRegExp
             */
            $sIpRegExp = '/(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.';
            $sIpRegExp .= '(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.';
            $sIpRegExp .= '(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.';
            $sIpRegExp .= '(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])/';
        } else {
            /**
             *
             * Regular Expression representing a valid IPv4 class address
             * We accept leading 0 but they normally imply octal so we shouldn't !!!
             *
             * @var string $sIpRegExp
             */
            $sIpRegExp = '/(25[0-5]|2[0-9][0-9]|[01]?[0-9][0-9]?)\.';
            $sIpRegExp .= '(25[0-5]|2[0-9][0-9]|[01]?[0-9][0-9]?)\.';
            $sIpRegExp .= '(25[0-5]|2[0-9][0-9]|[01]?[0-9][0-9]?)\.';
            $sIpRegExp .= '(25[0-5]|2[0-9][0-9]|[01]?[0-9][0-9]?)/';
        }

        return $sIpRegExp;
    }

    /**
     * Write IPs to banned ip file
     *
     * @return boolean
     */
    private function writeIP()
    {
        if ($this->invalidNewIp()) {
            return false;
        }

        foreach ($this->aNewIps as $sIp) {
            $this->addIp($sIp);
        }

        return true;
    }

    /**
     * @param string $sIpAddress
     *
     * @return void
     */
    private function addIp($sIpAddress)
    {
        file_put_contents(self::BANNED_IP_FILE_PATH, $sIpAddress . "\n", FILE_APPEND);
    }

    /**
     * @return bool
     */
    private function invalidNewIp()
    {
        return empty($this->aNewIps) || !is_array($this->aNewIps);
    }
}
