<?php

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Application;
use Boson\ApplicationCreateInfo;
use Boson\Component\Http\Response;
use Boson\WebView\Api\Schemes\Event\SchemeRequestReceived;
use Boson\WebView\WebViewCreateInfo;
use Boson\Window\WindowCreateInfo;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createApplication(string $content): Application
    {
        $app = new Application(new ApplicationCreateInfo(
            schemes: ['test'],
            debug: true,
            window: new WindowCreateInfo(
                webview: new WebViewCreateInfo(
                    devTools: false,
                ),
            ),
        ));

        $app->on(function (SchemeRequestReceived $e) use ($content): void {
            $e->response = new Response($content);
        });

        $app->poller->delay(3, function() {
            $this->fail('Application was not stopped');
        });

        $app->webview->url = 'test://localhost';

        return $app;
    }
}
