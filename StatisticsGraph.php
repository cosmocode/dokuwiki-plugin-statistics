<?php

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
namespace dokuwiki\plugin\statistics;

/**
 * Create the data for graph visualization
 */
class StatisticsGraph
{
    private readonly \helper_plugin_statistics $hlp;
    private readonly string $from;
    private readonly string $to;
    private readonly int $width;
    private readonly int $height;

    /**
     * Initialize a new Graph
     *
     * @param \helper_plugin_statistics $hlp
     * @param string $from From date
     * @param string $to To date
     * @param int $width width of the graph in pixels
     * @param int $height height of the graph in pixels
     */
    public function __construct(\helper_plugin_statistics $hlp, $from, $to, $width, $height)
    {
        $this->hlp = $hlp;
        $this->from = $from;
        $this->to = $to;
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * Create a PieChart
     *
     * @param array $data associative array contianing label and values
     */
    protected function pieChart($data)
    {
        $data = [
            'datasets' => [
                [
                    'data' => array_values($data),
                ],
            ],
            'labels' => array_keys($data)

        ];

        $this->printGraph('countries', 'pie', $data);
    }

    /**
     * Build a PieChart with only the top data shown and all other summarized
     *
     * @param string $query The function to call on the Query object to get the data
     * @param ?string $key The key containing the label, or null for the first non-cnt key
     * @param int $max How many discrete values to show before summarizing under "other"
     */
    public function sumUpPieChart($query, $key = null, $max = 4)
    {
        $result = $this->hlp->getQuery()->$query();
        if (!$result) return;

        if ($key === null) {
            $keys = array_keys($result[0]);
            $key = array_shift($keys);
            if ($key === 'cnt') {
                $key = array_shift($keys);
            }
        }

        $data   = [];
        $top    = 0;
        foreach ($result as $row) {
            if ($top < $max) {
                $data[$row[$key]] = $row['cnt'];
            } else {
                $data['other'] += $row['cnt'];
            }
            $top++;
        }
        $this->pieChart($data);
    }

    /**
     * Create a history graph for the given info type
     *
     * @param $info
     */
    protected function history($info)
    {
        $diff = abs(strtotime($this->from) - strtotime($this->to));
        $days = floor($diff / (60 * 60 * 24));
        if ($days > 365) {
            $interval = 'months';
        } elseif ($days > 56) {
            $interval = 'weeks';
        } else {
            $interval = 'days';
        }

        $result = $this->hlp->getQuery()->history($info, $interval);

        $data = [];
        $times = [];
        foreach ($result as $row) {
            $data[] = $row['cnt'];
            if ($interval == 'months') {
                $times[] = substr($row['time'], 0, 4) . '-' . substr($row['time'], 4, 2);
            } elseif ($interval == 'weeks') {
                $times[] = $row['EXTRACT(YEAR FROM dt)'] . '-' . $row['time']; // FIXME
            } else {
                $times[] = substr($row['time'], -5); // FIXME
            }
        }

        $data = [
            'datasets' => [
                [
                    'label' => $this->hlp->getLang('graph_' . $info),
                    'data' => $data,
                ],
            ],
            'labels' => $times
        ];

        $this->printGraph("history_$info", 'line', $data);
    }
    #region Graphbuilding functions

    public function countries()
    {
        $this->sumUpPieChart('countries', 'country');
    }

    public function searchengines()
    {
        $this->sumUpPieChart('searchengines', 'engine', 3);
    }

    public function browsers()
    {
        $this->sumUpPieChart('browsers', 'browser');
    }

    public function os()
    {
        $this->sumUpPieChart('os', 'os');
    }

    public function topdomain()
    {
        $this->sumUpPieChart('topdomain', 'domain');
    }

    public function topuser()
    {
        $this->sumUpPieChart('topuser', 'user');
    }

    public function topeditor()
    {
        $this->sumUpPieChart('topeditor', 'user');
    }

    public function topgroup()
    {
        $this->sumUpPieChart('topgroup', 'group');
    }

    public function topgroupedit()
    {
        $this->sumUpPieChart('topgroupedit', 'group');
    }

    public function viewport()
    {
        $result = $this->hlp->getQuery()->viewport();
        $data = [];

        foreach ($result as $row) {
            $data[] = [
                'x' => $row['res_x'],
                'y' => $row['res_y'],
                'r' => floor($row['cnt'] / 10),
            ];
        }

        $data = [
            'datasets' => [
                [
                    'label' => $this->hlp->getLang('viewport'),
                    'data' => $data
                ]
            ],
        ];

        $this->printGraph('viewport', 'bubble', $data);
    }

    public function resolution()
    {
        $result = $this->hlp->getQuery()->resolution();
        $data = [];

        foreach ($result as $row) {
            $data[] = [
                'x' => $row['res_x'],
                'y' => $row['res_y'],
                'r' => floor($row['cnt'] / 10),
            ];
        }

        $data = [
            'datasets' => [
                [
                    'label' => $this->hlp->getLang('resolution'),
                    'data' => $data
                ]
            ],
        ];

        $this->printGraph('resolution', 'bubble', $data);
    }


    public function history_page_count()
    {
        $this->history('page_count');
    }

    public function history_page_size()
    {
        $this->history('page_size');
    }

    public function history_media_count()
    {
        $this->history('media_count');
    }

    public function history_media_size()
    {
        $this->history('media_size');
    }

    public function dashboardviews()
    {
        $hours  = ($this->from == $this->to);
        $result = $this->hlp->getQuery()->dashboardviews($hours);
        $data1  = [];
        $data2  = [];
        $data3  = [];
        $times  = [];

        foreach ($result as $time => $row) {
            $data1[] = (int) $row['pageviews'];
            $data2[] = (int) $row['sessions'];
            $data3[] = (int) $row['visitors'];
            $times[] = $time . ($hours ? 'h' : '');
        }

        $data = [
            'datasets' => [
                [
                    'label' => $this->hlp->getLang('graph_views'),
                    'data' => $data1,
                ],
                [
                    'label' => $this->hlp->getLang('graph_sessions'),
                    'data' => $data2,
                ],
                [
                    'label' => $this->hlp->getLang('graph_visitors'),
                    'data' => $data3,
                ],
            ],
            'labels' => $times
        ];

        $this->printGraph('dashboardviews', 'line', $data);
    }

    public function dashboardwiki($js = false)
    {
        $hours  = ($this->from == $this->to);
        $result = $this->hlp->getQuery()->dashboardwiki($hours);
        $data1  = [];
        $data2  = [];
        $data3  = [];
        $times  = [];

        foreach ($result as $time => $row) {
            $data1[] = (int) ($row['E'] ?? 0);
            $data2[] = (int) ($row['C'] ?? 0);
            $data3[] = (int) ($row['D'] ?? 0);
            $times[] = $time . ($hours ? 'h' : '');
        }
        $data = [
            'datasets' => [
                [
                    'label' => $this->hlp->getLang('graph_edits'),
                    'data' => $data1,
                ],
                [
                    'label' => $this->hlp->getLang('graph_creates'),
                    'data' => $data2,
                ],
                [
                    'label' => $this->hlp->getLang('graph_deletions'),
                    'data' => $data3,
                ],
            ],
            'labels' => $times
        ];

        $this->printGraph('dashboardwiki', 'line', $data);
    }

    /**
     * @param string $name
     * @param string $type
     * @param array $data
     * @return void
     */
    protected function printGraph(string $name, string $type, array $data)
    {
        $json = htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');
        $tpl = '
        <chart-component
            width="%d"
            height="%d"
            name="%s"
            type="%s"
            data="%s"></chart-component>
        ';

        echo sprintf($tpl, $this->width, $this->height, $name, $type, $json);
    }

    #endregion Graphbuilding functions
}
