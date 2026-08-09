<?php

namespace dokuwiki\plugin\statistics;

use dokuwiki\Logger;
use dokuwiki\plugin\sqlite\SQLiteDB;
use helper_plugin_statistics;

/**
 * This class defines a bunch of SQL queries to fetch various statistics from the database
 */
class Query
{
    protected helper_plugin_statistics $hlp;
    protected SQLiteDB $db;
    protected string $from;
    protected string $to;
    protected string $limit = '';
    protected string $tz = 'localtime';

    /**
     * @param helper_plugin_statistics $hlp
     */
    public function __construct(helper_plugin_statistics $hlp)
    {
        $this->hlp = $hlp;
        $this->db = $hlp->getDB();
        $today = date('Y-m-d');
        $this->setTimeFrame($today, $today);
        $this->setPagination(0, 20);
    }

    /**
     * Set the time frame for all queries
     *
     * @param string $from The start date as YYYY-MM-DD
     * @param string $to The end date as YYYY-MM-DD
     */
    public function setTimeFrame(string $from, string $to): void
    {
        try {
            $from = new \DateTime($from);
            $to = new \DateTime($to);
        } catch (\Exception) {
            $from = new \DateTime();
            $to = new \DateTime();
        }
        $from->setTime(0, 0);
        $to->setTime(23, 59, 59);

        $this->from = $from->format('Y-m-d H:i:s');
        $this->to = $to->format('Y-m-d H:i:s');

        $this->setTimezone();
    }

    /**
     * Force configured timezone.
     * This is useful if you cannot set localtime on the server.
     *
     * @return void
     */
    public function setTimezone()
    {
        $timezoneId = $this->hlp->getConf('timezone');
        if (!$timezoneId || !in_array($timezoneId, \DateTimeZone::listIdentifiers())) return;

        try {
            $dateTime = new \DateTime($this->from, new \DateTimeZone($timezoneId));
            $this->tz = $dateTime->format('P');
        } catch (\Exception $e) {
            Logger::error($e->getMessage());
        }
    }

    /**
     * Set the pagination settings for some queries
     *
     * @param int $start The start offset
     * @param int $limit The number of results. If one more is returned, there is another page
     * @return void
     */
    public function setPagination(int $start, int $limit)
    {
        // when a limit is set, one more is fetched to indicate when a next page exists
        if ($limit) $limit += 1;

        if ($limit) {
            $this->limit = " LIMIT $start,$limit";
        } elseif ($start) {
            $this->limit = " OFFSET $start";
        }
    }

    /**
     * Return some aggregated statistics
     */
    public function aggregate(): array
    {
        // init some values that might not be set
        $data = [
            'referers' => 0, // total number of (external) referrers
            'external' => 0, // external referrers
            'search' => 0, // search engine referrers
            'direct' => 0, // direct referrers
            'internal' => 0, // internal referrers
            'bouncerate' => 0,
            'newvisitors' => 0,
        ];

        // Count referrer types by joining with referers table
        $sql = "SELECT
                    CASE
                        WHEN R.engine IS NOT NULL THEN 'search'
                        WHEN R.url = '' THEN 'direct'
                        WHEN R.url IS NOT NULL THEN 'external'
                        ELSE 'internal'
                    END as ref_type,
                    COUNT(*) as cnt
                  FROM pageviews as P
                  LEFT JOIN referers as R ON P.ref_id = R.id
                  LEFT JOIN sessions as S ON P.session = S.session
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND S.ua_type = 'browser'
              GROUP BY ref_type";
        $result = $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);

        foreach ($result as $row) {
            if ($row['ref_type'] == 'search') {
                $data['search'] = $row['cnt'];
                $data['referers'] += $row['cnt'];
            }
            if ($row['ref_type'] == 'direct') {
                $data['direct'] = $row['cnt'];
                $data['referers'] += $row['cnt'];
            }
            if ($row['ref_type'] == 'external') {
                $data['external'] = $row['cnt'];
                $data['referers'] += $row['cnt'];
            }
            if ($row['ref_type'] == 'internal') {
                $data['internal'] = $row['cnt'];
            }
        }

        // general user and session info
        $sql = "SELECT COUNT(DISTINCT P.session) as sessions,
                       COUNT(P.session) as views,
                       COUNT(DISTINCT S.user) as users,
                       COUNT(DISTINCT S.uid) as visitors,
                       DATETIME(MAX(P.dt), ?) as last
                  FROM pageviews as P
                  LEFT JOIN sessions as S ON P.session = S.session
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND S.ua_type = 'browser'";
        $result = $this->db->queryRecord($sql, [$this->tz, $this->tz, $this->from, $this->tz, $this->to]);

        $data['users'] = $result['users'];
        $data['sessions'] = $result['sessions'];
        $data['pageviews'] = $result['views'];
        $data['visitors'] = $result['visitors'];
        $data['last'] = $result['last'];

        // calculate bounce rate (sessions with only 1 page view)
        if ($data['sessions']) {
            $sql = "SELECT COUNT(*) as cnt
                      FROM (
                          SELECT P.session, COUNT(*) as views
                            FROM pageviews as P
                            LEFT JOIN sessions as S ON P.session = S.session
                           WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                             AND S.ua_type = 'browser'
                        GROUP BY P.session
                          HAVING views = 1
                      )";
            $count = $this->db->queryValue($sql, [$this->tz, $this->from, $this->tz, $this->to]);
            $data['bouncerate'] = $count * 100 / $data['sessions'];
            $data['newvisitors'] = $count * 100 / $data['sessions'];
        }

        // calculate avg. number of views per session
        $sql = "SELECT AVG(views) as cnt
                  FROM (
                      SELECT P.session, COUNT(*) as views
                        FROM pageviews as P
                        LEFT JOIN sessions as S ON P.session = S.session
                       WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                         AND S.ua_type = 'browser'
                    GROUP BY P.session
                  )";
        $data['avgpages'] = $this->db->queryValue($sql, [$this->tz, $this->from, $this->tz, $this->to]);

        // average time spent on the site
        $sql = "SELECT AVG((unixepoch(end) - unixepoch(dt)) / 60) as time
                  FROM sessions as S
                 WHERE S.dt != S.end
                   AND DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.ua_type = 'browser'";
        $data['timespent'] = $this->db->queryValue($sql, [$this->tz, $this->from, $this->tz, $this->to]);

        // logins
        $sql = "SELECT COUNT(*) as logins
                  FROM logins as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND (type = 'l' OR type = 'p')";
        $data['logins'] = $this->db->queryValue($sql, [$this->tz, $this->from, $this->tz, $this->to]);

        // registrations
        $sql = "SELECT COUNT(*) as registrations
                  FROM logins as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND type = 'C'";
        $data['registrations'] = $this->db->queryValue($sql, [$this->tz, $this->from, $this->tz, $this->to]);

        // current users (based on recent sessions)
        $sql = "SELECT COUNT(DISTINCT uid) as current
                  FROM sessions
                 WHERE end >= datetime('now', '-10 minutes')";
        $data['current'] = $this->db->queryValue($sql);

        return $data;
    }


    /**
     * Return some trend data about visits and edits in the wiki
     *
     * @param bool $hours Use hour resolution rather than days
     * @return array
     */
    public function dashboardviews(bool $hours = false): array
    {
        if ($hours) {
            $TIME = "strftime('%H', DATETIME(P.dt, '$this->tz'))";
        } else {
            $TIME = "DATE(DATETIME(P.dt, '$this->tz'))";
        }

        $data = [];

        // access trends
        $sql = "SELECT $TIME as time,
                       COUNT(DISTINCT P.session) as sessions,
                       COUNT(P.session) as pageviews,
                       COUNT(DISTINCT S.uid) as visitors
                  FROM pageviews as P
                  LEFT JOIN sessions as S ON P.session = S.session
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND S.ua_type = 'browser'
              GROUP BY $TIME
              ORDER BY time";
        $result = $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
        foreach ($result as $row) {
            $data[$row['time']]['sessions'] = $row['sessions'];
            $data[$row['time']]['pageviews'] = $row['pageviews'];
            $data[$row['time']]['visitors'] = $row['visitors'];
        }
        return $data;
    }

    /**
     * @param bool $hours Use hour resolution rather than days
     * @return array
     */
    public function dashboardwiki(bool $hours = false): array
    {
        if ($hours) {
            $TIME = "strftime('%H', DATETIME(dt, '$this->tz'))";
        } else {
            $TIME = "DATE(DATETIME(dt, '$this->tz'))";
        }

        $data = [];

        // edit trends
        foreach (['E', 'C', 'D'] as $type) {
            $sql = "SELECT $TIME as time,
                           COUNT(*) as cnt
                      FROM edits as A
                     WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                       AND type = '$type'
                  GROUP BY $TIME
                  ORDER BY time";
            $result = $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
            foreach ($result as $row) {
                $data[$row['time']][$type] = $row['cnt'];
            }
        }
        ksort($data);
        return $data;
    }

    /**
     * @param string $info Which type of history to select (FIXME which ones are there?)
     * @param string $interval Group data by this interval (days, weeks, months)
     * @return array
     */
    public function history(string $info, string $interval = 'day'): array
    {
        if ($interval == 'weeks') {
            $TIME = "strftime('%Y', DATETIME(dt, '$this->tz')), strftime('%W', DATETIME(dt, '$this->tz'))";
        } elseif ($interval == 'months') {
            $TIME = "strftime('%Y-%m', DATETIME(dt, '$this->tz'))";
        } else {
            $TIME = "strftime('%d-%m', DATETIME(dt, '$this->tz'))";
        }

        $mod = 1;
        if ($info == 'media_size' || $info == 'page_size') {
            $mod = 1024 * 1024;
        }

        $sql = "SELECT $TIME as time,
                       AVG(value)/$mod as cnt
                  FROM history as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND info = ?
                  GROUP BY $TIME
                  ORDER BY $TIME";

        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to, $info]);
    }

    /**
     * @return array
     */
    public function searchengines(): array
    {
        $sql = "SELECT COUNT(*) as cnt, R.engine
                  FROM pageviews as P,
                       referers as R
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.ref_id = R.id
                   AND R.engine != ''
              GROUP BY R.engine
              ORDER BY cnt DESC, R.engine" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function internalsearchphrases(): array
    {
        $sql = "SELECT COUNT(*) as cnt, query, query as ilookup
                  FROM search
                 WHERE DATETIME(dt, ?) >= ? AND DATETIME(dt, ?) <= ?
              GROUP BY query
              ORDER BY cnt DESC, query" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function internalsearchwords(): array
    {
        $sql = "SELECT COUNT(*) as cnt, SW.word, SW.word as ilookup
                  FROM search as S,
                       searchwords as SW
                 WHERE DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.id = SW.sid
              GROUP BY SW.word
              ORDER BY cnt DESC, SW.word" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function outlinks(): array
    {
        $sql = "SELECT COUNT(*) as cnt, link as url
                  FROM outlinks as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
              GROUP BY link
              ORDER BY cnt DESC, link" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function pages(): array
    {
        $sql = "SELECT COUNT(*) as cnt, P.page
                  FROM pageviews as P,
                       sessions as S
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.session = S.session
                   AND S.ua_type = 'browser'
              GROUP BY P.page
              ORDER BY cnt DESC, P.page" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function edits(): array
    {
        $sql = "SELECT COUNT(*) as cnt, page
                  FROM edits as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
              GROUP BY page
              ORDER BY cnt DESC, page" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function images(): array
    {
        $sql = "SELECT COUNT(*) as cnt, media, SUM(size) as filesize
                  FROM media as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND mime1 = 'image'
              GROUP BY media
              ORDER BY cnt DESC, media" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function imagessum(): array
    {
        $sql = "SELECT COUNT(*) as cnt, SUM(size) as filesize
                  FROM media as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND mime1 = 'image'";
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function downloads(): array
    {
        $sql = "SELECT COUNT(*) as cnt, media, SUM(size) as filesize
                  FROM media as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND mime1 != 'image'
              GROUP BY media
              ORDER BY cnt DESC, media" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function downloadssum(): array
    {
        $sql = "SELECT COUNT(*) as cnt, SUM(size) as filesize
                  FROM media as A
                 WHERE DATETIME(A.dt, ?) >= ? AND DATETIME(A.dt, ?) <= ?
                   AND mime1 != 'image'";
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function referer(): array
    {
        $sql = "SELECT COUNT(*) as cnt, R.url
                  FROM pageviews as P
                  LEFT JOIN sessions as S ON P.session = S.session
                  LEFT JOIN referers as R ON P.ref_id = R.id
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND S.ua_type = 'browser'
                   AND R.url IS NOT NULL
                   AND R.url != ''
                   AND R.engine IS NULL
              GROUP BY R.url
              ORDER BY cnt DESC, R.url" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function newreferer(): array
    {
        $sql = "SELECT COUNT(*) as cnt, R.url
                  FROM pageviews as P
                  LEFT JOIN sessions as S ON P.session = S.session
                  LEFT JOIN referers as R ON P.ref_id = R.id
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND S.ua_type = 'browser'
                   AND R.url IS NOT NULL
                   AND R.url != ''
                   AND R.engine IS NULL
                   AND DATETIME(R.dt, ?) >= ?
              GROUP BY R.url
              ORDER BY cnt DESC, R.url" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to, $this->tz, $this->from]);
    }

    /**
     * @return array
     */
    public function campaigns(): array
    {
        $sql = "SELECT COUNT(*) as cnt, C.campaign
                  FROM campaigns as C,
                       sessions as S
                 WHERE DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.session = C.session
              GROUP BY C.campaign
              ORDER BY cnt DESC, C.campaign" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function source(): array
    {
        $sql = "SELECT COUNT(*) as cnt, C.campaign || ' ' || C.source AS campaign
                  FROM campaigns as C,
                       sessions as S
                 WHERE DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.session = C.session
                   AND C.source IS NOT NULL
              GROUP BY C.campaign, C.source
              ORDER BY cnt DESC, C.campaign" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function medium(): array
    {
        $sql = "SELECT COUNT(*) as cnt, C.campaign || ' ' || C.medium AS campaign
                  FROM campaigns as C,
                       sessions as S
                 WHERE DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.session = C.session
                   AND C.medium IS NOT NULL
              GROUP BY C.campaign, C.medium
              ORDER BY cnt DESC, C.campaign" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }


    /**
     * @return array
     */
    public function countries(): array
    {
        $sql = "SELECT COUNT(DISTINCT P.session) as cnt, I.country
                  FROM pageviews as P,
                       iplocation as I
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.ip = I.ip
                   AND I.country != ''
              GROUP BY I.code
              ORDER BY cnt DESC, I.country" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @param bool $ext return extended information
     * @return array
     */
    public function browsers(bool $ext = false): array
    {
        if ($ext) {
            $sel = 'S.ua_info as browser, S.ua_ver';
            $grp = 'S.ua_info, S.ua_ver';
        } else {
            $sel = 'S.ua_info as browser';
            $grp = 'S.ua_info';
        }

        $sql = "SELECT COUNT(DISTINCT S.session) as cnt, $sel
                  FROM sessions as S
                 WHERE DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.ua_type = 'browser'
              GROUP BY $grp
              ORDER BY cnt DESC, S.ua_info" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function os(): array
    {
        $sql = "SELECT COUNT(DISTINCT S.session) as cnt, S.os
                  FROM sessions as S
                 WHERE DATETIME(S.dt, ?) >= ? AND DATETIME(S.dt, ?) <= ?
                   AND S.ua_type = 'browser'
              GROUP BY S.os
              ORDER BY cnt DESC, S.os" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function topdomain(): array
    {
        $sql = "SELECT COUNT(*) as cnt, U.domain
                  FROM pageviews as P,
                       sessions as S,
                       users as U
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.session = S.session
                   AND S.user = U.user
                   AND S.ua_type = 'browser'
                   AND S.user IS NOT NULL
              GROUP BY U.domain
              ORDER BY cnt DESC, U.domain" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function topuser(): array
    {
        $sql = "SELECT COUNT(*) as cnt, S.user
                  FROM pageviews as P,
                       sessions as S
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.session = S.session
                   AND S.ua_type = 'browser'
                   AND S.user IS NOT NULL
              GROUP BY S.user
              ORDER BY cnt DESC, S.user" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function topeditor(): array
    {
        $sql = "SELECT COUNT(*) as cnt, user
                  FROM edits as E,
                       sessions as S
                 WHERE DATETIME(E.dt, ?) >= ? AND DATETIME(E.dt, ?) <= ?
                   AND E.session = S.session
                   AND S.user IS NOT NULL
              GROUP BY user
              ORDER BY cnt DESC, user" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function topgroup(): array
    {
        $sql = "SELECT COUNT(*) as cnt, G.`group`
                  FROM pageviews as P,
                       sessions as S,
                       groups as G
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.session = S.session
                   AND S.user = G.user
                   AND S.ua_type = 'browser'
              GROUP BY G.`group`
              ORDER BY cnt DESC, G.`group`" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function topgroupedit(): array
    {
        $sql = "SELECT COUNT(*) as cnt, G.`group`
                  FROM edits as E,
                       sessions as S,
                       groups as G
                 WHERE DATETIME(E.dt, ?) >= ? AND DATETIME(E.dt, ?) <= ?
                   AND E.session = S.session
                   AND S.user = G.user
              GROUP BY G.`group`
              ORDER BY cnt DESC, G.`group`" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }


    /**
     * @return array
     */
    public function resolution(): array
    {
        $sql = "SELECT COUNT(DISTINCT S.uid) as cnt,
                       ROUND(P.screen_x/100)*100 as res_x,
                       ROUND(P.screen_y/100)*100 as res_y,
                       CAST(ROUND(P.screen_x/100)*100 AS int)
                           || 'x' ||
                       CAST(ROUND(P.screen_y/100)*100 AS int) as resolution
                  FROM pageviews as P,
                       sessions as S
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.session = S.session
                   AND S.ua_type = 'browser'
                   AND P.screen_x != 0
                   AND P.screen_y != 0
              GROUP BY resolution
              ORDER BY cnt DESC" .
            $this->limit;
        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function viewport(): array
    {
        $sql = "SELECT COUNT(DISTINCT S.uid) as cnt,
                       ROUND(P.view_x/100)*100 as res_x,
                       ROUND(P.view_y/100)*100 as res_y,
                       CAST(ROUND(P.view_x/100)*100 AS int)
                           || 'x' ||
                       CAST(ROUND(P.view_y/100)*100 AS int) as resolution
                  FROM pageviews as P,
                       sessions as S
                 WHERE DATETIME(P.dt, ?) >= ? AND DATETIME(P.dt, ?) <= ?
                   AND P.session = S.session
                   AND S.ua_type = 'browser'
                   AND P.view_x != 0
                   AND P.view_y != 0
              GROUP BY resolution
              ORDER BY cnt DESC" .
            $this->limit;

        return $this->db->queryAll($sql, [$this->tz, $this->from, $this->tz, $this->to]);
    }

    /**
     * @return array
     */
    public function seenusers(): array
    {
        $sql = "SELECT `user`, MAX(`dt`) as dt
                  FROM users
                 WHERE `user` IS NOT NULL
                   AND `user` != ''
              GROUP BY `user`
              ORDER BY `dt` DESC" .
            $this->limit;

        return $this->db->queryAll($sql);
    }
}
