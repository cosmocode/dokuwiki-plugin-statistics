<?php
/**
 * 繁體中文語言檔
 @author Jonathan Tsai <tryweb@ichiayi.com>
 */

// 用於管理插件，顯示在管理選單中的提示
$lang['menu'] = '存取與使用統計';

$lang['more'] = '更多';
$lang['prev'] = '上一頁';
$lang['next'] = '下一頁';

// 時間選擇
$lang['time_select'] = '選擇時間範圍：';
$lang['time_today']  = '今天';
$lang['time_last1']  = '昨天';
$lang['time_last7']  = '最近7天';
$lang['time_last30'] = '最近30天';
$lang['time_go']     = '確定';
$lang['days']                  = '天';
$lang['weeks']                 = '週';
$lang['months']                = '月';

// 不同頁面
$lang['dashboard']             = '儀表板';
$lang['page']                  = '頁面';
$lang['edits']                 = '編輯';
$lang['images']                = '圖片';
$lang['downloads']             = '下載';
$lang['referer']               = '來源連結';
$lang['newreferer']            = '新增來源連結';
$lang['outlinks']              = '外部連結';
$lang['searchphrases']         = '外部搜尋詞組';
$lang['searchwords']           = '外部搜尋關鍵字';
$lang['internalsearchphrases'] = '內部搜尋詞組';
$lang['internalsearchwords']   = '內部搜尋關鍵字';
$lang['searchengines']         = '搜尋引擎';
$lang['browsers']              = '瀏覽器';
$lang['os']                    = '作業系統';
$lang['countries']             = '國家';
$lang['resolution']            = '螢幕大小';
$lang['viewport']              = '瀏覽器視窗';
$lang['seenusers']             = '活躍用戶';
$lang['history']               = '成長歷史';
$lang['topuser']               = '熱門用戶';
$lang['topeditor']             = '熱門編輯者';
$lang['topgroup']              = '熱門群組';
$lang['topgroupedit']          = '熱門編輯群組';
$lang['content']               = '（內容）';
$lang['users']                 = '（用戶和群組）';
$lang['links']                 = '（連結）';
$lang['search']                = '（搜尋）';
$lang['technology']            = '（技術）';
$lang['trafficsum']            = '<strong>%s</strong> 個請求產生了 <strong>%s</strong> 的流量。';


// 介紹文字
$lang['intro_dashboard']             = '此頁面為您提供在所選時間範圍內，您的維基發生了什麼的快速概覽。<br />欲獲得詳細資訊和圖表，請從目錄中選擇一個主題。';
$lang['intro_page']                  = '以下是在所選時間範圍內最常被瀏覽的維基頁面 — 您的熱門內容。';
$lang['intro_edits']                 = '以下是在所選時間範圍內最常被編輯的維基頁面 — 這是當前活動發生的地方。';
$lang['intro_images']                = '以下是您維基中最常顯示的本地圖片。第三列顯示了每個項目傳輸的總位元組數。';
$lang['intro_downloads']             = '以下是您維基中最常被下載的本地媒體項目。第三列顯示了每個項目傳輸的總位元組數。';
$lang['intro_referer']               = '在所有 <strong>%d</strong> 次外部訪問中，<strong>%d</strong> 次（<strong>%.1f%%</strong>）是直接（或來自書籤）訪問，<strong>%d</strong> 次（<strong>%.1f%%</strong>）來自搜尋引擎，<strong>%d</strong> 次（<strong>%.1f%%</strong>）是通過其他頁面的連結而來。<br />這些其他頁面列在下方。';

$lang['intro_newreferer']            = '以下的來源連結在所選時間範圍內首次被記錄，且之前從未見過。';
$lang['intro_outlinks']              = '以下是您維基中最常被點擊的外部網站連結。';
$lang['intro_searchengines']         = '以下是用戶用來尋找您維基的搜尋引擎。';
$lang['intro_searchphrases']         = '以下是人們在找到您的維基時使用的確切搜尋詞組。';
$lang['intro_searchwords']           = '以下是人們在找到您的維基時最常使用的搜尋詞。';
$lang['intro_internalsearchphrases'] = '以下是人們在您的維基內部搜尋時使用的確切詞組。';
$lang['intro_internalsearchwords']   = '以下是人們在您的維基內部搜尋時最常使用的詞語。';
$lang['intro_browsers']              = '以下是您的用戶最常使用的瀏覽器。';
$lang['intro_os']                    = '以下是您的用戶最常使用的平台。';
$lang['intro_countries']             = '以下是您的用戶來源國家。請注意，將IP地址解析為國家並非完全精確的科學方法。';
$lang['intro_resolution']            = '此頁面提供有關您用戶的螢幕大小（解析度）的一些資訊。這是他們擁有的螢幕空間，而非瀏覽器顯示區域可用的空間。有關後者，請參閱瀏覽器視窗頁面。所有值都四捨五入到100像素，圖表僅顯示前100個值。';
$lang['intro_viewport']              = '以下是您的用戶瀏覽器用於渲染您維基的區域大小。所有值都四捨五入到100像素，圖表僅顯示前100個值。';
$lang['intro_seenusers']             = '這是用戶最後在維基中出現的時間列表，按最後出現日期排序。這與所選時間範圍無關。';
$lang['intro_history']               = '這些圖表讓您了解在指定時間範圍內，您的維基在條目數量和大小方面的成長情況。請注意，此圖表需要至少數天的時間範圍。';
$lang['intro_topuser']               = '此頁面顯示在所選時間範圍內，哪些已登入的用戶瀏覽了最多的維基頁面。';
$lang['intro_topeditor']             = '此頁面顯示在所選時間範圍內，哪些已登入的用戶進行了最多的編輯。';
$lang['intro_topgroup']              = '以下是在所選時間範圍內，瀏覽最多維基頁面的已登入用戶所屬的群組。請注意，當一個用戶屬於多個群組時，其所有群組都會被計算。';
$lang['intro_topgroupedit']          = '以下是在所選時間範圍內，進行最多編輯的已登入用戶所屬的群組。請注意，當一個用戶屬於多個群組時，其所有群組都會被計算。';

// 儀表板項目
$lang['dash_pageviews']     = '<strong>%d</strong> 次頁面瀏覽';
$lang['dash_sessions']      = '<strong>%d</strong> 次訪問（會話）';
$lang['dash_visitors']      = '<strong>%d</strong> 位獨立訪客';
$lang['dash_users']         = '<strong>%d</strong> 位已登入用戶';
$lang['dash_logins']        = '<strong>%d</strong> 次用戶登入';
$lang['dash_registrations'] = '<strong>%s</strong> 位新註冊用戶';
$lang['dash_current']       = '<strong>%d</strong> 位當前已登入用戶';
$lang['dash_bouncerate']    = '<strong>%.1f%%</strong> 跳出率';
$lang['dash_timespent']     = '平均每次會話停留 <strong>%.2f</strong> 分鐘';
$lang['dash_avgpages']      = '平均每次會話瀏覽 <strong>%.2f</strong> 頁';
$lang['dash_newvisitors']   = '<strong>%.1f%%</strong> 新訪客';

$lang['dash_mostpopular'] = '最受歡迎的頁面';
$lang['dash_newincoming'] = '熱門新增來源連結';
$lang['dash_topsearch']   = '熱門搜尋詞組';

// 圖表標籤
$lang['graph_edits']       = 'Page Edits';
$lang['graph_creates']     = 'Page Creations';
$lang['graph_deletions']   = 'Page Deletions';
$lang['graph_views']       = 'Page Views';
$lang['graph_sessions']    = 'Visits';
$lang['graph_visitors']    = 'Visitors';
$lang['graph_page_count']  = 'Pages';
$lang['graph_page_size']   = 'Pagessize (MB)';
$lang['graph_media_count'] = 'Media Items';
$lang['graph_media_size']  = 'Media Item Size (MB)';
