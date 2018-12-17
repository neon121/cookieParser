<?php
define ('DEBUG', true);
define ('HOST', 'http://localhost:4444/wd/hub');
define ('WIDTH', 1366);
define ('HEIGHT', 728);
define ("PHP_LOG", '_php.log');
define ("SELENIUM_LOG", '_selenium.log');
define ("CSV_FILE", "weblist.csv");
define ("PROXY_FILE", "proxies.csv");
define ("THREADS", 2);

define ('TIME_START', time());
define ('THREAD_ID', $argv[1] ?? null);

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;

$file = null;
function e() {
    $msg = func_num_args() == 1 ? func_get_arg(0) : implode(' ', func_get_args());
    $str = "\033[1;37m(".debug_backtrace()[0]['line'].")\t".date('H:i:s')."\033[0m\t$msg\n";
    if (DEBUG) echo $str;
    if (THREAD_ID !== 'stop') {
        global $file;
        if (!is_resource($file)) $file = fopen(PHP_LOG, 'w');
        $str = preg_replace('/.+?m/','', $str);
        fwrite($file, $str);
    }
}
function pr($arr, $recursion = 0) {
    if (DEBUG) {
        if (!$recursion) echo "\033[1;37m(".debug_backtrace()[0]['line'].")\t".date('H:i:s')."\033[0m\n";
        foreach ($arr as $id => $value) {
            if (is_array($value)) {
                echo str_repeat("\t", $recursion + 2)."'$id' => \n";
                pr($value, $recursion + 1);
            }
            else echo str_repeat("\t", $recursion + 2) . "'$id' => '$value'\n";
        }
    }
}

$screenShotsCounter = 0;
function takeScreenshots() {
    /**@var $Browser Facebook\WebDriver\Remote\RemoteWebDriver*/
    global $Browser, $screenShotsCounter;
    e("Taking screenshots $screenShotsCounter");
    if ($screenShotsCounter == 0) `rm _img/* 2> /dev/null`;
    foreach($Browser->getWindowHandles() as $i => $handle) {
        $Browser->switchTo()->window($handle);
        e($i . ': ' . $Browser->getCurrentURL());
        $Browser->takeScreenshot("_img/{$screenShotsCounter}_$i.png");
    }
    $screenShotsCounter++;
}

function clearAndStartAllProcesses() {
    clearAllProcesses();
    $log = SELENIUM_LOG;
    `nohup java -jar selenium-server-standalone-3.9.1.jar > $log 2>&1 &`;
    sleep(3);
}

function clearAllProcesses() {
    `pkill -f selenium-server-standalone-3.9.1.jar`;
    `pkill -f chromedriver`;
    `pkill -f chrome`;
    //`pkill -f "start.php"`;
    //exec('rm ' . PHP_LOG . '  2> /dev/null');
    //exec('rm ' . SELENIUM_LOG . '  2> /dev/null');
}

register_shutdown_function(function() {
    if (THREAD_ID !== null) {
        e("Finished at " . date('Y-m-d H:i:s'));
    }
});

function csvFileToArray($path) {
    $file = fopen($path, 'r+');
    $return = [];
    while ($row = fgetcsv($file, 0, ';')) $return[] = $row;
    fclose($file);
    return $return;
}

/**
 * @param null|string $proxy
 * @return DesiredCapabilities
 */
function getCapabilities($proxy = null) {
    $Options = new ChromeOptions();
    $Options->setBinary('/usr/bin/google-chrome-stable');
    $Options->addArguments([
        '--headless',
        '--no-sandbox',
        '--no-default-browser-check',
        ($proxy !== null ? "--proxy-server=$proxy" : '--no-proxy-server'),
        '--no-first-run',
        '--disable-boot-animation',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-default-apps',
        '--disable-extensions',
        '--disable-translate',
        '--disable-web-security',
        '--window-size=' . WIDTH . ',' . HEIGHT,
    ]);
    $Capabilities = DesiredCapabilities::chrome();
    $Capabilities->setCapability(ChromeOptions::CAPABILITY, $Options);
    return $Capabilities;
}