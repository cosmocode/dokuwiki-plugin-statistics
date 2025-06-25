<?php
/**
 * Statistics Plugin
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

class helper_plugin_statistics extends Dokuwiki_Plugin {

    private $dblink = null;
    public $prefix;
    private $oQuery = null;
    private $oLogger = null;
    private $oGraph = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->prefix = $this->getConf('db_prefix');
    }

    /**
     * Return an instance of the query class
     *
     * @return StatisticsQuery
     */
    public function Query() {
        if(is_null($this->oQuery)) {
            require dirname(__FILE__) . '/inc/StatisticsQuery.class.php';
            $this->oQuery = new StatisticsQuery($this);
        }
        return $this->oQuery;
    }

    /**
     * Return an instance of the logger class
     *
     * @return StatisticsLogger
     */
    public function Logger() {
        $this->prefix = $this->getConf('db_prefix');
        if(is_null($this->oLogger)) {
            require dirname(__FILE__) . '/inc/StatisticsLogger.class.php';
            $this->oLogger = new StatisticsLogger($this);
        }
        return $this->oLogger;
    }

    /**
     * Return an instance of the Graph class
     *
     * @return StatisticsGraph
     */
    public function Graph() {
        $this->prefix = $this->getConf('db_prefix');
        if(is_null($this->oGraph)) {
            require dirname(__FILE__) . '/inc/StatisticsGraph.class.php';
            $this->oGraph = new StatisticsGraph($this);
        }
        return $this->oGraph;
    }

    /**
     * Return a link to the DB, opening the connection if needed
     */
    protected function dbLink() {
        // connect to DB if needed
        if(!$this->dblink) {
            if(!$this->getConf('db_server')) return null;

            $this->dblink = mysqli_connect(
                $this->getConf('db_server'),
                $this->getConf('db_user'),
                $this->getConf('db_password')
            );
            if(!$this->dblink) {
                msg('DB Error: connection failed', -1);
                return null;
            }
            if(!mysqli_select_db($this->dblink, $this->getConf('db_database'))) {
                msg('DB Error: failed to select database', -1);
                return null;
            }

            // set utf-8
            if(!mysqli_query($this->dblink, 'set names utf8')) {
                msg('DB Error: could not set UTF-8 (' . mysqli_error($this->dblink) . ')', -1);
                return null;
            }
        }
        return $this->dblink;
    }

    /**
     * Simple function to run a DB query
     */
    public function runSQL($sql_string) {
        $link = $this->dbLink();
        if(!$link) return null;

        $result = mysqli_query($link, $sql_string);
        if($result === false) {
            // Using modern logger instead of deprecated dbglog
            if (class_exists('\dokuwiki\Logger')) {
                \dokuwiki\Logger::error('DB Error: ' . mysqli_error($link) . ' ' . hsc($sql_string));
            }
            msg('DB Error: ' . mysqli_error($link) . ' ' . hsc($sql_string), -1);
            return null;
        }

        $resultarray = array();

        //mysql_db_query returns 1 on a insert statement -> no need to ask for results
        if($result !== true) {
            for($i = 0; $i < mysqli_num_rows($result); $i++) {
                $temparray     = mysqli_fetch_assoc($result);
                $resultarray[] = $temparray;
            }
            mysqli_free_result($result);
        }

        if(mysqli_insert_id($link)) {
            $resultarray = mysqli_insert_id($link); //give back ID on insert
        }

        return $resultarray;
    }

    /**
     * Just send a 1x1 pixel blank gif to the browser
     *
     * @called from log.php
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Harry Fuecks <fuecks@gmail.com>
     */
    function sendGIF($transparent = true) {
        if($transparent) {
            $img = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAEALAAAAAABAAEAAAIBTAA7');
        } else {
            $img = base64_decode('R0lGODdhAQABAIAAAP///////ywAAAAAAQABAAACAkQBADs=');
        }
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($img));
        header('Connection: Close');
        print $img;
        flush();
        // Browser should drop connection after this
        // Thinks it's got the whole image
    }
}
