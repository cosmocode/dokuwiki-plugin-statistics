<?php
require dirname(__FILE__) . '/Browscap.php';

/**
 * Overwrites some methods from the original upstream Browscap class
 */
class StatisticsBrowscap extends Browscap {

    /**
     * Defines our own cache locations and names
     */
    public function __construct() {
        global $conf;
        $this->cacheDir      = $conf['cachedir'] . '/';
        $this->cacheFilename = 'browscap.ini.php';
        $this->remoteIniUrl  = 'http://browscap.org/stream?q=Lite_PHP_BrowsCapINI';
    }

    /**
     * Use DokuWiki's HTTP Clients for downloading
     *
     * @param string $url
     * @throws Exception
     * @return string
     */
    protected function _getRemoteData($url) {
        $http = new \dokuwiki\HTTP\DokuHTTPClient($url);
        $file = $http->get($url);
        if(!$file)
            throw new Exception('Your server can\'t connect to external resources. Please update the file manually.');
        return $file;
    }
}
