<?php
/**
 * Statistics plugin - image creator
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../') . '/');
define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC . 'inc/init.php');
session_write_close();

/** @var helper_plugin_statistics $plugin */
$plugin = plugin_load('helper', 'statistics');
try {
    if(!auth_ismanager()) throw new Exception('Access denied');
    $plugin->Graph()->render(
        isset($_REQUEST['img']) ? $_REQUEST['img'] : '',
        isset($_REQUEST['f']) ? $_REQUEST['f'] : '',
        isset($_REQUEST['t']) ? $_REQUEST['t'] : '',
        isset($_REQUEST['s']) ? $_REQUEST['s'] : ''
    );
} catch(Exception $e) {
    $plugin->sendGIF(false);
}

//Setup VIM: ex: et ts=4 :
