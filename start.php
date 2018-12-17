<?php
require_once ('vendor/autoload.php');
require_once ('_.php');

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeOutException;
use Facebook\WebDriver\Exception\StaleElementReferenceException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\Exception\WebDriverCurlException;

$CSV = csvFileToArray(CSV_FILE);
$PROXIES = csvFileToArray(PROXY_FILE);

//exec('rm ' . PHP_LOG . '  2> /dev/null');
exec('rm ' . SELENIUM_LOG . '  2> /dev/null');

clearAndStartAllProcesses();

$itemsProcessed = 0;
$itemsPassedAtStart = 0;
$curlError = 0;
$curlErrorRaised = false;

e("Start at " . date('Y-m-d H:i:s'));

for ($id = 0; $id < count($CSV); $id++) {
    $string = $CSV[$id];
    if (
           ($string[0] === 'URL' || $string[0] === '')
        || (isset($string[1]) && $string[1] != '')
        || (isset($string[2]) && $string[2] != '')
        || (THREAD_ID !== null && $id % THREADS != THREAD_ID)
    ) continue;
    if ($itemsProcessed == 0) $itemsPassedAtStart = $id;
    if (!$curlErrorRaised) $curlError = 0;
    $curlErrorRaised = false;
    
    $proxy = $PROXIES[rand(0, count($PROXIES) - 1)][0];
    $Capabilities = getCapabilities($proxy);
    try {
        $Browser = RemoteWebDriver::create(HOST, $Capabilities, 30000, 30000);
    } catch (WebDriverCurlException $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        e("have a curl error on browser creating, retrying");
        $CSV[$id][2] = '';
        clearAndStartAllProcesses();
        $id--;
    }
    $itemsProcessed++;
    if ($itemsProcessed > 5) {
        $timePerOne = (time() - TIME_START) / $itemsProcessed;
        $elapsed = time() + $timePerOne * (count($CSV) - $itemsPassedAtStart);
        $elapsed = ". End prognosis: " . date("d H:i:s", $elapsed);
    } else $elapsed = '';
    e("\033[1;33m$id: " . implode(' ', $string) . " | $elapsed\033[0m" . ($proxy ? ", proxy $proxy": ''));
    $CSV[$id][1] = '';
    $CSV[$id][2] = '';
    unset($string[2]);
    try {
        if (preg_match('/^http/', $string[0]) == 0) $string[0] = 'http://' . $string[0];
        $Browser->get($string[0]);
        $adw = null;
        e("searching adw");
        try {
            $Browser->wait(2)->until(function () use ($Browser, $id) {
                global $adw;
                try {
                    $adw = $Browser->findElement(WebDriverBy::cssSelector('.mcimg a'));
                    e("found");
                    return true;
                } catch (NoSuchElementException $e) {
                    $console = $Browser->manage()->getLog('browser');
                    foreach ($console as $message) {
                        $message = $message['message'];
                        if (strpos($message, 'mgid.com') !== false &&
                            strpos($message, 'Failed to load resource: the server responded with a status of 403 ()')) {
                            e("\033[0;31mLOOKS LIKE BAN\033[0m");
                            $id--;
                            //exit;
                        }
                    }
                    return false;
                }
            });
        } catch (Exception $e) {
            if (strpos($e, 'TimeOutException') !== false) {
                e("\033[0;31madw not found, nothing to do here\033[0m");
                throw new NoSuchElementException("no adw");
            }
            else throw $e;
        }
        /** @var $adw RemoteWebElement*/
        $coord = null;
        $Browser->wait(5)->until(function () use ($adw, $Browser) {
            try {
                $coord = $adw->getCoordinates();
                $Y = $coord->onPage()->getY() - 200;
                if ($Y > 0) $Browser->executeScript("window.scroll(0, $Y)");
                e("attached");
                return true;
            } catch (StaleElementReferenceException $e) {
                e("not attached");
                return false;
            }
        });
        $coord = $adw->getCoordinates();
        $Y = $coord->onPage()->getY() - 400;
        if ($Y > 0) {
            e("scrolling to $Y");
            $Browser->executeScript("window.scroll(0, $Y)");
        }
        
        e('trying to click adw block');
        $adwBlock = $Browser->findElement(WebDriverBy::cssSelector('.mcimg'));
        $Browser->wait(5)->until(function () use ($Browser, $adwBlock) {
            try {
                $adwBlock->click();
                return true;
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'is not clickable') !== false) {
                        e("not clickable");
                        preg_match('/would receive the click: (.+)/', $msg, $arr);
                        if (count($arr) !== 2) throw $e;
                        $elemStr = $arr[1];
                        preg_match_all('/([-_\w\d]+)="([^"]+)"/', $elemStr, $arr);
                        $selectors = [];
                        for ($i = 0; $i < count($arr[1]); $i++) {
                            if ($arr[1][$i] == 'id') $selectors[] = '#' . trim($arr[2][$i]);
                            elseif ($arr[1][$i] == 'class') $selectors[] = '.' . preg_replace('/\s+/', '.', $arr[2][$i]);
                            elseif ($arr[1][$i] == 'style') continue;
                            else   $selectors[] = '[' . $arr[1][$i] . '="' . trim($arr[2][$i]) . '"]';
                        }
                        $selectors = implode('', $selectors);
                        e("trying to remove Element $selectors");
                        $Browser->executeScript("document.querySelector('$selectors').remove()");
                        return false;
                }
                elseif(strpos($msg, 'not interactable') !== false) {
                    e("\033[0;31madw block is not interactable, try to continue\033[0m");
                    return true;
                }
                else throw $e;
            }
        });
        if (isset($Browser->getWindowHandles()[1]))
            $Browser->switchTo()->window($Browser->getWindowHandles()[1])->close();
        $Browser->switchTo()->window($Browser->getWindowHandles()[0]);
        $Mouse = $Browser->getMouse();
        $size = $adw->getSize();
        $target = ['x' => $coord->inViewPort()->getX(), 'y' => $coord->inViewPort()->getY()];
        $start = ['x' => rand(0, $target['x'] - 1), 'y' => $target['y'] - 1];
        $size = ['x' => $size->getWidth(), 'y' => $size->getHeight()];
        $end = [
            'x' => $target['x'] + round($size['x'] / 2) + rand(-10, 10),
            'y' => $target['y'] + round($size['y'] / 2) + rand(-10, 10),
        ];
        $pos = $start;
        e('moving mouse');
        $Mouse->mouseMove(null, $start['x'], $start['y']);
        $steps = 0;
        while ($pos != $end) {
            $steps++;
            $ofs = [
                'x' => round(($end['x'] - $start['x']) / 10) + rand(-5, 5),
                'y' => round(($end['y'] - $start['y']) / 10) + rand(-5, 5),
            ];
            $prevPos = $pos;
            $pos = [
                'x' => $prevPos['x'] + $ofs['x'],
                'y' => $prevPos['y'] + $ofs['y']
            ];
            if ($pos['x'] > $end['x']) {
                $pos['x'] = $end['x'];
                $ofs['x'] = $end['x'] - $prevPos['x'];
            }
            if ($pos['y'] > $end['y']) {
                $pos['y'] = $end['y'];
                $ofs['y'] = $end['y'] - $prevPos['y'];
            }
            if ($steps > 10) {
                $pos = $end;
                $ofs = ['x' => $end['x'] - $prevPos['x'], 'y' => $end['y'] - $prevPos['y']];
            }
            $Mouse->mouseMove(null, $ofs['x'], $ofs['y']);
            //e($pos['x'] . ' ' . $pos['y'] . '(' . $ofs['x'] . ' ' . $ofs['y'] . ')');
        }
        for ($i = 0; $i < 10; $i++) {
            $ofs = ['x' => rand(-2, 2), 'y' => rand(-2, 2)];
            $Mouse->mouseMove(null, $ofs['x'], $ofs['y']);
            //e("RAND: " . $ofs['x'] . ' ' . $ofs['y']);
        }
        e('clicking');
        $Browser->wait(5)->until(function() use ($Browser, $adw, $Mouse) {
            try {
                $adw->click();
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'is not clickable') !== false) {
                    e("not clickable");
                    preg_match('/would receive the click: (.+)/', $msg, $arr);
                    if (count($arr) !== 2) throw $e;
                    $elemStr = $arr[1];
                    preg_match_all('/([-_\w\d]+)="([^"]+)"/', $elemStr, $arr);
                    $selectors = [];
                    for ($i = 0; $i < count($arr[1]); $i++) {
                        if ($arr[1][$i] == 'id') $selectors[] = '#' . trim($arr[2][$i]);
                        elseif ($arr[1][$i] == 'class') $selectors[] = '.' . preg_replace('/\s+/', '.', $arr[2][$i]);
                        elseif ($arr[1][$i] == 'style') continue;
                        else   $selectors[] = '[' . $arr[1][$i] . '="' . trim($arr[2][$i]) . '"]';
                    }
                    $selectors = implode('', $selectors);
                    e("trying to remove Element $selectors");
                    $Browser->executeScript("document.querySelector('$selectors').remove()");
                    return false;
                }
                elseif (strpos($msg, 'element not interactable') !== false) return false;
                else throw $e;
            }
            return count($Browser->getWindowHandles()) > 1;
        });
        $Browser->switchTo()->window($Browser->getWindowHandles()[1]);
        $href = $Browser->getCurrentURL();
        if (strpos($href, 'marketgid.com') !== false OR strpos($href, 'mgid.com') !== false) {
            e("\033[0;31mMarketgid is not sure it is not a bot\033[0m");
            $link = $Browser->findElements(WebDriverBy::cssSelector('a'));
            if (isset($link[1])) $link = $link[1];
            else throw new Exception("No link in antibot screen");
            $coord = $link->getCoordinates();
            e("clicking");
            $Browser->getMouse()->mouseMove($coord, rand(10, 15), rand(10, 15))
                ->mouseMove(null, rand(10, 15), rand(10, 15))
                ->mouseMove(null, rand(10, 15), rand(10, 15))
                ->mouseMove(null, rand(10, 15), rand(10, 15));
            $link->click();
        }
        $Browser->close();
        $Browser->switchTo()->window($Browser->getWindowHandles()[0]);
        try {
            e("waiting for cookie from mgid.com");
            $Browser->get('https://www.mgid.com');
            $Browser->wait(5)->until(
                function () use ($Browser) {
                    return $Browser->manage()->getCookieNamed('mtuid') !== null;
                }
            );
        } catch (TimeOutException $e) {
            e("waiting for cookie from marketgid.com");
            $Browser->get('https://www.marketgid.com');
            $Browser->wait(5)->until(
                function () use ($Browser) {
                    return $Browser->manage()->getCookieNamed('mtuid') !== null;
                }
            );
        }
        $cookie = $Browser->manage()->getCookieNamed('mtuid')->getValue();
        $CSV[$id][1] = $cookie;
        e("FOUND COOKIE $cookie");
    } catch (NoSuchElementException $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        e("element not found: $e");
        $CSV[$id][2] = 'element not found';
    } catch (TimeOutException $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        e("timeout: $e");
        $CSV[$id][2] = 'timeout';
    } catch (WebDriverCurlException $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        clearAndStartAllProcesses();
        if ($curlError < 2) {
            e("have a curl error, trying to retry");
            $CSV[$id][2] = '';
            $id--;
            $curlError++;
            $curlErrorRaised = true;
        }
        else {
            e("\033[0;31mtoo much curl errors, have to continue\033[0m");
            $CSV[$id][2] = 'curl error';
            $curlError = 0;
        }
    } catch (Exception $e) {
        $msg = preg_replace('/\n[\w\W]+/', '', $e->getMessage());
        e($e);
        $CSV[$id][2] = $msg;
    } finally {
        `rm weblist.csv`;
        $fileCSV = fopen(CSV_FILE, 'w');
        foreach($CSV as $str) fputcsv($fileCSV, $str, ';');
        if ($curlError == 0) {
            try {
                $Browser->quit();
            } catch (Exception $e) {
                e($e);
                clearAndStartAllProcesses();
            }
        }
    }
}