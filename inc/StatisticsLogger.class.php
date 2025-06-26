<?php

require dirname(__FILE__) . '/StatisticsBrowscap.class.php';

class StatisticsLogger {
    private $hlp;

    private $ua_agent;
    private $ua_type;
    private $ua_name;
    private $ua_version;
    private $ua_platform;

    private $uid;

    /**
     * Parses browser info and set internal vars
     */
    public function __construct(helper_plugin_statistics $hlp) {
        $this->hlp = $hlp;

        $this->ua_agent = trim(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
        $bc             = new StatisticsBrowscap();
        $ua             = $bc->getBrowser($this->ua_agent);
        $this->ua_name  = $ua->Browser;
        $this->ua_type  = 'browser';
        if(isset($ua->Crawler) && $ua->Crawler) $this->ua_type = 'robot';
        if(isset($ua->isSyndicationReader) && $ua->isSyndicationReader) $this->ua_type = 'feedreader';
        $this->ua_version  = $ua->Version;
        $this->ua_platform = $ua->Platform;

        $this->uid = $this->getUID();

        $this->log_lastseen();
    }

    /**
     * get the unique user ID
     */
    protected function getUID() {
        $uid = isset($_REQUEST['uid']) ? $_REQUEST['uid'] : null;
        if(!$uid) $uid = get_doku_pref('plgstats', false);
        if(!$uid) $uid = session_id();
        return $uid;
    }

    /**
     * Return the user's session ID
     *
     * This is usually our own managed session, not a PHP session (only in fallback)
     *
     * @return string
     */
    protected function getSession() {
        $ses = isset($_REQUEST['ses']) ? $_REQUEST['ses'] : null;
        if(!$ses) $ses = get_doku_pref('plgstatsses', false);
        if(!$ses) $ses = session_id();
        return $ses;
    }

    /**
     * Log that we've seen the user (authenticated only)
     *
     * This is called directly from the constructor and thus logs always,
     * regardless from where the log is initiated
     */
    public function log_lastseen() {
        if(!isset($_SERVER['REMOTE_USER']) || empty($_SERVER['REMOTE_USER'])) return;
        $user = addslashes($_SERVER['REMOTE_USER']);

        $sql = "REPLACE INTO " . $this->hlp->prefix . "lastseen
                    SET `user` = '$user'
               ";
        $this->hlp->runSQL($sql);
    }

    /**
     * Log actions by groups
     *
     * @param string $type   The type of access to log ('view','edit')
     * @param array  $groups The groups to log
     */
    public function log_groups($type, $groups) {
        if(!is_array($groups)) {
            return;
        }

        $tolog = $this->hlp->getConf('loggroups');
        if($tolog) {
            foreach($groups as $pos => $group) {
                if(!in_array($group, $tolog)) unset($groups[$pos]);
            }
        }
        if (!count($groups)) {
            return;
        }

        $type = addslashes($type);

        $sql = "INSERT DELAYED INTO " . $this->hlp->prefix . "groups
                     (`dt`, `type`, `group`) VALUES ";
        foreach($groups as $group) {
            $group = addslashes($group);
            $sql .= "( NOW(), '$type', '$group' ),";
        }
        $sql = rtrim($sql, ',');

        $ok = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            print_r($MSG);
        }
    }

    /**
     * Log external search queries
     *
     * Will not write anything if the referer isn't a search engine
     */
    public function log_externalsearch($referer, &$type) {
        $referer = \dokuwiki\Utf8\PhpString::strtolower($referer);
        include(dirname(__FILE__) . '/searchengines.php');
        /** @var array $SEARCHENGINES */

        $query = '';
        $name  = '';

        // parse the referer
        $urlparts = parse_url($referer);
        $domain   = $urlparts['host'];
        $qpart    = isset($urlparts['query']) ? $urlparts['query'] : '';
        if(!$qpart) $qpart = isset($urlparts['fragment']) ? $urlparts['fragment'] : ''; //google does this

        $params = array();
        parse_str($qpart, $params);

        // check domain against common search engines
        foreach($SEARCHENGINES as $regex => $info) {
            if(preg_match('/' . $regex . '/', $domain)) {
                $type = 'search';
                $name = array_shift($info);
                // check the known parameters for content
                foreach($info as $k) {
                    if(empty($params[$k])) continue;
                    $query = $params[$k];
                    break;
                }
                break;
            }
        }

        // try some generic search engin parameters
        if($type != 'search') foreach(array('search', 'query', 'q', 'keywords', 'keyword') as $k) {
            if(empty($params[$k])) continue;
            $query = $params[$k];
            // we seem to have found some generic search, generate name from domain
            $name = preg_replace('/(\.co)?\.([a-z]{2,5})$/', '', $domain); //strip tld
            $name = explode('.', $name);
            $name = array_pop($name);
            $type = 'search';
            break;
        }

        // still no hit? return
        if($type != 'search') return;

        // clean the query
        $query = preg_replace('/^(cache|related):[^\+]+/', '', $query); // non-search queries
        $query = preg_replace('/ +/', ' ', $query); // ws compact
        $query = trim($query);
        if(!\dokuwiki\Utf8\Clean::isUtf8($query)) {
            // Convert from latin1 to UTF-8 if not already UTF-8
            $query = mb_convert_encoding($query, 'UTF-8', 'ISO-8859-1');
        }

        // no query? no log
        if(!$query) return;

        // log it!
        // Replace deprecated utf8_stripspecials with modern equivalent
        $cleaned_query = preg_replace('/[^\w\s\._\-:\*]/u', '', $query);
        $words = explode(' ', $cleaned_query);
        $this->log_search(isset($_REQUEST['p']) ? $_REQUEST['p'] : '', $query, $words, $name);
    }

    /**
     * The given data to the search related tables
     */
    public function log_search($page, $query, $words, $engine) {
        $page   = addslashes($page);
        $query  = addslashes($query);
        $engine = addslashes($engine);

        $sql = "INSERT INTO " . $this->hlp->prefix . "search
                    SET dt       = NOW(),
                        page     = '$page',
                        query    = '$query',
                        engine   = '$engine'";
        $id  = $this->hlp->runSQL($sql);
        if(is_null($id)) return;

        foreach($words as $word) {
            if(!$word) continue;
            $word = addslashes($word);
            $sql  = "INSERT DELAYED INTO " . $this->hlp->prefix . "searchwords
                       SET sid  = $id,
                           word = '$word'";
            $this->hlp->runSQL($sql);
        }
    }

    /**
     * Log that the session was seen
     *
     * This is used to calculate the time people spend on the whole site
     * during their session
     *
     * Viewcounts are used for bounce calculation
     *
     * @param int $addview set to 1 to count a view
     */
    public function log_session($addview = 0) {
        // only log browser sessions
        if($this->ua_type != 'browser') return;

        $addview = addslashes($addview);
        $session = addslashes($this->getSession());
        $uid     = addslashes($this->uid);
        $sql     = "INSERT DELAYED INTO " . $this->hlp->prefix . "session
                   SET session = '$session',
                       dt      = NOW(),
                       end     = NOW(),
                       views   = $addview,
                       uid     = '$uid'
                ON DUPLICATE KEY UPDATE
                       end     = NOW(),
                       views   = views + $addview,
                       uid     = '$uid'";
        $this->hlp->runSQL($sql);
    }

    /**
     * Resolve IP to country/city
     */
    public function log_ip($ip) {
        // check if IP already known and up-to-date
        $sql    = "SELECT ip
                  FROM " . $this->hlp->prefix . "iplocation
                 WHERE ip ='" . addslashes($ip) . "'
                   AND lastupd > DATE_SUB(CURDATE(),INTERVAL 30 DAY)";
        $result = $this->hlp->runSQL($sql);
        if($result && isset($result[0]) && isset($result[0]['ip']) && $result[0]['ip']) return;

        $http          = new \dokuwiki\HTTP\DokuHTTPClient();
        $http->timeout = 10;
        $data          = $http->get('http://api.hostip.info/get_html.php?ip=' . $ip);

        if(preg_match('/^Country: (.*?) \((.*?)\)\nCity: (.*?)$/s', $data, $match)) {
            $country = addslashes(ucwords(strtolower(trim($match[1]))));
            $code    = addslashes(strtolower(trim($match[2])));
            $city    = addslashes(ucwords(strtolower(trim($match[3]))));
            $host    = addslashes(gethostbyaddr($ip));
            $ip      = addslashes($ip);

            $sql = "REPLACE INTO " . $this->hlp->prefix . "iplocation
                        SET ip = '$ip',
                            country = '$country',
                            code    = '$code',
                            city    = '$city',
                            host    = '$host'";
            $this->hlp->runSQL($sql);
        }
    }

    /**
     * log a click on an external link
     *
     * called from log.php
     */
    public function log_outgoing() {
        if(!isset($_REQUEST['ol']) || !$_REQUEST['ol']) return;

        $link     = addslashes($_REQUEST['ol']);
        $link_md5 = md5($link);
        $session  = addslashes($this->getSession());
        $page     = addslashes(isset($_REQUEST['p']) ? $_REQUEST['p'] : '');

        $sql = "INSERT DELAYED INTO " . $this->hlp->prefix . "outlinks
                    SET dt       = NOW(),
                        session  = '$session',
                        page     = '$page',
                        link_md5 = '$link_md5',
                        link     = '$link'";
        $ok  = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            print_r($MSG);
        }
    }

    /**
     * log a page access
     *
     * called from log.php
     */
    public function log_access() {
        if(!isset($_REQUEST['p']) || !$_REQUEST['p']) return;
        global $USERINFO;

        # FIXME check referer against blacklist and drop logging for bad boys

        // handle referer
        $referer = trim(isset($_REQUEST['r']) ? $_REQUEST['r'] : '');
        if($referer) {
            $ref     = addslashes($referer);
            $ref_md5 = ($ref) ? md5($referer) : '';
            if(strpos($referer, DOKU_URL) === 0) {
                $ref_type = 'internal';
            } else {
                $ref_type = 'external';
                $this->log_externalsearch($referer, $ref_type);
            }
        } else {
            $ref      = '';
            $ref_md5  = '';
            $ref_type = '';
        }

        // handle user agent
        $ua      = addslashes($this->ua_agent);
        $ua_type = addslashes($this->ua_type);
        $ua_ver  = addslashes($this->ua_version);
        $os      = addslashes($this->ua_platform);
        $ua_info = addslashes($this->ua_name);

        $page    = addslashes($_REQUEST['p']);
        $ip      = addslashes(clientIP(true));
        $sx      = (int) (isset($_REQUEST['sx']) ? $_REQUEST['sx'] : 0);
        $sy      = (int) (isset($_REQUEST['sy']) ? $_REQUEST['sy'] : 0);
        $vx      = (int) (isset($_REQUEST['vx']) ? $_REQUEST['vx'] : 0);
        $vy      = (int) (isset($_REQUEST['vy']) ? $_REQUEST['vy'] : 0);
        $js      = (int) (isset($_REQUEST['js']) ? $_REQUEST['js'] : 0);
        $uid     = addslashes($this->uid);
        $user    = addslashes(isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '');
        $session = addslashes($this->getSession());

        $sql = "INSERT DELAYED INTO " . $this->hlp->prefix . "access
                    SET dt       = NOW(),
                        page     = '$page',
                        ip       = '$ip',
                        ua       = '$ua',
                        ua_info  = '$ua_info',
                        ua_type  = '$ua_type',
                        ua_ver   = '$ua_ver',
                        os       = '$os',
                        ref      = '$ref',
                        ref_md5  = '$ref_md5',
                        ref_type = '$ref_type',
                        screen_x = '$sx',
                        screen_y = '$sy',
                        view_x   = '$vx',
                        view_y   = '$vy',
                        js       = '$js',
                        user     = '$user',
                        session  = '$session',
                        uid      = '$uid'";
        $ok  = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            print_r($MSG);
        }

        $sql = "INSERT DELAYED IGNORE INTO " . $this->hlp->prefix . "refseen
                   SET ref_md5  = '$ref_md5',
                       dt       = NOW()";
        $ok  = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            print_r($MSG);
        }

        // log group access
        if(isset($USERINFO['grps'])) {
            $this->log_groups('view', $USERINFO['grps']);
        }

        // resolve the IP
        $this->log_ip(clientIP(true));
    }

    /**
     * Log access to a media file
     *
     * called from action.php
     *
     * @param string $media the media ID
     * @param string $mime  the media's mime type
     * @param bool $inline is this displayed inline?
     * @param int $size size of the media file
     */
    public function log_media($media, $mime, $inline, $size) {
        // handle user agent
        $ua      = addslashes($this->ua_agent);
        $ua_type = addslashes($this->ua_type);
        $ua_ver  = addslashes($this->ua_version);
        $os      = addslashes($this->ua_platform);
        $ua_info = addslashes($this->ua_name);

        $media    = addslashes($media);
        list($mime1, $mime2)     = explode('/', strtolower($mime));
        $mime1   = addslashes($mime1);
        $mime2   = addslashes($mime2);
        $inline  = $inline ? 1 : 0;
        $size    = (int) $size;

        $ip      = addslashes(clientIP(true));
        $uid     = addslashes($this->uid);
        $user    = addslashes(isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '');
        $session = addslashes($this->getSession());

        $sql = "INSERT DELAYED INTO " . $this->hlp->prefix . "media
                    SET dt       = NOW(),
                        media    = '$media',
                        ip       = '$ip',
                        ua       = '$ua',
                        ua_info  = '$ua_info',
                        ua_type  = '$ua_type',
                        ua_ver   = '$ua_ver',
                        os       = '$os',
                        user     = '$user',
                        session  = '$session',
                        uid      = '$uid',
                        size     = $size,
                        mime1    = '$mime1',
                        mime2    = '$mime2',
                        inline   = $inline
                        ";
        $ok  = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            // Using modern logger instead of deprecated dbglog
            if (class_exists('\dokuwiki\Logger')) {
                \dokuwiki\Logger::debug($MSG);
            }
        }
    }

    /**
     * Log edits
     */
    public function log_edit($page, $type) {
        global $USERINFO;

        $ip      = addslashes(clientIP(true));
        $user    = addslashes(isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '');
        $session = addslashes($this->getSession());
        $uid     = addslashes($this->uid);
        $page    = addslashes($page);
        $type    = addslashes($type);

        $sql = "INSERT DELAYED INTO " . $this->hlp->prefix . "edits
                    SET dt       = NOW(),
                        page     = '$page',
                        type     = '$type',
                        ip       = '$ip',
                        user     = '$user',
                        session  = '$session',
                        uid      = '$uid'";
        $this->hlp->runSQL($sql);

        // log group access
        if(isset($USERINFO['grps'])) {
            $this->log_groups('edit', $USERINFO['grps']);
        }
    }

    /**
     * Log login/logoffs and user creations
     */
    public function log_login($type, $user = '') {
        if(!$user) $user = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '';

        $ip      = addslashes(clientIP(true));
        $user    = addslashes($user);
        $session = addslashes($this->getSession());
        $uid     = addslashes($this->uid);
        $type    = addslashes($type);

        $sql = "INSERT DELAYED INTO " . $this->hlp->prefix . "logins
                    SET dt       = NOW(),
                        type     = '$type',
                        ip       = '$ip',
                        user     = '$user',
                        session  = '$session',
                        uid      = '$uid'";
        $this->hlp->runSQL($sql);
    }

    /**
     * Log the current page count and size as today's history entry
     */
    public function log_history_pages() {
        global $conf;

        // use the popularity plugin's search method to find the wanted data
        /** @var helper_plugin_popularity $pop */
        $pop = plugin_load('helper', 'popularity');
        $list = array();
        search($list, $conf['datadir'], array($pop,'searchCountCallback'), array('all' => false), '');
        $page_count = $list['file_count'];
        $page_size  = $list['file_size'];

        print_r($list);

        $sql = "REPLACE INTO " . $this->hlp->prefix . "history
                        (`info`, `value`, `dt`)
                        VALUES
                        ( 'page_count', $page_count, DATE(NOW()) ),
                        ( 'page_size',  $page_size, DATE(NOW()) )
                        ";
        $ok = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            print_r($MSG);
        }
    }

    /**
     * Log the current page count and size as today's history entry
     */
    public function log_history_media() {
        global $conf;

        // use the popularity plugin's search method to find the wanted data
        /** @var helper_plugin_popularity $pop */
        $pop = plugin_load('helper', 'popularity');
        $list = array();
        search($list, $conf['mediadir'], array($pop, 'searchCountCallback'), array('all' => true), '');
        $media_count = $list['file_count'];
        $media_size  = $list['file_size'];

        $sql = "REPLACE INTO " . $this->hlp->prefix . "history
                        (`info`, `value`, `dt`)
                        VALUES
                        ( 'media_count', $media_count, DATE(NOW()) ),
                        ( 'media_size',  $media_size, DATE(NOW()) )
                        ";
        $ok = $this->hlp->runSQL($sql);
        if(is_null($ok)) {
            global $MSG;
            print_r($MSG);
        }
    }

}
