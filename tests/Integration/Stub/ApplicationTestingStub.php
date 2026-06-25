<?php

declare(strict_types=1);

namespace Grafida\Tests\Integration\Stub;

use Boson\Application;

/**
 * @internal for testing purpose only
 */
class ApplicationTestingStub extends Application
{
    #[\Override]
    protected function createLibSaucer(?string $library): SaucerTestingStub
    {
        return new SaucerTestingStub();
    }

    #[\Override]
    public function run(): void
    {
        $this->poller->defer(function () {
            $this->quit();
        });

        parent::run();
    }
}
