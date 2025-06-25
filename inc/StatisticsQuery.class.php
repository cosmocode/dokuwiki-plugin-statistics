<?php

class StatisticsQuery {
    private $hlp;

    public function __construct(helper_plugin_statistics $hlp) {
        $this->hlp = $hlp;
    }

    /**
     * Return some aggregated statistics
     */
    public function aggregate($tlimit) {
        $data = array();

        $sql    = "SELECT ref_type, COUNT(*) as cnt
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
              GROUP BY ref_type";
        $result = $this->hlp->runSQL($sql);

        if(is_array($result)) foreach($result as $row) {
            if($row['ref_type'] == 'search') $data['search'] = $row['cnt'];
            if($row['ref_type'] == 'external') $data['external'] = $row['cnt'];
            if($row['ref_type'] == 'internal') $data['internal'] = $row['cnt'];
            if($row['ref_type'] == '') $data['direct'] = $row['cnt'];
        }

        // general user and session info
        $sql    = "SELECT COUNT(DISTINCT session) as sessions,
                       COUNT(session) as views,
                       COUNT(DISTINCT user) as users,
                       COUNT(DISTINCT uid) as visitors
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'";
        $result = $this->hlp->runSQL($sql);

        $data['users']     = ($result && isset($result[0])) ? max($result[0]['users'] - 1, 0) : 0; // subtract empty user
        $data['sessions']  = ($result && isset($result[0])) ? $result[0]['sessions'] : 0;
        $data['pageviews'] = ($result && isset($result[0])) ? $result[0]['views'] : 0;
        $data['visitors']  = ($result && isset($result[0])) ? $result[0]['visitors'] : 0;

        // calculate bounce rate
        if($data['sessions']) {
            $sql                = "SELECT COUNT(*) as cnt
                      FROM " . $this->hlp->prefix . "session as A
                     WHERE $tlimit
                       AND views = 1";
            $result             = $this->hlp->runSQL($sql);
            $data['bouncerate'] = ($result && isset($result[0])) ? $result[0]['cnt'] * 100 / $data['sessions'] : 0;

            // new visitors
            $result              = "SELECT COUNT(*) as cnt
                         FROM " . $this->hlp->prefix . "session as A
                        WHERE $tlimit
                          AND NOT EXISTS (
                                SELECT *
                                  FROM stats_session B
                                 WHERE A.session <> B.session
                                   AND B.uid = B.uid
                              )";
            $result              = $this->hlp->runSQL($sql);
            $data['newvisitors'] = ($result && isset($result[0])) ? $result[0]['cnt'] * 100 / $data['sessions'] : 0;
        }

        // calculate avg. number of views per session
        $sql              = "SELECT AVG(views) as cnt
                  FROM " . $this->hlp->prefix . "session as A
                     WHERE $tlimit";
        $result           = $this->hlp->runSQL($sql);
        $data['avgpages'] = ($result && isset($result[0])) ? $result[0]['cnt'] : 0;

        /* not used currently
                $sql = "SELECT COUNT(id) as robots
                          FROM ".$this->hlp->prefix."access as A
                         WHERE $tlimit
                           AND ua_type = 'robot'";
                $result = $this->hlp->runSQL($sql);
                $data['robots'] = ($result && isset($result[0])) ? $result[0]['robots'] : 0;
        */

        // average time spent on the site
        $sql               = "SELECT AVG(end - dt)/60 as time
                  FROM " . $this->hlp->prefix . "session as A
                 WHERE $tlimit
                   AND dt != end
                   AND DATE(dt) = DATE(end)";
        $result            = $this->hlp->runSQL($sql);
        $data['timespent'] = ($result && isset($result[0])) ? $result[0]['time'] : 0;

        // logins
        $sql            = "SELECT COUNT(*) as logins
                  FROM " . $this->hlp->prefix . "logins as A
                 WHERE $tlimit
                   AND (type = 'l' OR type = 'p')";
        $result         = $this->hlp->runSQL($sql);
        $data['logins'] = ($result && isset($result[0])) ? $result[0]['logins'] : 0;

        // registrations
        $sql                   = "SELECT COUNT(*) as registrations
                  FROM " . $this->hlp->prefix . "logins as A
                 WHERE $tlimit
                   AND type = 'C'";
        $result                = $this->hlp->runSQL($sql);
        $data['registrations'] = ($result && isset($result[0])) ? $result[0]['registrations'] : 0;

        // current users
        $sql = "SELECT COUNT(*) as current
                  FROM ". $this->hlp->prefix . "lastseen
                 WHERE `dt` >= NOW() - INTERVAL 10 MINUTE";
        $result                = $this->hlp->runSQL($sql);
        $data['current'] = ($result && isset($result[0])) ? $result[0]['current'] : 0;

        return $data;
    }

    /**
     * standard statistics follow, only accesses made by browsers are counted
     * for general stats like browser or OS only visitors not pageviews are counted
     */

    /**
     * Return some trend data about visits and edits in the wiki
     */
    public function dashboardviews($tlimit, $hours = false) {
        if($hours) {
            $TIME = 'HOUR(dt)';
        } else {
            $TIME = 'DATE(dt)';
        }

        $data = array();

        // access trends
        $sql    = "SELECT $TIME as time,
                       COUNT(DISTINCT session) as sessions,
                       COUNT(session) as pageviews,
                       COUNT(DISTINCT uid) as visitors
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
              GROUP BY $TIME
              ORDER BY time";
        $result = $this->hlp->runSQL($sql);
        foreach($result as $row) {
            $data[$row['time']]['sessions']  = $row['sessions'];
            $data[$row['time']]['pageviews'] = $row['pageviews'];
            $data[$row['time']]['visitors']  = $row['visitors'];
        }
        return $data;
    }

    public function dashboardwiki($tlimit, $hours = false) {
        if($hours) {
            $TIME = 'HOUR(dt)';
        } else {
            $TIME = 'DATE(dt)';
        }

        $data = array();

        // edit trends
        foreach(array('E', 'C', 'D') as $type) {
            $sql    = "SELECT $TIME as time,
                           COUNT(*) as cnt
                      FROM " . $this->hlp->prefix . "edits as A
                     WHERE $tlimit
                       AND type = '$type'
                  GROUP BY $TIME
                  ORDER BY time";
            $result = $this->hlp->runSQL($sql);
            foreach($result as $row) {
                $data[$row['time']][$type] = $row['cnt'];
            }
        }
        ksort($data);
        return $data;
    }

    public function history($tlimit, $info, $interval = false) {
        if($interval == 'weeks') {
            $TIME = 'EXTRACT(YEAR FROM dt), EXTRACT(WEEK FROM dt)';
        } elseif ($interval == 'months') {
            $TIME = 'EXTRACT(YEAR_MONTH FROM dt)';
        } else {
            $TIME = 'dt';
        }

        $mod = 1;
        if($info == 'media_size' || $info == 'page_size') {
            $mod = 1024*1024;
        }

        $sql = "SELECT $TIME as time,
                       AVG(`value`)/$mod as cnt
                  FROM " . $this->hlp->prefix . "history as A
                 WHERE $tlimit
                   AND info = '$info'
                  GROUP BY $TIME
                  ORDER BY $TIME";
        return $this->hlp->runSQL($sql);
    }

    public function searchengines($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, engine as eflag, engine
                  FROM " . $this->hlp->prefix . "search as A
                 WHERE $tlimit
              GROUP BY engine
              ORDER BY cnt DESC, engine" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function searchphrases($extern, $tlimit, $start = 0, $limit = 20) {
        if($extern) {
            $WHERE = "engine != 'dokuwiki'";
            $I     = '';
        } else {
            $WHERE = "engine = 'dokuwiki'";
            $I     = 'i';
        }
        $sql = "SELECT COUNT(*) as cnt, query, query as ${I}lookup
                  FROM " . $this->hlp->prefix . "search as A
                 WHERE $tlimit
                   AND $WHERE
              GROUP BY query
              ORDER BY cnt DESC, query" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function searchwords($extern, $tlimit, $start = 0, $limit = 20) {
        if($extern) {
            $WHERE = "engine != 'dokuwiki'";
            $I     = '';
        } else {
            $WHERE = "engine = 'dokuwiki'";
            $I     = 'i';
        }
        $sql = "SELECT COUNT(*) as cnt, word, word as ${I}lookup
                  FROM " . $this->hlp->prefix . "search as A,
                       " . $this->hlp->prefix . "searchwords as B
                 WHERE $tlimit
                   AND A.id = B.sid
                   AND $WHERE
              GROUP BY word
              ORDER BY cnt DESC, word" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function outlinks($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, link as url
                  FROM " . $this->hlp->prefix . "outlinks as A
                 WHERE $tlimit
              GROUP BY link
              ORDER BY cnt DESC, link" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function pages($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, page
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
              GROUP BY page
              ORDER BY cnt DESC, page" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function edits($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, page
                  FROM " . $this->hlp->prefix . "edits as A
                 WHERE $tlimit
              GROUP BY page
              ORDER BY cnt DESC, page" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function images($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, media, SUM(size) as filesize
                  FROM " . $this->hlp->prefix . "media as A
                 WHERE $tlimit
                   AND mime1 = 'image'
              GROUP BY media
              ORDER BY cnt DESC, media" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function imagessum($tlimit) {
        $sql = "SELECT COUNT(*) as cnt, SUM(size) as filesize
                  FROM " . $this->hlp->prefix . "media as A
                 WHERE $tlimit
                   AND mime1 = 'image'";
        return $this->hlp->runSQL($sql);
    }

    public function downloads($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, media, SUM(size) as filesize
                  FROM " . $this->hlp->prefix . "media as A
                 WHERE $tlimit
                   AND mime1 != 'image'
              GROUP BY media
              ORDER BY cnt DESC, media" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function downloadssum($tlimit) {
        $sql = "SELECT COUNT(*) as cnt, SUM(size) as filesize
                  FROM " . $this->hlp->prefix . "media as A
                 WHERE $tlimit
                   AND mime1 != 'image'";
        return $this->hlp->runSQL($sql);
    }

    public function referer($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, ref as url
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
                   AND ref_type = 'external'
              GROUP BY ref_md5
              ORDER BY cnt DESC, url" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function newreferer($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, ref as url
                  FROM " . $this->hlp->prefix . "access as B,
                       " . $this->hlp->prefix . "refseen as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
                   AND ref_type = 'external'
                   AND A.ref_md5 = B.ref_md5
              GROUP BY A.ref_md5
              ORDER BY cnt DESC, url" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function countries($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(DISTINCT session) as cnt, B.code AS cflag, B.country
                  FROM " . $this->hlp->prefix . "access as A,
                       " . $this->hlp->prefix . "iplocation as B
                 WHERE $tlimit
                   AND A.ip = B.ip
              GROUP BY B.code
              ORDER BY cnt DESC, B.country" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function browsers($tlimit, $start = 0, $limit = 20, $ext = true) {
        if($ext) {
            $sel = 'ua_info as bflag, ua_info as browser, ua_ver';
            $grp = 'ua_info, ua_ver';
        } else {
            $grp = 'ua_info';
            $sel = 'ua_info';
        }

        $sql = "SELECT COUNT(DISTINCT session) as cnt, $sel
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
              GROUP BY $grp
              ORDER BY cnt DESC, ua_info" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function os($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(DISTINCT session) as cnt, os as osflag, os
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
              GROUP BY os
              ORDER BY cnt DESC, os" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function topuser($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, user
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type = 'browser'
                   AND user != ''
              GROUP BY user
              ORDER BY cnt DESC, user" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function topeditor($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, user
                  FROM " . $this->hlp->prefix . "edits as A
                 WHERE $tlimit
                   AND user != ''
              GROUP BY user
              ORDER BY cnt DESC, user" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function topgroup($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, `group`
                  FROM " . $this->hlp->prefix . "groups as A
                 WHERE $tlimit
                   AND `type` = 'view'
              GROUP BY `group`
              ORDER BY cnt DESC, `group`" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function topgroupedit($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(*) as cnt, `group`
                  FROM " . $this->hlp->prefix . "groups as A
                 WHERE $tlimit
                   AND `type` = 'edit'
              GROUP BY `group`
              ORDER BY cnt DESC, `group`" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }


    public function resolution($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(DISTINCT uid) as cnt,
                       ROUND(screen_x/100)*100 as res_x,
                       ROUND(screen_y/100)*100 as res_y,
                       CONCAT(ROUND(screen_x/100)*100,'x',ROUND(screen_y/100)*100) as resolution
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type  = 'browser'
                   AND screen_x != 0
                   AND screen_y != 0
              GROUP BY resolution
              ORDER BY cnt DESC" .
            $this->mklimit($start, $limit);
        return $this->hlp->runSQL($sql);
    }

    public function viewport($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT COUNT(DISTINCT uid) as cnt,
                       ROUND(view_x/100)*100 as res_x,
                       ROUND(view_y/100)*100 as res_y,
                       CONCAT(ROUND(view_x/100)*100,'x',ROUND(view_y/100)*100) as resolution
                  FROM " . $this->hlp->prefix . "access as A
                 WHERE $tlimit
                   AND ua_type  = 'browser'
                   AND view_x != 0
                   AND view_y != 0
              GROUP BY resolution
              ORDER BY cnt DESC" .
            $this->mklimit($start, $limit);

        return $this->hlp->runSQL($sql);
    }

    public function seenusers($tlimit, $start = 0, $limit = 20) {
        $sql = "SELECT `user`, `dt`
                  FROM " . $this->hlp->prefix . "lastseen as A
              ORDER BY `dt` DESC" .
            $this->mklimit($start, $limit);

        return $this->hlp->runSQL($sql);
    }


    /**
     * Builds a limit clause
     */
    public function mklimit($start, $limit) {
        $start = (int) $start;
        $limit = (int) $limit;
        if($limit) {
            $limit += 1;
            return " LIMIT $start,$limit";
        } elseif($start) {
            return " OFFSET $start";
        }
        return '';
    }

    /**
     * Create a time limit for use in SQL
     */
    public function mktlimit(&$from, &$to) {
        // fixme add better sanity checking here:
        $from = preg_replace('/[^\d\-]+/', '', $from);
        $to   = preg_replace('/[^\d\-]+/', '', $to);
        if(!$from) $from = date('Y-m-d');
        if(!$to) $to = date('Y-m-d');

        return "A.dt >= '$from 00:00:00' AND A.dt <= '$to 23:59:59'";
    }
}
