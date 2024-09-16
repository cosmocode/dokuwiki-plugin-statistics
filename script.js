/**
 * Statistics script
 */
var plugin_statistics = {
    data: {},

    /**
     * initialize the script
     */
    init: async function () {

        // load visitor cookie
        var now = new Date();
        var uid = DokuCookie.getValue('plgstats');
        if (!uid) {
            uid = now.getTime() + '-' + Math.floor(Math.random() * 32000);
            DokuCookie.setValue('plgstats', uid);
        }

        plugin_statistics.data = {
            uid: uid,
            ses: plugin_statistics.get_session(),
            p: JSINFO['id'],
            r: document.referrer,
            sx: screen.width,
            sy: screen.height,
            vx: window.innerWidth,
            vy: window.innerHeight,
            js: 1,
            rnd: now.getTime()
        };

        try {
            await navigator.userAgentData.getHighEntropyValues(["platformVersion"])
                .then(ua => {
                    if (navigator.userAgentData.platform === "Windows") {
                        const majorPlatformVersion = parseInt(ua.platformVersion.split('.')[0]);
                        if (majorPlatformVersion >= 13) {
                            plugin_statistics.data.os = "Win11";
                        }
                    }
                });
        } catch(error) {
            // console.log(error);
        }

        // log access
        if (JSINFO['act'] == 'show') {
            plugin_statistics.log_view('v');
        } else {
            plugin_statistics.log_view('s');
        }

        // attach outgoing event
        jQuery('a.urlextern').click(plugin_statistics.log_external);

        // attach unload event
        jQuery(window).bind('beforeunload', plugin_statistics.log_exit);

        jQuery('.plg_stats_timeselect .datepicker').datepicker({dateFormat: 'yy-mm-dd'});
    },

    /**
     * Log a view or session
     *
     * @param {string} act 'v' = view, 's' = session
     */
    log_view: function (act) {
        var params = jQuery.param(plugin_statistics.data);
        var img = new Image();
        img.src = DOKU_BASE + 'lib/plugins/statistics/log.php?do=' + act + '&' + params;
    },

    /**
     * Log clicks to external URLs
     */
    log_external: function () {
        var params = jQuery.param(plugin_statistics.data);
        var img = new Image();
        img.src = DOKU_BASE + 'lib/plugins/statistics/log.php?do=o&ol=' + encodeURIComponent(this.href) + '&' + params;
        plugin_statistics.pause(500);
        return true;
    },

    /**
     * Log any leaving action as session info
     */
    log_exit: function () {
        var params = jQuery.param(plugin_statistics.data);

        var ses = plugin_statistics.get_session();
        if(ses != params.ses) return; // session expired a while ago, don't log this anymore

        var url = DOKU_BASE + 'lib/plugins/statistics/log.php?do=s&' + params;
        jQuery.ajax(url, {async: false});
    },

    /**
     * get current session identifier
     *
     * Auto clears an expired session and creates a new one after 15 min idle time
     *
     * @returns {string}
     */
    get_session: function () {
        var now = new Date();

        // load session cookie
        var ses = DokuCookie.getValue('plgstatsses');
        if (ses) {
            ses = ses.split('-');
            var time = ses[0];
            ses = ses[1];
            if (now.getTime() - time > 15 * 60 * 1000) {
                ses = ''; // session expired
            }
        }
        // assign new session
        if (!ses) {
            //http://stackoverflow.com/a/16693578/172068
            ses = (Math.random().toString(16) + "000000000").substr(2, 8) +
                (Math.random().toString(16) + "000000000").substr(2, 8) +
                (Math.random().toString(16) + "000000000").substr(2, 8) +
                (Math.random().toString(16) + "000000000").substr(2, 8);
        }
        // update session info
        DokuCookie.setValue('plgstatsses', now.getTime() + '-' + ses);

        return ses;
    },


    /**
     * Pause the script execution for the given time
     *
     * @param {int} ms
     */
    pause: function (ms) {
        var now = new Date();
        var exitTime = now.getTime() + ms;
        while (true) {
            now = new Date();
            if (now.getTime() > exitTime) {
                return;
            }
        }
    }
};


jQuery(plugin_statistics.init);
