<?php
/**
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_statistics extends DokuWiki_Action_Plugin {

    /**
     * return some info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/info.txt');
    }

    /**
     * register the eventhandlers and initialize some options
     */
    function register(&$controller){

        $controller->register_hook('TPL_METAHEADER_OUTPUT',
                                   'BEFORE',
                                   $this,
                                   'handle_metaheaders',
                                   array());
    }

    /**
     * Extend the meta headers
     */
    function handle_metaheaders(&$event, $param){
        global $ACT;
        global $ID;
        if($ACT != 'show') return; //only log page views for now

        $base = DOKU_BASE.'lib/plugins/statistics/log.php?rnd='.time();
        $view = $base.'&p='.rawurlencode($ID);

        // we create an image object and load the logger here, we also attach the same logger
        // to all external links - we won't use the JS dispatcher because we only want all this
        // on the 'show' action
        $data = "var plugin_statistics_image = new Image();
                 var plugin_statistics_uid   = DokuCookie.getValue('plgstats');
                 if(!plugin_statistics_uid){
                    plugin_statistics_uid = new Date().getTime()+'-'+Math.floor(Math.random()*32000);
                    DokuCookie.setValue('plgstats',plugin_statistics_uid);
                    if(!DokuCookie.getCookie(DokuCookie.name)){
                        plugin_statistics_uid = '';
                    }
                 }
                 plugin_statistics_image.src = '$view&r='+encodeURIComponent(document.referrer)+
                                                    '&sx='+screen.width+
                                                    '&sy='+screen.height+
                                                    '&vx='+window.innerWidth+
                                                    '&vy='+window.innerHeight+
                                                    '&uid='+plugin_statistics_uid+
                                                    '&js=1';
                addInitEvent(function(){
                    var links = getElementsByClass('urlextern',null,'a');
                    for(var i=0; i<links.length; i++){
                        addEvent(links[i],'click',function(){
                            var img = new Image();
                            img.src = '$base&ol='+encodeURIComponent(this.href);
                            return true;
                        });
                    }
                });
                ";
        $event->data['script'][] = array( 'type'=>'text/javascript', 'charset'=>'utf-8', '_data'=>$data);
    }

    /**
     * @fixme call this in the webbug call
     */
    function putpixel(){
        global $ID;
        $url = DOKU_BASE.'lib/plugins/statistics/log.php?p='.rawurlencode($ID).
               '&amp;r='.rawurlencode($_SERVER['HTTP_REFERER']).'&rnd='.time();

        echo '<noscript><img src="'.$url.'" width="1" height="1" /></noscript>';
    }
}

