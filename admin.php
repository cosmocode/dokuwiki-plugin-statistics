<?php
/**
 * statistics plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@splitbrain.org>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();


/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_statistics extends DokuWiki_Admin_Plugin {
    public    $dblink = null;
    protected $opt    = '';
    protected $from   = '';
    protected $to     = '';
    protected $start  = '';
    protected $tlimit = '';

    /**
     * Available statistic pages
     */
    protected $pages  = array('dashboard','page','referer','newreferer',
                              'outlinks','searchengines','searchphrases',
                              'searchwords', 'internalsearchphrases',
                              'internalsearchwords','browsers','os',
                              'countries','resolution');

    /**
     * Initialize the helper
     */
    public function __construct() {
        $this->hlp = plugin_load('helper','statistics');
    }

    /**
     * Access for managers allowed
     */
    public function forAdminOnly(){
        return false;
    }

    /**
     * return sort order for position in admin menu
     */
    public function getMenuSort() {
        return 350;
    }

    /**
     * handle user request
     */
    public function handle() {
        $this->opt = preg_replace('/[^a-z]+/','',$_REQUEST['opt']);
        if(!in_array($this->opt,$this->pages)) $this->opt = 'dashboard';

        $this->start = (int) $_REQUEST['s'];
        $this->setTimeframe($_REQUEST['f'],$_REQUEST['t']);
    }

    /**
     * set limit clause
     */
    public function setTimeframe($from,$to){
        // fixme add better sanity checking here:
        $from = preg_replace('/[^\d\-]+/','',$from);
        $to   = preg_replace('/[^\d\-]+/','',$to);
        if(!$from) $from = date('Y-m-d');
        if(!$to)   $to   = date('Y-m-d');

        //setup limit clause
        $tlimit = "A.dt >= '$from 00:00:00' AND A.dt <= '$to 23:59:59'";
        $this->tlimit = $tlimit;
        $this->from   = $from;
        $this->to     = $to;
    }

    /**
     * Output the Statistics
     */
    function html() {
        echo '<h1>Access Statistics</h1>';
        $this->html_timeselect();

        $method = 'html_'.$this->opt;
        if(method_exists($this,$method)){
            echo '<div class="plg_stats_'.$this->opt.'">';
            echo '<h2>'.$this->getLang($this->opt).'</h2>';
            $this->$method();
            echo '</div>';
        }
    }

    /**
     * Return the TOC
     *
     * @return array
     */
    function getTOC(){
        $toc = array();
        foreach($this->pages as $page){
            $toc[] = array(
                    'link'  => '?do=admin&amp;page=statistics&amp;opt='.$page.'&amp;f='.$this->from.'&amp;t='.$this->to,
                    'title' => $this->getLang($page),
                    'level' => 1,
                    'type'  => 'ul'
            );
        }
        return $toc;
    }

    /**
     * Outputs pagination links
     *
     * @fixme does this still work?
     *
     * @param type $limit
     * @param type $next
     */
    function html_pager($limit,$next){
        echo '<div class="plg_stats_pager">';

        if($this->start > 0){
            $go = max($this->start - $limit, 0);
            echo '<a href="?do=admin&amp;page=statistics&amp;opt='.$this->opt.'&amp;f='.$this->from.'&amp;t='.$this->to.'&amp;s='.$go.'" class="prev">previous page</a>';
        }

        if($next){
            $go = $this->start + $limit;
            echo '<a href="?do=admin&amp;page=statistics&amp;opt='.$this->opt.'&amp;f='.$this->from.'&amp;t='.$this->to.'&amp;s='.$go.'" class="next">next page</a>';
        }
        echo '</div>';
    }

    /**
     * Print the time selection menu
     */
    function html_timeselect(){
        $now   = date('Y-m-d');
        $yday  = date('Y-m-d',time()-(60*60*24));
        $week  = date('Y-m-d',time()-(60*60*24*7));
        $month = date('Y-m-d',time()-(60*60*24*30));

        echo '<div class="plg_stats_timeselect">';
        echo '<span>Select the timeframe:</span>';
        echo '<ul>';

        echo '<li>';
        echo '<a href="?do=admin&amp;page=statistics&amp;opt='.$this->opt.'&amp;f='.$now.'&amp;t='.$now.'">';
        echo 'today';
        echo '</a>';
        echo '</li>';

        echo '<li>';
        echo '<a href="?do=admin&amp;page=statistics&amp;opt='.$this->opt.'&amp;f='.$yday.'&amp;t='.$yday.'">';
        echo 'yesterday';
        echo '</a>';
        echo '</li>';

        echo '<li>';
        echo '<a href="?do=admin&amp;page=statistics&amp;opt='.$this->opt.'&amp;f='.$week.'&amp;t='.$now.'">';
        echo 'last 7 days';
        echo '</a>';
        echo '</li>';

        echo '<li>';
        echo '<a href="?do=admin&amp;page=statistics&amp;opt='.$this->opt.'&amp;f='.$month.'&amp;t='.$now.'">';
        echo 'last 30 days';
        echo '</a>';
        echo '</li>';

        echo '</ul>';


        echo '<form action="" method="get">';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="statistics" />';
        echo '<input type="hidden" name="opt" value="'.$this->opt.'" />';
        echo '<input type="text" name="f" value="'.$this->from.'" class="edit" />';
        echo '<input type="text" name="t" value="'.$this->to.'" class="edit" />';
        echo '<input type="submit" value="go" class="button" />';
        echo '</form>';

        echo '</div>';
    }


    /**
     * Print an introductionary screen
     */
    function html_dashboard(){
        echo '<p>This page gives you a quick overview on what is happening in your Wiki. For detailed lists
              choose a topic from the list.</p>';

        // general info
        echo '<div class="plg_stats_top">';
        $result = $this->hlp->Query()->aggregate($this->tlimit);
        echo '<ul>';
        echo '<li><span>'.$result['pageviews'].'</span> page views </li>';
        echo '<li><span>'.$result['sessions'].'</span> visits (sessions) </li>';
        echo '<li><span>'.$result['visitors'].'</span> unique visitors </li>';
        echo '<li><span>'.$result['users'].'</span> logged in users</li>';

        echo '</ul>';
        echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/img.php?img=trend&amp;f='.$this->from.'&amp;t='.$this->to.'" />';
        echo '</div>';


        // top pages today
        echo '<div>';
        echo '<h2>Most popular pages</h2>';
        $result = $this->hlp->Query()->pages($this->tlimit,$this->start,15);
        $this->html_resulttable($result);
        echo '<a href="?do=admin&amp;page=statistics&amp;opt=page&amp;f='.$this->from.'&amp;t='.$this->to.'" class="more">more</a>';
        echo '</div>';

        // top referer today
        echo '<div>';
        echo '<h2>Newest incoming links</h2>';
        $result = $this->hlp->Query()->newreferer($this->tlimit,$this->start,15);
        $this->html_resulttable($result);
        echo '<a href="?do=admin&amp;page=statistics&amp;opt=newreferer&amp;f='.$this->from.'&amp;t='.$this->to.'" class="more">more</a>';
        echo '</div>';

        // top searches today
        echo '<div>';
        echo '<h2>Top search phrases</h2>';
        $result = $this->hlp->Query()->searchphrases($this->tlimit,$this->start,15);
        $this->html_resulttable($result);
        echo '<a href="?do=admin&amp;page=statistics&amp;opt=searchphrases&amp;f='.$this->from.'&amp;t='.$this->to.'" class="more">more</a>';
        echo '</div>';
    }

    function html_countries(){
        echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/img.php?img=countries&amp;f='.$this->from.'&amp;t='.$this->to.'" />';
        $result = $this->hlp->Query()->countries($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_page(){
        $result = $this->hlp->Query()->pages($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_browsers(){
        echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/img.php?img=browsers&amp;f='.$this->from.'&amp;t='.$this->to.'" />';
        $result = $this->hlp->Query()->browsers($this->tlimit,$this->start,150,true);
        $this->html_resulttable($result,'',150);
    }

    function html_os(){
        $result = $this->hlp->Query()->os($this->tlimit,$this->start,150,true);
        $this->html_resulttable($result,'',150);
    }

    function html_referer(){
        $result = $this->hlp->Query()->aggregate($this->tlimit);

        $all    = $result['search']+$result['external']+$result['direct'];

        if($all){
            printf("<p>Of all %d external visits, %d (%.1f%%) were bookmarked (direct) accesses,
                    %d (%.1f%%) came from search engines and %d (%.1f%%) were referred through
                    links from other pages.</p>",$all,$result['direct'],(100*$result['direct']/$all),
                    $result['search'],(100*$result['search']/$all),$result['external'],
                    (100*$result['external']/$all));
        }

        $result = $this->hlp->Query()->referer($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_newreferer(){
        echo '<p>The following incoming links where first logged in the selected time frame,
              and have never been seen before.</p>';

        $result = $this->hlp->Query()->newreferer($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_outlinks(){
        $result = $this->hlp->Query()->outlinks($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_searchphrases(){
        $result = $this->hlp->Query()->searchphrases(true,$this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_searchwords(){
        $result = $this->hlp->Query()->searchwords(true,$this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_internalsearchphrases(){
        $result = $this->hlp->Query()->searchphrases(false,$this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_internalsearchwords(){
        $result = $this->hlp->Query()->searchwords(false,$this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }

    function html_searchengines(){
        $result = $this->hlp->Query()->searchengines($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);
    }


    function html_resolution(){
        $result = $this->hlp->Query()->resolution($this->tlimit,$this->start,150);
        $this->html_resulttable($result,'',150);

        echo '<p>While the data above gives you some info about the resolution your visitors use, it does not tell you
              much about about the real size of their browser windows. The graphic below shows the size distribution of
              the view port (document area) of your visitor\'s browsers. Please note that this data can not be logged
              in all browsers. Because users may resize their browser window while browsing your site the statistics may
              be flawed. Take it with a grain of salt.</p>';

        echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/img.php?img=view&amp;f='.$this->from.'&amp;t='.$this->to.'" />';
    }


    /**
     * Display a result in a HTML table
     */
    function html_resulttable($result,$header='',$pager=0){
        echo '<table>';
        if(is_array($header)){
            echo '<tr>';
            foreach($header as $h){
                echo '<th>'.hsc($h).'</th>';
            }
            echo '</tr>';
        }

        $count = 0;
        if(is_array($result)) foreach($result as $row){
            echo '<tr>';
            foreach($row as $k => $v){
                echo '<td class="plg_stats_X'.$k.'">';
                if($k == 'page'){
                    echo '<a href="'.wl($v).'" class="wikilink1">';
                    echo hsc($v);
                    echo '</a>';
                }elseif($k == 'url'){
                    $url = hsc($v);
                    $url = preg_replace('/^https?:\/\/(www\.)?/','',$url);
                    if(strlen($url) > 45){
                        $url = substr($url,0,30).' &hellip; '.substr($url,-15);
                    }
                    echo '<a href="'.$v.'" class="urlextern">';
                    echo $url;
                    echo '</a>';
                }elseif($k == 'ilookup'){
                    echo '<a href="'.wl('',array('id'=>$v,'do'=>'search')).'">Search</a>';
                }elseif($k == 'lookup'){
                    echo '<a href="http://www.google.com/search?q='.rawurlencode($v).'">';
                    echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/ico/search/google.png" alt="lookup in Google" border="0" />';
                    echo '</a> ';

                    echo '<a href="http://search.yahoo.com/search?p='.rawurlencode($v).'">';
                    echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/ico/search/yahoo.png" alt="lookup in Yahoo" border="0" />';
                    echo '</a> ';

                    echo '<a href="http://search.msn.com/results.aspx?q='.rawurlencode($v).'">';
                    echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/ico/search/msn.png" alt="lookup in MSN Live" border="0" />';
                    echo '</a> ';

                }elseif($k == 'engine'){
                    include_once(dirname(__FILE__).'/inc/search_engines.php');
                    echo $SearchEnginesHashLib[$v];
                }elseif($k == 'bflag'){
                    echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/ico/browser/'.strtolower(preg_replace('/[^\w]+/','',$v)).'.png" alt="'.hsc($v).'" />';
                }elseif($k == 'osflag'){
                    echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/ico/os/'.strtolower(preg_replace('/[^\w]+/','',$v)).'.png" alt="'.hsc($v).'" />';
                }elseif($k == 'cflag'){
                    echo '<img src="'.DOKU_BASE.'lib/plugins/statistics/ico/flags/'.hsc($v).'.png" alt="'.hsc($v).'" width="18" height="12" />';
                }elseif($k == 'html'){
                    echo $v;
                }else{
                    echo hsc($v);
                }
                echo '</td>';
            }
            echo '</tr>';

            if($pager && ($count == $pager)) break;
            $count++;
        }
        echo '</table>';

        if($pager) $this->html_pager($pager,count($result) > $pager);
    }

}
