<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
// Using autoloading instead of require_once for better compatibility

class action_plugin_statistics extends DokuWiki_Action_Plugin {

    /**
     * register the eventhandlers and initialize some options
     */
    function register(Doku_Event_Handler $controller) {
        global $JSINFO;
        global $ACT;
        $JSINFO['act'] = $ACT;

        $controller->register_hook(
            'IO_WIKIPAGE_WRITE',
            'BEFORE',
            $this,
            'logedits',
            array()
        );
        $controller->register_hook(
            'SEARCH_QUERY_FULLPAGE',
            'AFTER',
            $this,
            'logsearch',
            array()
        );
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'loglogins',
            array()
        );
        $controller->register_hook(
            'AUTH_USER_CHANGE',
            'AFTER',
            $this,
            'logregistration',
            array()
        );
        $controller->register_hook(
            'FETCH_MEDIA_STATUS',
            'BEFORE',
            $this,
            'logmedia',
            array()
        );
        $controller->register_hook(
            'INDEXER_TASKS_RUN',
            'AFTER',
            $this,
            'loghistory',
            array()
        );
    }

    /**
     * @fixme call this in the webbug call
     */
    function putpixel() {
        global $ID;
        $url = DOKU_BASE . 'lib/plugins/statistics/log.php?p=' . rawurlencode($ID) .
            '&amp;r=' . rawurlencode($_SERVER['HTTP_REFERER']) . '&rnd=' . time();

        echo '<noscript><img src="' . $url . '" width="1" height="1" /></noscript>';
    }

    /**
     * Log page edits actions
     */
    function logedits(Doku_Event $event, $param) {
        if($event->data[3]) return; // no revision

        if(file_exists($event->data[0][0])) {
            if($event->data[0][1] == '') {
                $type = 'D';
            } else {
                $type = 'E';
            }
        } else {
            $type = 'C';
        }
        /** @var helper_plugin_statistics $hlp */
        $hlp = plugin_load('helper', 'statistics');
        $hlp->Logger()->log_edit(cleanID($event->data[1] . ':' . $event->data[2]), $type);
    }

    /**
     * Log internal search
     */
    function logsearch(Doku_Event $event, $param) {
        /** @var helper_plugin_statistics $hlp */
        $hlp = plugin_load('helper', 'statistics');
        $hlp->Logger()->log_search('', $event->data['query'], $event->data['highlight'], 'dokuwiki');
    }

    /**
     * Log login/logouts
     */
    function loglogins(Doku_Event $event, $param) {
        $type = '';
        $act  = $this->_act_clean($event->data);
        if($act == 'logout') {
            $type = 'o';
        } elseif(isset($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] && $act == 'login') {
            if(isset($_REQUEST['r']) && $_REQUEST['r']) {
                $type = 'p';
            } else {
                $type = 'l';
            }
        } elseif(isset($_REQUEST['u']) && $_REQUEST['u'] && !isset($_REQUEST['http_credentials']) && !isset($_SERVER['REMOTE_USER'])) {
            $type = 'f';
        }
        if(!$type) return;

        /** @var helper_plugin_statistics $hlp */
        $hlp = plugin_load('helper', 'statistics');
        $hlp->Logger()->log_login($type);
    }

    /**
     * Log user creations
     */
    function logregistration(Doku_Event $event, $param) {
        if($event->data['type'] == 'create') {
            /** @var helper_plugin_statistics $hlp */
            $hlp = plugin_load('helper', 'statistics');
            $hlp->Logger()->log_login('C', $event->data['params'][0]);
        }
    }

    /**
     * Log media access
     */
    function logmedia(Doku_Event $event, $param) {
        if($event->data['status'] < 200) return;
        if($event->data['status'] >= 400) return;
        if(preg_match('/^\w+:\/\//', $event->data['media'])) return;

        // no size for redirect/not modified
        if($event->data['status'] >= 300) {
            $size = 0;
        } else {
            $size = @filesize($event->data['file']);
        }

        /** @var helper_plugin_statistics $hlp */
        $hlp = plugin_load('helper', 'statistics');
        $hlp->Logger()->log_media(
            $event->data['media'],
            $event->data['mime'],
            !$event->data['download'],
            $size
        );
    }

    /**
     * Log the daily page and media counts for the history
     */
    function loghistory(Doku_Event $event, $param) {
        echo 'Plugin Statistics: started'.DOKU_LF;

        /** @var helper_plugin_statistics $hlp */
        $hlp = plugin_load('helper', 'statistics');

        // check if a history was gathered already today
        $sql = "SELECT `info` FROM " . $hlp->prefix . "history WHERE `dt` = DATE(NOW())";
        $result = $hlp->runSQL($sql);
        if(is_null($result)) {
            global $MSG;
            print_r($MSG);
        }

        $page_ran  = false;
        $media_ran = false;
        foreach($result as $row) {
            if($row['info'] == 'page_count')  $page_ran  = true;
            if($row['info'] == 'media_count') $media_ran = true;
        }

        if($page_ran && $media_ran){
            echo 'Plugin Statistics: nothing to do - finished'.DOKU_LF;
            return;
        }

        $event->stopPropagation();
        $event->preventDefault();

        if($page_ran) {
            echo 'Plugin Statistics: logging media'.DOKU_LF;
            $hlp->Logger()->log_history_media();
        } else {
            echo 'Plugin Statistics: logging pages'.DOKU_LF;
            $hlp->Logger()->log_history_pages();
        }
        echo 'Plugin Statistics: finished'.DOKU_LF;
    }

    /**
     * Pre-Sanitize the action command
     *
     * Similar to act_clean in action.php but simplified and without
     * error messages
     */
    function _act_clean($act) {
        // check if the action was given as array key
        if(is_array($act)) {
            list($act) = array_keys($act);
        }

        //remove all bad chars
        $act = strtolower($act);
        $act = preg_replace('/[^a-z_]+/', '', $act);

        return $act;
    }
}
