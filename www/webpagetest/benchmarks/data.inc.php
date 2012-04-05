<?php

require_once('common.inc');
$raw_data = null;
$trend_data = null;

/**
* Get a list of the series to display
* 
*/
function GetSeriesLabels($benchmark) {
    $series = null;
    $info = GetBenchmarkInfo($benchmark);
    if ($info && is_array($info)) {
        $loc = null;
        if ($info['expand'] && count($info['locations'] > 1)) {
            foreach ($info['locations'] as $location => $label) {
                $loc = $location;
                break;
            }
        }
        
        if (GetConfigurationNames($benchmark, $configurations, $loc, $loc_aliases)) {
            $series = array();
            foreach($configurations as &$configuration) {
                if (array_key_exists('title', $configuration) && strlen($configuration['title']))
                    $title = $configuration['title'];
                else
                    $title = $configuration['name'];
                if (count($configuration['locations']) > 1) {
                    $name = "$title ";
                    if (count($configurations) == 1)
                        $name = '';
                    foreach ($configuration['locations'] as &$location) {
                        if (is_numeric($location['label'])) {
                            $series[] = array('name' => "$name{$location['location']}", 'configuration' => $configuration['name'], 'location' => $location['location']);
                        } else {
                            $series[] = array('name' => "$name{$location['label']}", 'configuration' => $configuration['name'], 'location' => $location['location']);
                        }
                    }
                } else {
                    $series[] = array('name' => $title, 'configuration' => $configuration['name'], 'location' => '');
                }
            }
        }
    }
    return $series;
}


/*
    Helper functions to deal with aggregate benchmark data
*/
function LoadDataTSV($benchmark, $cached, $metric, $aggregate, $loc, &$annotations) {
    $tsv = null;
    $isbytes = false;
    $istime = false;
    $annotations = array();
    if (stripos($metric, 'bytes') !== false) {
        $isbytes = true;
    } elseif (stripos($metric, 'time') !== false || 
            stripos($metric, 'render') !== false || 
            stripos($metric, 'fullyloaded') !== false || 
            stripos($metric, 'visualcomplete') !== false || 
            stripos($metric, 'eventstart') !== false || 
            stripos($metric, 'ttfb') !== false) {
        $istime = true;
    }
    if (LoadData($data, $configurations, $benchmark, $cached, $metric, $aggregate, $loc)) {
        $series = array();
        $tsv = 'Date';
        foreach($configurations as &$configuration) {
            if (array_key_exists('title', $configuration) && strlen($configuration['title']))
                $title = $configuration['title'];
            else
                $title = $configuration['name'];
            if (count($configuration['locations']) > 1) {
                $name = "$title ";
                if (count($configurations) == 1)
                    $name = '';
                foreach ($configuration['locations'] as &$location) {
                    if (is_numeric($location['label'])) {
                        $tsv .= "\t$name{$location['location']}";
                        $series[] = "$name{$location['location']}";
                    } else {
                        $tsv .= "\t$name{$location['label']}";
                        $series[] = "$name{$location['label']}";
                    }
                }
            } else {
                $tsv .= "\t$title";
                $series[] = $title;
            }
        }
        $tsv .= "\n";
        $dates = array();
        foreach ($data as $time => &$row) {
            $date_text = gmdate('c', $time);
            $tsv .= $date_text;
            $dates[$date_text] = $time;
            foreach($configurations as &$configuration) {
                foreach ($configuration['locations'] as &$location) {
                    $tsv .= "\t";
                    if (array_key_exists($configuration['name'], $row) && array_key_exists($location['location'], $row[$configuration['name']])) {
                        $value = $row[$configuration['name']][$location['location']];
                        if ($aggregate != 'count') {
                            if ($isbytes)
                                $value = number_format($value / 1024.0, 3);
                            elseif ($istime)
                                $value = number_format($value / 1000.0, 3);
                        }
                        $tsv .= $value;
                    }
                }
            }
            $tsv .= "\n";
        }
        if (is_file("./settings/benchmarks/$benchmark.notes")) {
            $notes = parse_ini_file("./settings/benchmarks/$benchmark.notes", true);
            $i = 0;
            asort($dates);
            foreach($notes as $note_date => $note) {
                // find the closest data point on or after the selected date
                $note_date = str_replace('/', '-', $note_date);
                if (!array_key_exists($note_date, $dates)) {
                    $UTC = new DateTimeZone('UTC');
                    $date = DateTime::createFromFormat('Y-m-d H:i', $note_date, $UTC);
                    if ($date !== false) {
                        $time = $date->getTimestamp();
                        unset($note_date);
                        if ($time) {
                            foreach($dates as $date_text => $date_time) {
                                if ($date_time >= $time) {
                                    $note_date = $date_text;
                                    break;
                                }
                            }
                        }
                    }
                }
                if (isset($note_date) && array_key_exists('text', $note) && strlen($note['text'])) {
                    $i++;
                    foreach($series as $data_series) {
                        $annotations[] = array('series' => $data_series, 'x' => $note_date, 'shortText' => "$i", 'text' => $note['text']);
                    }
                }
            }
        }
    }
    return $tsv;
}

/**
* Load data for the given request (benchmark/metric)
* 
*/
function LoadData(&$data, &$configurations, $benchmark, $cached, $metric, $aggregate, $loc) {
    $ok = false;
    $data = array();
    if (GetConfigurationNames($benchmark, $configurations, $loc, $loc_aliases)) {
        $data_file = "./results/benchmarks/$benchmark/aggregate/$metric.json";
        if (gz_is_file($data_file)) {
            $raw_data = json_decode(gz_file_get_contents($data_file), true);
            if (count($raw_data)) {
                foreach($raw_data as &$row) {
                    if ($row['cached'] == $cached &&
                        array_key_exists($aggregate, $row) &&
                        strlen($row[$aggregate])) {
                        $time = $row['time'];
                        $config = $row['config'];
                        $location = $row['location'];
                        if (isset($loc_aliases) && count($loc_aliases)) {
                            foreach($loc_aliases as $loc_name => &$aliases) {
                                foreach($aliases as $alias) {
                                    if ($location == $alias) {
                                        $location = $loc_name;
                                        break 2;
                                    }
                                }
                            }
                        }
                        if (!isset($loc) || $loc == $location) {
                            $ok = true;
                            if (!array_key_exists($time, $data)) {
                                $data[$time] = array();
                            }
                            if (!array_key_exists($config, $data[$time])) {
                                $data[$time][$config] = array();
                            }
                            $data[$time][$config][$location] = $row[$aggregate];
                        }
                    }
                }
            }
        }
    }
    return $ok;
}

/**
* Get the list of configurations for the given benchmark
* 
* @param mixed $benchmark
*/
function GetConfigurationNames($benchmark, &$configs, $loc, &$loc_aliases) {
    $ok = false;
    $configs = array();
    if (isset($loc_aliases))
        unset($loc_aliases);
    if (include "./settings/benchmarks/$benchmark.php") {
        $ok = true;
        if (isset($location_aliases)) {
            $loc_aliases = $location_aliases;
        }
        foreach ($configurations as $name => &$config) {
            $entry = array('name' => $name, 'title' => $config['title'] ,'locations' => array());
            if (array_key_exists('locations', $config)) {
                foreach ($config['locations'] as $label => $location) {
                    if (!isset($loc) || $location == $loc) {
                        $entry['locations'][] = array('location' => $location, 'label' => $label);
                    }
                }
            }
            $configs[] = $entry;
        }
    }
    return $ok;
}

/**
* Get information about the various benchmarks that are configured
* 
*/
function GetBenchmarks() {
    $benchmarks = array();
    $bm_list = file('./settings/benchmarks/benchmarks.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!count($bm_list))
        $bm_list = glob('./settings/benchmarks/*.php');
    foreach ($bm_list as $benchmark) {
        $benchmarks[] = GetBenchmarkInfo(basename($benchmark, '.php'));
    }
    return $benchmarks;
}

/**
* Get the information for a single benchmark
* 
* @param mixed $benchmark
*/
function GetBenchmarkInfo($benchmark) {
    $info = array('name' => $benchmark);
    if(include "./settings/benchmarks/$benchmark.php") {
        if (isset($title)) {
            $info['title'] = $title;
        }
        if (isset($description)) {
            $info['description'] = $description;
        }
        $info['fvonly'] = false;
        $info['video'] = false;
        $info['expand'] = false;
        $info['options'] = array();
        if (isset($expand) && $expand)
            $info['expand'] = true;
        if (isset($options))
            $info['options'] = $options;
        if (isset($configurations)) {
            $info['configurations'] = $configurations;
            $info['locations'] = array();
            foreach($configurations as &$configuration) {
                if (array_key_exists('settings', $configuration)) {
                    foreach ($configuration['settings'] as $key => $value) {
                        if ($key == 'fvonly' && $value)
                            $info['fvonly'] = true;
                        elseif ($key == 'video' && $value)
                            $info['video'] = true;
                    }
                }
                if (array_key_exists('locations', $configuration)) {
                    foreach ($configuration['locations'] as $label => $location) {
                        $info['locations'][$location] = $label;
                    }
                }
            }
        }
    }
    return $info;
}

/**
* Load the raw data for the given test
* 
*/
function LoadTestDataTSV($benchmark, $cached, $metric, $test, &$meta, $loc) {
    $tsv = null;
    $isbytes = false;
    $istime = false;
    $annotations = array();
    if (stripos($metric, 'bytes') !== false) {
        $isbytes = true;
    } elseif (stripos($metric, 'time') !== false || 
            stripos($metric, 'render') !== false || 
            stripos($metric, 'fullyloaded') !== false || 
            stripos($metric, 'visualcomplete') !== false || 
            stripos($metric, 'eventstart') !== false || 
            stripos($metric, 'ttfb') !== false) {
        $istime = true;
    }
    if (LoadTestData($data, $configurations, $benchmark, $cached, $metric, $test, $meta, $loc)) {
        $series = array();
        $tsv = 'URL';
        foreach($configurations as &$configuration) {
            if (array_key_exists('title', $configuration) && strlen($configuration['title']))
                $title = $configuration['title'];
            else
                $title = $configuration['name'];
            if (count($configuration['locations']) > 1) {
                $name = "$title ";
                if (count($configurations) == 1)
                    $name = '';
                foreach ($configuration['locations'] as &$location) {
                    if (is_numeric($location['label'])) {
                        $tsv .= "\t$name{$location['location']}";
                        $series[] = "$name{$location['location']}";
                    } else {
                        $tsv .= "\t$name{$location['label']}";
                        $series[] = "$name{$location['label']}";
                    }
                }
            } else {
                $tsv .= "\t$title";
                $series[] = $title;
            }
        }
        $tsv .= "\n";
        foreach ($data as $url => &$row) {
            $data_points = 0;
            $url_data = array();
            // figure out the maximum number of data points we have
            foreach($configurations as &$configuration) {
                foreach ($configuration['locations'] as &$location) {
                    if (array_key_exists($configuration['name'], $row) && 
                        array_key_exists($location['location'], $row[$configuration['name']]) &&
                        is_array($row[$configuration['name']][$location['location']])) {
                        $count = count($row[$configuration['name']][$location['location']]);
                        if ($count > $data_points)
                            $data_points = $count;
                    }
                }
            }
            for ($i = 0; $i < $data_points; $i++) {
                $tsv .= $url;
                $column = 0;
                foreach($configurations as &$configuration) {
                    foreach ($configuration['locations'] as &$location) {
                        $value = ' ';
                        if (array_key_exists($configuration['name'], $row) && 
                            array_key_exists($location['location'], $row[$configuration['name']]) &&
                            is_array($row[$configuration['name']][$location['location']])) {
                            $count = count($row[$configuration['name']][$location['location']]);
                            if ($i < $count) {
                                if (!array_key_exists('tests', $meta[$url])) {
                                    $meta[$url]['tests'] = array();
                                    for ($j = 0; $j < count($series); $j++)
                                        $meta[$url]['tests'][] = '';
                                }
                                $meta[$url]['tests'][$column] = $row[$configuration['name']][$location['location']][$i]['test'];
                                $value = $row[$configuration['name']][$location['location']][$i]['value'];
                                if ($isbytes)
                                    $value = number_format($value / 1024.0, 3);
                                elseif ($istime)
                                    $value = number_format($value / 1000.0, 3);
                            }
                        }
                        $tsv .= "\t$value";
                        $column++;
                    }
                }
                $tsv .= "\n";
            }
        }
    }
    return $tsv;
}

/**
* Load the raw data for a given test
* 
*/
function LoadTestData(&$data, &$configurations, $benchmark, $cached, $metric, $test, &$meta, $loc) {
    global $raw_data;
    $ok = false;
    $data = array();
    $meta = array();
    if (GetConfigurationNames($benchmark, $configurations, $loc, $loc_aliases)) {
        $date = gmdate('Ymd_Hi', $test);
        $data_file = "./results/benchmarks/$benchmark/data/$date.json";
        if (gz_is_file($data_file)) {
            if (!isset($raw_data)) {
                $raw_data = json_decode(gz_file_get_contents($data_file), true);
            }
            if (count($raw_data)) {
                foreach($raw_data as &$row) {
                    if (array_key_exists('cached', $row) &&
                        $row['cached'] == $cached &&
                        array_key_exists('url', $row) && 
                        array_key_exists('config', $row) && 
                        array_key_exists('location', $row) && 
                        array_key_exists($metric, $row) && 
                        strlen($row[$metric])) {
                        $url = GetUrlIndex($row['url'], $meta);
                        $config = $row['config'];
                        $location = $row['location'];
                        if (isset($loc_aliases) && count($loc_aliases)) {
                            foreach($loc_aliases as $loc_name => &$aliases) {
                                foreach($aliases as $alias) {
                                    if ($location == $alias) {
                                        $location = $loc_name;
                                        break 2;
                                    }
                                }
                            }
                        }
                        if (!isset($loc) || $loc == $location) {
                            $ok = true;
                            if (!array_key_exists($url, $data)) {
                                $data[$url] = array();
                            }
                            if (!array_key_exists($config, $data[$url])) {
                                $data[$url][$config] = array();
                            }
                            $data[$url][$config][$location][] = array('value' => $row[$metric], 'test' => $row['id']);
                        }
                    }
                }
            }
        }
    }
    return $ok;
}

/**
* Convert the URLs into indexed numbers
* 
* @param mixed $url
* @param mixed $urls
*/
function GetUrlIndex($url, &$meta) {
    $index = 0;
    $found = false;
    foreach($meta as $i => &$u) {
        if ($u['url'] == $url) {
            $index = $i;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $index = count($meta);
        $meta[] = array('url' => $url);
    }
    return $index;
}

/*
    Helper functions to deal with aggregate benchmark data
*/
function LoadTrendDataTSV($benchmark, $cached, $metric, $url, $loc, &$annotations, &$meta) {
    $tsv = null;
    $isbytes = false;
    $istime = false;
    $annotations = array();
    if (stripos($metric, 'bytes') !== false) {
        $isbytes = true;
    } elseif (stripos($metric, 'time') !== false || 
            stripos($metric, 'render') !== false || 
            stripos($metric, 'fullyloaded') !== false || 
            stripos($metric, 'visualcomplete') !== false || 
            stripos($metric, 'eventstart') !== false || 
            stripos($metric, 'ttfb') !== false) {
        $istime = true;
    }
    if (LoadTrendData($data, $configurations, $benchmark, $cached, $metric, $url, $loc)) {
        $series = array();
        $tsv = 'Date';
        foreach($configurations as &$configuration) {
            if (array_key_exists('title', $configuration) && strlen($configuration['title']))
                $title = $configuration['title'];
            else
                $title = $configuration['name'];
            if (count($configuration['locations']) > 1) {
                $name = "$title ";
                if (count($configurations) == 1)
                    $name = '';
                foreach ($configuration['locations'] as &$location) {
                    if (is_numeric($location['label'])) {
                        $tsv .= "\t$name{$location['location']}";
                        $series[] = "$name{$location['location']}";
                    } else {
                        $tsv .= "\t$name{$location['label']}";
                        $series[] = "$name{$location['label']}";
                    }
                }
            } else {
                $tsv .= "\t$title";
                $series[] = $title;
            }
        }
        $tsv .= "\n";
        $dates = array();
        foreach ($data as $time => &$row) {
            $date_text = gmdate('c', $time);
            $tsv .= $date_text;
            $dates[$date_text] = $time;
            $column=0;
            foreach($configurations as &$configuration) {
                foreach ($configuration['locations'] as &$location) {
                    $tsv .= "\t";
                    if (array_key_exists($configuration['name'], $row) && 
                        array_key_exists($location['location'], $row[$configuration['name']]) &&
                        array_key_exists('value', $row[$configuration['name']][$location['location']]) ) {
                        $value = $row[$configuration['name']][$location['location']]['value'];
                        if ($isbytes)
                            $value = number_format($value / 1024.0, 3);
                        elseif ($istime)
                            $value = number_format($value / 1000.0, 3);
                        $tsv .= $value;
                        if (!array_key_exists($time, $meta)) {
                            $meta[$time] = array();
                        }
                        $meta[$time][] = array('label' => $series[$column], 'test' => $row[$configuration['name']][$location['location']]['test']);
                    }
                    $column++;
                }
            }
            $tsv .= "\n";
        }
        if (is_file("./settings/benchmarks/$benchmark.notes")) {
            $notes = parse_ini_file("./settings/benchmarks/$benchmark.notes", true);
            $i = 0;
            asort($dates);
            foreach($notes as $note_date => $note) {
                // find the closest data point on or after the selected date
                $note_date = str_replace('/', '-', $note_date);
                if (!array_key_exists($note_date, $dates)) {
                    $UTC = new DateTimeZone('UTC');
                    $date = DateTime::createFromFormat('Y-m-d H:i', $note_date, $UTC);
                    if ($date !== false) {
                        $time = $date->getTimestamp();
                        unset($note_date);
                        if ($time) {
                            foreach($dates as $date_text => $date_time) {
                                if ($date_time >= $time) {
                                    $note_date = $date_text;
                                    break;
                                }
                            }
                        }
                    }
                }
                if (isset($note_date) && array_key_exists('text', $note) && strlen($note['text'])) {
                    $i++;
                    foreach($series as $data_series) {
                        $annotations[] = array('series' => $data_series, 'x' => $note_date, 'shortText' => "$i", 'text' => $note['text']);
                    }
                }
            }
        }
    }
    return $tsv;
}

/**
* Load data for a single URL trended over time from all of the configurations
* 
*/
function LoadTrendData(&$data, &$configurations, $benchmark, $cached, $metric, $url, $loc, $options) {
    global $trend_data;
    $ok = false;
    $data = array();
    if (GetConfigurationNames($benchmark, $configurations, $loc, $loc_aliases)) {
        if (!isset($trend_data)) {
            // loop through all of the data files
            $files = scandir("./results/benchmarks/$benchmark/data");
            foreach( $files as $file ) {
                if (preg_match('/([0-9]+_[0-9]+)\..*/', $file, $matches)) {
                    $UTC = new DateTimeZone('UTC');
                    $date = DateTime::createFromFormat('Ymd_Hi', $matches[1], $UTC);
                    $time = $date->getTimestamp();
                    $tests = array();
                    $raw_data = json_decode(gz_file_get_contents("./results/benchmarks/$benchmark/data/$file"), true);
                    if (count($raw_data)) {
                        foreach($raw_data as $row) {
                            if (array_key_exists('docTime', $row) && 
                                ($row['result'] == 0 || $row['result'] == 99999) &&
                                ($row['label'] == $url || $row['url'] == $url)) {
                                $location = $row['location'];
                                $id = $row['id'];
                                if (!array_key_exists($id, $tests)) {
                                    $tests[$id] = array();
                                }
                                $row['time'] = $time;
                                $tests["$id-{$row['cached']}"][] = $row;
                            }
                        }
                        // grab the median run from each test
                        if (count($tests)) {
                            foreach($tests as &$test) {
                                $times = array();
                                foreach($test as $row) {
                                    $times[] = $row['docTime'];
                                }
                                $median_run_index = 0;
                                $count = count($times);
                                if( $count > 1 ) {
                                    asort($times);
                                    $medianIndex = (int)floor(((float)$count + 1.0) / 2.0);
                                    $current = 0;
                                    foreach( $times as $index => $time ) {
                                        $current++;
                                        if( $current == $medianIndex ) {
                                            $median_run_index = $index;
                                            break;
                                        }
                                    }
                                }
                                $trend_data[] = $test[$median_run_index];
                            }
                        }
                    }
                }
            }
        }
        if (count($trend_data)) {
            foreach( $trend_data as &$row ) {
                if( $row['cached'] == $cached &&
                    array_key_exists($metric, $row) && 
                    strlen($row[$metric])) {
                    $time = $row['time'];
                    $config = $row['config'];
                    $location = $row['location'];
                    if (isset($loc_aliases) && count($loc_aliases)) {
                        foreach($loc_aliases as $loc_name => &$aliases) {
                            foreach($aliases as $alias) {
                                if ($location == $alias) {
                                    $location = $loc_name;
                                    break 2;
                                }
                            }
                        }
                    }
                    if (!isset($loc) || $loc == $location) {
                        $ok = true;
                        if (!array_key_exists($time, $data)) {
                            $data[$time] = array();
                        }
                        if (!array_key_exists($config, $data[$time])) {
                            $data[$time][$config] = array();
                        }
                        $data[$time][$config][$location] = array('value' => $row[$metric], 'test' => $row['id']);
                    }
                }
            }
        }
    }
    return $ok;
}

?>
