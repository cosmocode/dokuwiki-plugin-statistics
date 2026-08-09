<?php

namespace dokuwiki\plugin\statistics;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\AbstractParser;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DeviceDetector\Parser\OperatingSystem;
use dokuwiki\Input\Input;
use dokuwiki\plugin\sqlite\SQLiteDB;
use helper_plugin_popularity;
use helper_plugin_statistics;

class Logger
{
    /** @var helper_plugin_statistics The statistics helper plugin instance */
    protected helper_plugin_statistics $hlp;

    /** @var SQLiteDB The SQLite database instance */
    protected SQLiteDB $db;

    /** @var string The full user agent string */
    protected string $uaAgent;

    /** @var string The type of user agent (browser, robot, feedreader) */
    protected string $uaType = 'browser';

    /** @var string The browser/client name */
    protected string $uaName;

    /** @var string The browser/client version */
    protected string $uaVersion;

    /** @var string The operating system/platform */
    protected string $uaPlatform;

    /** @var string|null The user name, if available */
    protected ?string $user = null;

    /** @var string The unique user identifier */
    protected string $uid;

    /** @var string The session identifier */
    protected string $session;

    /** @var int|null The ID of the main access log entry if any */
    protected ?int $hit = null;

    // region lifecycle

    /**
     * Constructor
     *
     * Parses browser info and set internal vars
     * @throws IgnoreException
     */
    public function __construct(helper_plugin_statistics $hlp)
    {
        /** @var Input $INPUT */
        global $INPUT;

        $this->hlp = $hlp;
        $this->db = $this->hlp->getDB();

        // FIXME if we already have a session, we should not re-parse the user agent

        $ua = trim($INPUT->server->str('HTTP_USER_AGENT'));
        AbstractDeviceParser::setVersionTruncation(AbstractParser::VERSION_TRUNCATION_MAJOR);
        $dd = new DeviceDetector($ua, ClientHints::factory($_SERVER));
        $dd->discardBotInformation();
        $dd->parse();

        if ($dd->isFeedReader()) {
            $this->uaType = 'feedreader';
        } elseif ($dd->isBot()) {
            $this->uaType = 'robot';
            // for now ignore bots
            throw new IgnoreException('Bot detected, not logging');
        }

        $this->uaAgent = $ua;
        $this->uaName = $dd->getClient('name') ?: 'Unknown';
        $this->uaVersion = $dd->getClient('version') ?: '0';
        $this->uaPlatform = OperatingSystem::getOsFamily($dd->getOs('name')) ?: 'Unknown';
        $this->uid = $this->getUID();
        $this->session = $this->getSession();

        if (!$this->hlp->getConf('nousers')) {
            $this->user = $INPUT->server->str('REMOTE_USER', null, true);
        }
    }

    /**
     * Should be called before logging
     *
     * This starts a transaction, so all logging is done in one go. It also logs the user and session data.
     */
    public function begin(): void
    {
        $this->db->getPdo()->beginTransaction();

        $this->logUser();
        $this->logGroups();
        $this->logDomain();
        $this->logSession();
        $this->logCampaign();
    }

    /**
     * Should be called after logging
     *
     * This commits the transaction started in begin()
     */
    public function end(): void
    {
        $this->db->getPdo()->commit();
    }

    // endregion
    // region data gathering

    /**
     * Get the unique user ID
     *
     * The user ID is stored in the user preferences and should stay there forever.
     * @return string The unique user identifier
     * @throws IgnoreException
     */
    protected function getUID(): string
    {
        if (!isset($_SESSION[DOKU_COOKIE]['statistics']['uid'])) {
            // when there is no session UID set, we assume this was deliberate and we simply abort all logging
            // @todo we may later make UID generation optional
            throw new IgnoreException('No user ID found');
        }

        return $_SESSION[DOKU_COOKIE]['statistics']['uid'];
    }

    /**
     * Return the user's session ID
     *
     * @return string The session identifier
     * @throws IgnoreException
     */
    protected function getSession(): string
    {
        if (!isset($_SESSION[DOKU_COOKIE]['statistics']['id'])) {
            // when there is no session ID set, we assume this was deliberate and we simply abort all logging
            throw new IgnoreException('No session ID found');
        }

        return $_SESSION[DOKU_COOKIE]['statistics']['id'];
    }

    // endregion
    // region automatic logging

    /**
     * Log the user was seen
     */
    protected function logUser(): void
    {
        if (!$this->user) return;

        $this->db->exec(
            'INSERT INTO users (user, dt)
                  VALUES (?, CURRENT_TIMESTAMP)
            ON CONFLICT (user) DO UPDATE SET
                         dt = CURRENT_TIMESTAMP
                   WHERE excluded.user = users.user
            ',
            $this->user
        );
    }

    /**
     * Log the session and user agent information
     */
    protected function logSession(): void
    {
        $this->db->exec(
            'INSERT INTO sessions (session, dt, end, uid, user, ua, ua_info, ua_type, ua_ver, os)
                  VALUES (?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (session) DO UPDATE SET
                         end = CURRENT_TIMESTAMP,
                         user = excluded.user,
                         uid = excluded.uid
                   WHERE excluded.session = sessions.session
             ',
            $this->session,
            $this->uid,
            $this->user,
            $this->uaAgent,
            $this->uaName,
            $this->uaType,
            $this->uaVersion,
            $this->uaPlatform
        );
    }

    /**
     * Log UTM campaign data
     *
     * @return void
     */
    protected function logCampaign(): void
    {
        global $INPUT;

        $campaign = $INPUT->filter('trim')->str('utm_campaign', null, true);
        $source = $INPUT->filter('trim')->str('utm_source', null, true);
        $medium = $INPUT->filter('trim')->str('utm_medium', null, true);

        if (!$campaign && !$source && !$medium) return;

        $this->db->exec(
            'INSERT OR IGNORE INTO campaigns (session, campaign, source, medium)
                  VALUES (?, ?, ?, ?)',
            $this->session,
            $campaign,
            $source,
            $medium
        );
    }

    /**
     * Log all groups for the user
     *
     * @todo maybe this should be done only once per session?
     */
    protected function logGroups(): void
    {
        global $USERINFO;

        if (!$this->user) return;
        if (!isset($USERINFO['grps'])) return;
        if (!is_array($USERINFO['grps'])) return;
        $groups = $USERINFO['grps'];

        $this->db->exec('DELETE FROM groups WHERE user = ?', $this->user);

        if ($groups === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($groups), '(?, ?)'));
        $params = [];
        $sql = "INSERT INTO groups (`user`, `group`) VALUES $placeholders";
        foreach ($groups as $group) {
            $params[] = $this->user;
            $params[] = $group;
        }
        $this->db->exec($sql, $params);
    }

    /**
     * Log email domain
     *
     * @todo maybe this should be done only once per session?
     */
    protected function logDomain(): void
    {
        global $USERINFO;
        if (!$this->user) return;
        if (!isset($USERINFO['mail'])) return;
        $mail = $USERINFO['mail'];

        $pos = strrpos($mail, '@');
        if (!$pos) return;
        $domain = substr($mail, $pos + 1);
        if (empty($domain)) return;

        $sql = 'UPDATE users SET domain = ? WHERE user = ?';
        $this->db->exec($sql, [$domain, $this->user]);
    }

    // endregion
    // region internal loggers called by the dispatchers

    /**
     * Log the given referer URL
     *
     * Note: we DO log empty referers. These are external accesses that did not provide a referer URL.
     * We do not log referers that are our own pages though.
     *
     * engine set -> a search engine referer
     * no engine set, url empty -> a direct access (bookmark, direct link, etc.)
     * no engine set, url not empty -> a referer from another page (not a wiki page)
     * null returned -> referer was a wiki page
     *
     * @param $referer
     * @return int|null The referer ID or null if no referer was logged
     * @todo we could check against a blacklist here
     */
    public function logReferer($referer): ?int
    {
        $referer = trim($referer);

        // do not log our own pages as referers (empty referer is OK though)
        if (!empty($referer)) {
            $selfre = '^' . preg_quote(DOKU_URL, '/');
            if (preg_match("/$selfre/", $referer)) {
                return null;
            }
        }

        // is it a search engine?
        $se = new SearchEngines($referer);
        $engine = $se->getEngine();

        $sql = 'INSERT OR IGNORE INTO referers (url, engine, dt) VALUES (?, ?, CURRENT_TIMESTAMP)';
        $this->db->exec($sql, [$referer, $engine]);
        return (int)$this->db->queryValue('SELECT id FROM referers WHERE url = ?', $referer);
    }

    /**
     * Resolve IP to country/city and store in database
     *
     * @return string The IP address as stored
     */
    public function logIp(): string
    {
        $ip = clientIP(true);

        // anonymize the IP address for storage?
        if ($this->hlp->getConf('anonips')) {
            $hash = md5($ip . strrev($ip)); // we use the reversed IP as salt to avoid common rainbow tables
            $host = '';
        } else {
            $hash = $ip;
            $host = gethostbyaddr($ip);
        }

        if ($this->hlp->getConf('nolocation')) {
            // if we don't resolve location data, we just return the IP address
            return $hash;
        }

        // check if IP already known and up-to-date
        $result = $this->db->queryValue(
            "SELECT ip
             FROM   iplocation
             WHERE  ip = ?
               AND  dt > date('now', '-30 days')",
            $hash
        );
        if ($result) return $hash; // already known and up-to-date


        // resolve the IP address to location data
        try {
            $data = $this->hlp->resolveIP($ip);
        } catch (IpResolverException $e) {
            \dokuwiki\Logger::error('Statistics Plugin: ' . $e->getMessage(), $e->details);
            $data = [];
        }

        $this->db->exec(
            'INSERT OR REPLACE INTO iplocation (
                    ip, country, code, city, host, dt
                 ) VALUES (
                    ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
                 )',
            $hash,
            $data['country'] ?? '',
            $data['countryCode'] ?? '',
            $data['city'] ?? '',
            $host
        );

        return $hash;
    }

    // endregion
    // region log dispatchers

    public function logPageView(): void
    {
        global $INPUT;

        if (!$INPUT->str('p')) return;


        $referer = $INPUT->filter('trim')->str('r');
        $ip = $this->logIp(); // resolve the IP address

        $data = [
            'page' => $INPUT->filter('cleanID')->str('p'),
            'ip' => $ip,
            'ref_id' => $this->logReferer($referer),
            'sx' => $INPUT->int('sx'),
            'sy' => $INPUT->int('sy'),
            'vx' => $INPUT->int('vx'),
            'vy' => $INPUT->int('vy'),
            'session' => $this->session,
        ];

        $this->db->exec(
            '
        INSERT INTO pageviews (
            dt, page, ip, ref_id, screen_x, screen_y, view_x, view_y, session
        ) VALUES (
            CURRENT_TIMESTAMP, :page, :ip, :ref_id, :sx, :sy, :vx, :vy, :session
        )
        ',
            $data
        );
    }

    /**
     * Log a click on an external link
     *
     * Called from dispatch.php
     */
    public function logOutgoing(): void
    {
        global $INPUT;

        if (!$INPUT->str('ol')) return;

        $link = $INPUT->filter('trim')->str('ol');
        $session = $this->session;
        $page = $INPUT->filter('cleanID')->str('p');

        $this->db->exec(
            'INSERT INTO outlinks (
                dt, session, page, link
             ) VALUES (
                CURRENT_TIMESTAMP, ?, ?, ?
             )',
            $session,
            $page,
            $link
        );
    }

    /**
     * Log access to a media file
     *
     * Called from action.php
     *
     * @param string $media The media ID
     * @param string $mime The media's mime type
     * @param bool $inline Is this displayed inline?
     * @param int $size Size of the media file
     */
    public function logMedia(string $media, string $mime, bool $inline, int $size): void
    {
        [$mime1, $mime2] = explode('/', strtolower($mime));
        $inline = $inline ? 1 : 0;


        $data = [
            'media' => cleanID($media),
            'ip' => $this->logIp(), // resolve the IP address
            'session' => $this->session,
            'size' => $size,
            'mime1' => $mime1,
            'mime2' => $mime2,
            'inline' => $inline,
        ];

        $this->db->exec(
            '
                INSERT INTO media ( dt, media, ip, session, size, mime1, mime2, inline )
                     VALUES (CURRENT_TIMESTAMP, :media, :ip, :session, :size, :mime1, :mime2, :inline)
            ',
            $data
        );
    }

    /**
     * Log page edits
     *
     * called from action.php
     *
     * @param string $page The page that was edited
     * @param string $type The type of edit (create, edit, etc.)
     */
    public function logEdit(string $page, string $type): void
    {
        $data = [
            'page' => cleanID($page),
            'type' => $type,
            'ip' => $this->logIp(), // resolve the IP address
            'session' => $this->session
        ];

        $this->db->exec(
            'INSERT INTO edits (
                dt, page, type, ip, session
             ) VALUES (
                CURRENT_TIMESTAMP, :page, :type, :ip, :session
             )',
            $data
        );
    }

    /**
     * Log login/logoffs and user creations
     *
     * @param string $type The type of login event (login, logout, create, failed)
     * @param string $user The username
     */
    public function logLogin(string $type, string $user = ''): void
    {
        global $INPUT;

        if (!$user) $user = $INPUT->server->str('REMOTE_USER');

        $ip = clientIP(true);

        $this->db->exec(
            'INSERT INTO logins (
                dt, ip, user, type
             ) VALUES (
                CURRENT_TIMESTAMP, ?, ?, ?
             )',
            $ip,
            $user,
            $type
        );
    }

    /**
     * Log search data to the search related tables
     *
     * @param string $query The search query
     * @param string[] $words The query split into words
     */
    public function logSearch(string $query, array $words): void
    {
        if (!$query) return;

        $sid = $this->db->exec(
            'INSERT INTO search (dt, ip, session, query) VALUES (CURRENT_TIMESTAMP, ?, ? , ?)',
            $this->logIp(), // resolve the IP address
            $this->session,
            $query,
        );

        foreach ($words as $word) {
            if (!$word) continue;
            $this->db->exec(
                'INSERT INTO searchwords (sid, word) VALUES (?, ?)',
                $sid,
                $word
            );
        }
    }

    /**
     * Log the current page count and size as today's history entry
     */
    public function logHistoryPages(): void
    {
        global $conf;

        // use the popularity plugin's search method to find the wanted data
        /** @var helper_plugin_popularity $pop */
        $pop = plugin_load('helper', 'popularity');
        $list = $this->initEmptySearchList();
        search($list, $conf['datadir'], $pop->searchCountCallback(...), ['all' => false], '');
        $page_count = $list['file_count'];
        $page_size = $list['file_size'];

        $this->db->exec(
            'INSERT OR REPLACE INTO history (
                info, value, dt
             ) VALUES (
                ?, ?, CURRENT_TIMESTAMP
             )',
            'page_count',
            $page_count
        );
        $this->db->exec(
            'INSERT OR REPLACE INTO history (
                info, value, dt
             ) VALUES (
                ?, ?, CURRENT_TIMESTAMP
             )',
            'page_size',
            $page_size
        );
    }

    /**
     * Log the current media count and size as today's history entry
     */
    public function logHistoryMedia(): void
    {
        global $conf;

        // use the popularity plugin's search method to find the wanted data
        /** @var helper_plugin_popularity $pop */
        $pop = plugin_load('helper', 'popularity');
        $list = $this->initEmptySearchList();
        search($list, $conf['mediadir'], $pop->searchCountCallback(...), ['all' => true], '');
        $media_count = $list['file_count'];
        $media_size = $list['file_size'];

        $this->db->exec(
            'INSERT OR REPLACE INTO history (
                info, value, dt
             ) VALUES (
                ?, ?, CURRENT_TIMESTAMP
             )',
            'media_count',
            $media_count
        );
        $this->db->exec(
            'INSERT OR REPLACE INTO history (
                info, value, dt
             ) VALUES (
                ?, ?, CURRENT_TIMESTAMP
             )',
            'media_size',
            $media_size
        );
    }

    // endregion

    /**
     * @todo can be dropped in favor of helper_plugin_popularity::initEmptySearchList() once it's public
     * @return array
     */
    protected function initEmptySearchList()
    {
        return array_fill_keys([
            'file_count',
            'file_size',
            'file_max',
            'file_min',
            'dir_count',
            'dir_nest',
            'file_oldest'
        ], 0);
    }
}
