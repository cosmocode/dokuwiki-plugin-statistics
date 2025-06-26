<?php
/**
 * Statistics plugin - data logger
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../') . '/');
define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC . 'inc/init.php');
session_write_close();

// all features are brokered by the helper plugin
/** @var helper_plugin_statistics $plugin */
$plugin = plugin_load('helper', 'statistics');

// Using modern logger instead of deprecated dbglog
if (class_exists('\dokuwiki\Logger')) {
    \dokuwiki\Logger::debug('Log ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''));
}

switch(isset($_REQUEST['do']) ? $_REQUEST['do'] : '') {
    case 'v':
        $plugin->Logger()->log_access();
        $plugin->Logger()->log_session(1);
        break;

    /** @noinspection PhpMissingBreakStatementInspection */
    case 'o':
        $plugin->Logger()->log_outgoing();

    //falltrough
    default:
        $plugin->Logger()->log_session();
}

// fixme move to top
$plugin->sendGIF();

//Setup VIM: ex: et ts=4 :
