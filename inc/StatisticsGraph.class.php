<?php

require dirname(__FILE__) . '/pchart/pData.php';
require dirname(__FILE__) . '/pchart/pChart.php';
require dirname(__FILE__) . '/pchart/GDCanvas.php';
require dirname(__FILE__) . '/pchart/PieChart.php';

class StatisticsGraph {
    private $hlp;
    private $tlimit;
    private $start;
    private $from;
    private $to;

    public function __construct(helper_plugin_statistics $hlp) {
        $this->hlp = $hlp;
    }

    public function render($call, $from, $to, $start) {
        $from = preg_replace('/[^\d\-]+/', '', $from);
        $to   = preg_replace('/[^\d\-]+/', '', $to);
        if(!$from) $from = date('Y-m-d');
        if(!$to) $to = date('Y-m-d');
        $this->tlimit = "A.dt >= '$from 00:00:00' AND A.dt <= '$to 23:59:59'";
        $this->start  = (int) $start;
        $this->from   = $from;
        $this->to     = $to;

        if(method_exists($this, $call)) {
            $this->$call();
        } else {
            $this->hlp->sendGIF();
        }
    }

    /**
     * Create a PieChart
     *
     * @param array $data associative array contianing label and values
     */
    protected function PieChart($data) {
        $DataSet = new pData;
        $Canvas  = new GDCanvas(400, 200, false);
        $Chart   = new PieChart(400, 200, $Canvas);
        $Chart->setFontProperties(dirname(__FILE__) . '/pchart/Fonts/DroidSans.ttf', 8);

        // Ensure data values are numeric
        $values = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, array_values($data));
        $keys = array_keys($data);
        
        // Ensure arrays have at least one element
        if(empty($values) || empty($keys)) {
            $values = array(0);
            $keys = array('No Data');
        }
        
        // Check if all values are zero
        $totalSum = array_sum($values);
        if($totalSum == 0 && count($values) > 1) {
            // If all values are zero, show a single "No Data" entry
            $values = array(1);  // Use 1 instead of 0 to avoid division by zero in chart rendering
            $keys = array('No Data');
        }
        
        $DataSet->AddPoints($values, 'Serie1');
        $DataSet->AddPoints($keys, 'Serie2');
        $DataSet->AddAllSeries();
        $DataSet->SetAbscissaLabelSeries("Serie2");

        // Final data validation before drawing pie chart
        $pieData = $DataSet->getData();
        if (is_array($pieData)) {
            foreach ($pieData as $serieKey => $serieData) {
                if (is_array($serieData)) {
                    $pieData[$serieKey] = array_map(function($v) {
                        return is_numeric($v) ? (float)$v : 0.0;
                    }, $serieData);
                }
            }
        }
        
        $Chart->drawBasicPieGraph(
            $pieData,
            $DataSet->GetDataDescription(),
            120, 100, 60, PIE_PERCENTAGE
        );
        $Chart->drawPieLegend(
            230, 15,
            $pieData,
            $DataSet->GetDataDescription(),
            new Color(250)
        );

        header('Content-Type: image/png');
        $Chart->Render('');
    }

    /**
     * Build a PieChart with only the top data shown and all other summarized
     *
     * @param string $query The function to call on the Query object to get the data
     * @param string $key The key containing the label
     * @param int $max How many discrete values to show before summarizing under "other"
     */
    protected function sumUpPieChart($query, $key, $max=4){
        $result = $this->hlp->Query()->$query($this->tlimit, $this->start, 0, false);
        $data   = array();
        $top    = 0;
        
        // Initialize 'other' key
        if (!isset($data['other'])) {
            $data['other'] = 0;
        }
        
        foreach($result as $row) {
            if($top < $max) {
                $keyValue = isset($row[$key]) ? $row[$key] : 'unknown';
                $cntValue = isset($row['cnt']) ? (is_numeric($row['cnt']) ? (int)$row['cnt'] : 0) : 0;
                $data[$keyValue] = $cntValue;
            } else {
                $cntValue = isset($row['cnt']) ? (is_numeric($row['cnt']) ? (int)$row['cnt'] : 0) : 0;
                if (!isset($data['other'])) {
                    $data['other'] = 0;
                }
                $data['other'] += $cntValue;
            }
            $top++;
        }
        
        // Remove empty 'other' category if it wasn't used
        if (isset($data['other']) && $data['other'] == 0 && count($data) > 1) {
            unset($data['other']);
        }
        
        // Ensure we have at least some data
        if (empty($data)) {
            $data = array('No Data' => 0);
        }
        $this->PieChart($data);
    }

    /**
     * Create a history graph for the given info type
     *
     * @param $info
     */
    protected function history($info) {
        $diff = abs(strtotime($this->from) - strtotime($this->to));
        $days = floor($diff / (60*60*24));
        if ($days > 365) {
            $interval= 'months';
        } elseif ($days > 56) {
            $interval = 'weeks';
        } else {
            $interval = 'days';
        }

        $result = $this->hlp->Query()->history($this->tlimit, $info, $interval);

        $data = array();
        $times = array();
        foreach($result as $row) {
            $cntValue = isset($row['cnt']) ? (is_numeric($row['cnt']) ? (int)$row['cnt'] : 0) : 0;
            $data[] = $cntValue;
            if($interval == 'months') {
                $time = isset($row['time']) ? $row['time'] : '';
                $times[] = substr($time, 0, 4) . '-' . substr($time, 4, 2);
            } elseif ($interval == 'weeks') {
                $year = isset($row['EXTRACT(YEAR FROM dt)']) ? $row['EXTRACT(YEAR FROM dt)'] : '';
                $time = isset($row['time']) ? $row['time'] : '';
                $times[] = $year . '-' . $time;
            }else {
                $time = isset($row['time']) ? $row['time'] : '';
                $times[] = substr($time, -5);
            }
        }

        // Ensure data contains only numeric values
        $data = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data);
        
        // Ensure arrays have at least one element
        if(empty($data) || empty($times)) {
            $data = array(0);
            $times = array('No Data');
        }

        $DataSet = new pData();
        $DataSet->AddPoints($data, 'Serie1');
        $DataSet->AddPoints($times, 'Times');
        $DataSet->AddAllSeries();
        $DataSet->SetAbscissaLabelSeries('Times');

        $DataSet->setXAxisName($this->hlp->getLang($interval));
        $DataSet->setYAxisName($this->hlp->getLang('graph_'.$info));

        $Canvas = new GDCanvas(600, 200, false);
        $Chart  = new pChart(600, 200, $Canvas);

        $Chart->setFontProperties(dirname(__FILE__) . '/pchart/Fonts/DroidSans.ttf', 8);
        $Chart->setGraphArea(70, 15, 580, 140);
        $Chart->drawScale(
            $DataSet, new ScaleStyle(SCALE_NORMAL, new Color(127)),
            45, 1, false, ceil(count($times) / 12)
        );
        // Final data validation before drawing
        $chartData = $DataSet->GetData();
        if (is_array($chartData)) {
            foreach ($chartData as $serieKey => $serieData) {
                if (is_array($serieData)) {
                    $chartData[$serieKey] = array_map(function($v) {
                        return is_numeric($v) ? (float)$v : 0.0;
                    }, $serieData);
                }
            }
        }
        $Chart->drawLineGraph($chartData, $DataSet->GetDataDescription());

        $DataSet->removeSeries('Times');
        $DataSet->removeSeriesName('Times');


        header('Content-Type: image/png');
        $Chart->Render('');
    }

    #region Graphbuilding functions

    public function countries() {
        $this->sumUpPieChart('countries', 'country');
    }

    public function searchengines() {
        $this->sumUpPieChart('searchengines', 'engine', 3);
    }

    public function browsers() {
        $this->sumUpPieChart('browsers', 'ua_info');
    }

    public function os() {
        $this->sumUpPieChart('os', 'os');
    }

    public function topuser() {
        $this->sumUpPieChart('topuser', 'user');
    }

    public function topeditor() {
        $this->sumUpPieChart('topeditor', 'user');
    }

    public function topgroup() {
        $this->sumUpPieChart('topgroup', 'group');
    }

    public function topgroupedit() {
        $this->sumUpPieChart('topgroupedit', 'group');
    }

    public function viewport() {
        $result = $this->hlp->Query()->viewport($this->tlimit, 0, 100);
        $data1  = array();
        $data2  = array();
        $data3  = array();

        foreach($result as $row) {
            $data1[] = isset($row['res_x']) ? (is_numeric($row['res_x']) ? (int)$row['res_x'] : 0) : 0;
            $data2[] = isset($row['res_y']) ? (is_numeric($row['res_y']) ? (int)$row['res_y'] : 0) : 0;
            $data3[] = isset($row['cnt']) ? (is_numeric($row['cnt']) ? (int)$row['cnt'] : 0) : 0;
        }

        // Ensure all data arrays contain only numeric values
        $data1 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data1);
        $data2 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data2);
        $data3 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data3);
        
        // Ensure all arrays have at least one element
        if(empty($data1) || empty($data2) || empty($data3)) {
            $data1 = array(0);
            $data2 = array(0);
            $data3 = array(0);
        }

        $DataSet = new pData;
        $DataSet->AddPoints($data1, 'Serie1');
        $DataSet->AddPoints($data2, 'Serie2');
        $DataSet->AddPoints($data3, 'Serie3');
        $DataSet->AddAllSeries();

        $Canvas = new GDCanvas(650, 490, false);
        $Chart  = new pChart(650, 490, $Canvas);

        $Chart->setFontProperties(dirname(__FILE__) . '/pchart/Fonts/DroidSans.ttf', 8);
        $Chart->setGraphArea(50, 30, 630, 470);
        $Chart->drawXYScale(
            $DataSet, new ScaleStyle(SCALE_NORMAL, new Color(127)),
            'Serie2', 'Serie1'
        );

        // Validate DataSet before XY plot
        $validatedDataSet = new pData;
        $originalData = $DataSet->GetData();
        if (is_array($originalData)) {
            foreach ($originalData as $serieKey => $serieData) {
                if (is_array($serieData)) {
                    $cleanData = array_map(function($v) {
                        return is_numeric($v) ? (float)$v : 0.0;
                    }, $serieData);
                    $validatedDataSet->AddPoints($cleanData, $serieKey);
                }
            }
            $validatedDataSet->AddAllSeries();
        } else {
            $validatedDataSet = $DataSet;
        }
        $Chart->drawXYPlotGraph($validatedDataSet, 'Serie2', 'Serie1', 0, 20, 2, null, false, 'Serie3');
        header('Content-Type: image/png');
        $Chart->Render('');
    }

    public function resolution() {
        $result = $this->hlp->Query()->resolution($this->tlimit, 0, 100);
        $data1  = array();
        $data2  = array();
        $data3  = array();

        foreach($result as $row) {
            $data1[] = isset($row['res_x']) ? (is_numeric($row['res_x']) ? (int)$row['res_x'] : 0) : 0;
            $data2[] = isset($row['res_y']) ? (is_numeric($row['res_y']) ? (int)$row['res_y'] : 0) : 0;
            $data3[] = isset($row['cnt']) ? (is_numeric($row['cnt']) ? (int)$row['cnt'] : 0) : 0;
        }

        // Ensure all data arrays contain only numeric values
        $data1 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data1);
        $data2 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data2);
        $data3 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data3);
        
        // Ensure all arrays have at least one element
        if(empty($data1) || empty($data2) || empty($data3)) {
            $data1 = array(0);
            $data2 = array(0);
            $data3 = array(0);
        }

        $DataSet = new pData;
        $DataSet->AddPoints($data1, 'Serie1');
        $DataSet->AddPoints($data2, 'Serie2');
        $DataSet->AddPoints($data3, 'Serie3');
        $DataSet->AddAllSeries();

        $Canvas = new GDCanvas(650, 490, false);
        $Chart  = new pChart(650, 490, $Canvas);

        $Chart->setFontProperties(dirname(__FILE__) . '/pchart/Fonts/DroidSans.ttf', 8);
        $Chart->setGraphArea(50, 30, 630, 470);
        $Chart->drawXYScale(
            $DataSet, new ScaleStyle(SCALE_NORMAL, new Color(127)),
            'Serie2', 'Serie1'
        );

        // Validate DataSet before XY plot
        $validatedDataSet = new pData;
        $originalData = $DataSet->GetData();
        if (is_array($originalData)) {
            foreach ($originalData as $serieKey => $serieData) {
                if (is_array($serieData)) {
                    $cleanData = array_map(function($v) {
                        return is_numeric($v) ? (float)$v : 0.0;
                    }, $serieData);
                    $validatedDataSet->AddPoints($cleanData, $serieKey);
                }
            }
            $validatedDataSet->AddAllSeries();
        } else {
            $validatedDataSet = $DataSet;
        }
        $Chart->drawXYPlotGraph($validatedDataSet, 'Serie2', 'Serie1', 0, 20, 2, null, false, 'Serie3');
        header('Content-Type: image/png');
        $Chart->Render('');
    }


    public function history_page_count() {
        $this->history('page_count');
    }

    public function history_page_size() {
        $this->history('page_size');
    }

    public function history_media_count() {
        $this->history('media_count');
    }

    public function history_media_size() {
        $this->history('media_size');
    }


    public function dashboardviews() {
        $hours  = ($this->from == $this->to);
        $result = $this->hlp->Query()->dashboardviews($this->tlimit, $hours);
        $data1  = array();
        $data2  = array();
        $data3  = array();
        $times  = array();

        foreach($result as $time => $row) {
            $data1[] = isset($row['pageviews']) ? (is_numeric($row['pageviews']) ? (int)$row['pageviews'] : 0) : 0;
            $data2[] = isset($row['sessions']) ? (is_numeric($row['sessions']) ? (int)$row['sessions'] : 0) : 0;
            $data3[] = isset($row['visitors']) ? (is_numeric($row['visitors']) ? (int)$row['visitors'] : 0) : 0;
            $times[] = $time . ($hours ? 'h' : '');
        }

        // Ensure all data arrays contain only numeric values
        $data1 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data1);
        $data2 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data2);
        $data3 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data3);
        
        // Ensure all arrays have the same length and at least one element
        if(empty($data1) || empty($data2) || empty($data3) || empty($times)) {
            $data1 = array(0);
            $data2 = array(0);
            $data3 = array(0);
            $times = array('No Data');
        }

        $DataSet = new pData();
        $DataSet->AddPoints($data1, 'Serie1');
        $DataSet->AddPoints($data2, 'Serie2');
        $DataSet->AddPoints($data3, 'Serie3');
        $DataSet->AddPoints($times, 'Times');
        $DataSet->AddAllSeries();
        $DataSet->SetAbscissaLabelSeries('Times');

        $DataSet->SetSeriesName($this->hlp->getLang('graph_views'), 'Serie1');
        $DataSet->SetSeriesName($this->hlp->getLang('graph_sessions'), 'Serie2');
        $DataSet->SetSeriesName($this->hlp->getLang('graph_visitors'), 'Serie3');

        $Canvas = new GDCanvas(700, 280, false);
        $Chart  = new pChart(700, 280, $Canvas);

        $Chart->setFontProperties(dirname(__FILE__) . '/pchart/Fonts/DroidSans.ttf', 8);
        $Chart->setGraphArea(50, 10, 680, 200);
        $Chart->drawScale(
            $DataSet, new ScaleStyle(SCALE_NORMAL, new Color(127)),
            ($hours ? 0 : 45), 1, false, ceil(count($times) / 12)
        );
        // Final data validation before drawing
        $chartData = $DataSet->GetData();
        if (is_array($chartData)) {
            foreach ($chartData as $serieKey => $serieData) {
                if (is_array($serieData)) {
                    $chartData[$serieKey] = array_map(function($v) {
                        return is_numeric($v) ? (float)$v : 0.0;
                    }, $serieData);
                }
            }
        }
        $Chart->drawLineGraph($chartData, $DataSet->GetDataDescription());

        $DataSet->removeSeries('Times');
        $DataSet->removeSeriesName('Times');
        $Chart->drawLegend(
            550, 15,
            $DataSet->GetDataDescription(),
            new Color(250)
        );

        header('Content-Type: image/png');
        $Chart->Render('');
    }

    public function dashboardwiki() {
        $hours  = ($this->from == $this->to);
        $result = $this->hlp->Query()->dashboardwiki($this->tlimit, $hours);
        $data1  = array();
        $data2  = array();
        $data3  = array();
        $times  = array();

        foreach($result as $time => $row) {
            $data1[] = isset($row['E']) ? (is_numeric($row['E']) ? (int)$row['E'] : 0) : 0;
            $data2[] = isset($row['C']) ? (is_numeric($row['C']) ? (int)$row['C'] : 0) : 0;
            $data3[] = isset($row['D']) ? (is_numeric($row['D']) ? (int)$row['D'] : 0) : 0;
            $times[] = $time . ($hours ? 'h' : '');
        }

        // Ensure all data arrays contain only numeric values
        $data1 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data1);
        $data2 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data2);
        $data3 = array_map(function($v) { return is_numeric($v) ? (int)$v : 0; }, $data3);
        
        // Ensure all arrays have the same length and at least one element
        if(empty($data1) || empty($data2) || empty($data3) || empty($times)) {
            $data1 = array(0);
            $data2 = array(0);
            $data3 = array(0);
            $times = array('No Data');
        }

        $DataSet = new pData();
        $DataSet->AddPoints($data1, 'Serie1');
        $DataSet->AddPoints($data2, 'Serie2');
        $DataSet->AddPoints($data3, 'Serie3');
        $DataSet->AddPoints($times, 'Times');
        $DataSet->AddAllSeries();
        $DataSet->SetAbscissaLabelSeries('Times');

        $DataSet->SetSeriesName($this->hlp->getLang('graph_edits'), 'Serie1');
        $DataSet->SetSeriesName($this->hlp->getLang('graph_creates'), 'Serie2');
        $DataSet->SetSeriesName($this->hlp->getLang('graph_deletions'), 'Serie3');

        $Canvas = new GDCanvas(700, 280, false);
        $Chart  = new pChart(700, 280, $Canvas);

        $Chart->setFontProperties(dirname(__FILE__) . '/pchart/Fonts/DroidSans.ttf', 8);
        $Chart->setGraphArea(50, 10, 680, 200);
        $Chart->drawScale(
            $DataSet, new ScaleStyle(SCALE_NORMAL, new Color(127)),
            ($hours ? 0 : 45), 1, false, ceil(count($times) / 12)
        );
        // Final data validation before drawing
        $chartData = $DataSet->GetData();
        if (is_array($chartData)) {
            foreach ($chartData as $serieKey => $serieData) {
                if (is_array($serieData)) {
                    $chartData[$serieKey] = array_map(function($v) {
                        return is_numeric($v) ? (float)$v : 0.0;
                    }, $serieData);
                }
            }
        }
        $Chart->drawLineGraph($chartData, $DataSet->GetDataDescription());

        $DataSet->removeSeries('Times');
        $DataSet->removeSeriesName('Times');
        $Chart->drawLegend(
            550, 15,
            $DataSet->GetDataDescription(),
            new Color(250)
        );

        header('Content-Type: image/png');
        $Chart->Render('');
    }

    #endregion Graphbuilding functions
}
