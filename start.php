<?php
require_once ('vendor/autoload.php');
define ('DEBUG', true);
define ('HOST', 'http://localhost:4444/wd/hub');
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeOutException;
use Facebook\WebDriver\Exception\UnknownServerException;
use Facebook\WebDriver\Exception\WebDriverCurlException;

define('TIME_START', time());
if (DEBUG) echo "Start at " . date('Y-m-d H:i:s', TIME_START) . "\n";
register_shutdown_function(function() {
    if (DEBUG) echo "\nFinished at " . date('Y-m-d H:i:s');
});
$CSV = explode("\n", file_get_contents("weblist.csv"));
foreach ($CSV as &$string) {
    $string = explode(';', $string);
    $string = array_map(function($val) {return trim($val);}, $string);
}
unset($string);

$Options = new ChromeOptions();
$Options->setBinary('/usr/bin/google-chrome-stable');
$Options->addArguments([
    '--headless',
    '--no-sandbox',
    '--no-proxy-server',
    '--no-default-browser-check',
    '--no-first-run',
    '--disable-boot-animation',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-default-apps',
    '--disable-extensions',
    '--disable-translate',
    '--window-size=1366,768'
]);
$Capabilities = DesiredCapabilities::chrome();
$Capabilities->setCapability(ChromeOptions::CAPABILITY, $Options);
$Browser = RemoteWebDriver::create(HOST, $Capabilities, 5000, 5000);
$itemsProcessed = 0;
$itemsPassedAtStart = 0;

foreach ($CSV as $id => $string) {
    if ($string[0] === 'URL' || $string[0] === '') continue;
    if (isset($string[1]) && $string[1] != '') continue;
    if (isset($string[2]) && $string[2] != '' && $string[2] !== 'Timeout') continue;
    if ($itemsProcessed == 0) $itemsPassedAtStart = $id;
    $itemsProcessed++;
    if ($itemsProcessed > 5) {
        $timePerOne = (time() - TIME_START) / $itemsProcessed;
        $elapsed = TIME_START + $timePerOne * (count($CSV) - $itemsPassedAtStart);
        $elapsed = ". End prognosis: " . date("d H:i:s", $elapsed);
    }
    else $elapsed = '';
    if (DEBUG) echo (implode(' ', $string))."$elapsed\n";
    $CSV[$id][1] = '';
    unset($string[2]);
    try {
        if (preg_match('/^http/', $string[0]) == 0) $string[0] = 'http://' . $string[0];
        $Browser->get($string[0]);
        $Browser->manage()->timeouts()->implicitlyWait(rand(20, 50) / 10);
        $adw = $Browser->findElement(WebDriverBy::cssSelector('div.mcimg'));
        $coord = $adw->getCoordinates();
        $Y = $coord->onPage()->getY();
        $Browser->executeScript("window.scroll(0, $Y)");
        $currentTab = $Browser->getWindowHandle();
        $Browser->wait(5)->until(function () use ($Browser, $adw, $coord) {
            $Browser->manage()->timeouts()->implicitlyWait(rand(10, 20) / 10);
            $Browser->getMouse()->mouseMove($coord, rand(10, 15), rand(10, 15));
            //$a = $adw->findElement(WebDriverBy::cssSelector('a'));
            try {
                $adw->click(); //this makes href right
            } catch (UnknownServerException $e) {
                $msg = $e->getMessage();
                if (DEBUG) echo "Not clickable\n";
                if (strpos($msg, 'is not clickable') !== false) {
                    preg_match('/would receive the click: (.+)/', $msg, $arr);
                    if (count($arr) !== 2) throw $e;
                    $elemStr = $arr[1];
                    preg_match_all('/([-_\w\d]+)="([^"]+)"/', $elemStr, $arr);
                    $selectors = [];
                    for ($i = 0; $i < count($arr[1]); $i++) {
                        if     ($arr[1][$i] == 'id')    $selectors[] = '#' . $arr[2][$i];
                        elseif ($arr[1][$i] == 'class') $selectors[] = '.' . str_replace(' ', '.', $arr[2][$i]);
                        elseif ($arr[1][$i] == 'style') continue;
                        else   $selectors[] = '[' . $arr[1][$i] . '=" ' . $arr[2][$i] . '"]';
                    }
                    $selectors = implode('', $selectors);
                    if (DEBUG) echo "trying to remove Element $selectors\n";
                    $Browser->executeScript("document.querySelector('$selectors').remove()");
                    return false;
                }
                else throw $e;
            }
            $handles = $Browser->getWindowHandles();
            return count($handles) > 1;
        });
        $Browser->switchTo()->window($currentTab);
        $Browser->get('https://www.mgid.com');
        $Browser->wait(5)->until(
            function () use ($Browser) {
                return $Browser->manage()->getCookieNamed('mtuid') !== null;
            }
        );
        $cookie = $Browser->manage()->getCookieNamed('mtuid')->getValue();
        $CSV[$id][1] = $cookie;
        if (DEBUG) echo ("FOUND COOKIE $cookie\n");
    } catch (NoSuchElementException $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        if (DEBUG) echo 'Element not found: ' . $msg."\n";
        $CSV[$id][2] = 'Element not found';
    } catch (TimeOutException $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        if (DEBUG) echo 'Timeout: ' . $msg."\n";
        $CSV[$id][2] = 'Timeout';
    } catch (Exception $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        if (DEBUG) echo $msg."\n";
        $CSV[$id][2] = preg_replace('/\n[\w\W]+/', '', $msg);
    } finally {
        `rm weblist.csv`;
        file_put_contents(
            'weblist.csv',
            implode(
                "\n",
                array_map(
                    function ($v) {return implode(';', $v);},
                    $CSV
                )
            )
        );
        try {
            $handles = $Browser->getWindowHandles();
            for ($i = 1; $i < count($handles); $i++) {
                $Browser->switchTo()->window($handles[$i])->close();
            }
            $Browser->switchTo()->window($handles[0]);
        }
        catch (WebDriverCurlException $e) {
            echo "Curl error when tried to get windows handles: " . $e->getMessage()."\n";
        }
    }
}
$Browser->quit();