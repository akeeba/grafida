<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

use Boson\Application;
use Boson\ApplicationCreateInfo;
use Boson\Component\Http\Static\FilesystemStaticProvider;
use Boson\WebView\Api\Schemes\Event\SchemeRequestReceived;
use Boson\WebView\WebViewCreateInfo;
use Boson\Window\WindowCreateInfo;
use Boson\Window\WindowDecoration;
use Grafida\FrontController;

require __DIR__ . '/vendor/autoload.php';

$app = new Application(new ApplicationCreateInfo(
    schemes: ['boson'],
    debug: (bool) filter_var(getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
    window: new WindowCreateInfo(
        title: 'Grafida',
        width: 1280,
        height: 860,
        decoration: WindowDecoration::DarkMode,
        webview: new WebViewCreateInfo(
            devTools: (bool) filter_var(getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
        ),
    ),
));

$static = new FilesystemStaticProvider([
    __DIR__ . '/assets/private',
    __DIR__ . '/assets/public',
]);

$controller = new FrontController($static);

$app->on(static function (SchemeRequestReceived $e) use ($controller): void {
    $e->response = $controller($e->request);
});

$app->webview->url = 'boson://app/';

$app->run();
