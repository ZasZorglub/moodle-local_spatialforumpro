<?php
// Captures the real Moodle report pages in the disposable browser test container.

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverExpectedCondition;

if (PHP_SAPI !== 'cli' || getenv('SFPRO_WWWROOT') !== 'http://moodle:8081') {
    exit(1);
}
require_once('/tmp/sfpro-moodle/vendor/autoload.php');

$capabilities = DesiredCapabilities::firefox();
$capabilities->setCapability('moz:firefoxOptions', ['args' => ['-headless']]);
$driver = RemoteWebDriver::create('http://browser:4444', $capabilities, 10000, 90000);
try {
    $driver->manage()->window()->setSize(new WebDriverDimension(1440, 1420));
    $driver->get('http://moodle:8081/login/index.php');
    $driver->findElement(WebDriverBy::id('username'))->sendKeys('admin');
    $driver->findElement(WebDriverBy::id('password'))->sendKeys('Qa-Disposable-01!');
    $driver->findElement(WebDriverBy::id('loginbtn'))->click();
    $driver->wait(20)->until(WebDriverExpectedCondition::not(
        WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('loginbtn')),
    ));
    $url = 'http://moodle:8081/local/spatialforumpro/index.php';
    $screens = [
        [$url, 'Spatial forum Pro participation', 'Weekly site summary', '01-participation-and-weekly-summary.png'],
        [$url . '?view=moderation', 'Moderation overview', 'Which prototype should we test first?', '02-moderation.png'],
    ];
    foreach ($screens as [$page, $heading, $example, $filename]) {
        $driver->get($page);
        $driver->wait(30)->until(static function (RemoteWebDriver $browser) use ($heading, $example): bool {
            $main = $browser->findElement(WebDriverBy::id('region-main'))->getText();
            return str_contains($main, $heading) && str_contains($main, $example)
                && (bool) $browser->executeScript('return document.readyState === "complete" && document.styleSheets.length > 0');
        });
        $driver->takeScreenshot('/screenshots/' . $filename);
        echo "Browser verified and captured $filename" . PHP_EOL;
    }
} finally {
    $driver->quit();
}
