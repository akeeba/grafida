<?php

declare(strict_types=1);

namespace Grafida\Tests\Integration\Stub;

use Boson\Component\Saucer\SaucerTestingStub as BaseStub;
use Boson\WebView\Api\LifecycleEvents\LifecycleEventsListener;
use Boson\Window\Internal\SaucerWindowEventHandler;
use FFI\CData;

/**
 * @internal for testing purpose only
 */
class SaucerTestingStub extends BaseStub
{
    public function __construct()
    {
        $this->addDefaultMethod('cast', fn(string $t, CData $ptr) => $ptr);
        $this->addDefaultMethod('new', $this->createStruct(...));

        $this->addDefaultMethod('saucer_options_new', fn(mixed ...$args): CData
            => $this->createStruct('saucer_options_new', $args));

        $this->addDefaultMethod('saucer_preferences_new', fn(mixed ...$args): CData
            => $this->createStruct('saucer_preferences_new', $args));

        $this->addDefaultMethod('saucer_desktop_new', fn(mixed ...$args): CData
            => $this->createStruct('saucer_desktop_new', $args));

        $this->addDefaultMethod('saucer_application_init', fn(mixed ...$args): CData
            => $this->createStruct('saucer_application_init', $args));

        $this->addDefaultMethod('saucer_new', fn(mixed ...$args): CData
            => $this->createStruct('saucer_new', $args));

        $this->addDefaultMethod('saucer_script_new', fn(mixed ...$args): CData
            => $this->createStruct('saucer_script_new', $args));

        $this->addDefaultMethod('saucer_preferences_set_persistent_cookies');
        $this->addDefaultMethod('saucer_preferences_add_browser_flag');

        $this->addDefaultMethod('saucer_application_run_once');

        $this->addDefaultMethod('saucer_window_set_size');
        $this->addDefaultMethod('saucer_window_set_decorations');
        $this->addDefaultMethod('saucer_window_on');
        $this->addDefaultMethod('saucer_window_show');

        $this->addDefaultMethod('saucer_webview_set_context_menu');
        $this->addDefaultMethod('saucer_webview_set_dev_tools');
        $this->addDefaultMethod('saucer_webview_inject');
        $this->addDefaultMethod('saucer_webview_on');
        $this->addDefaultMethod('saucer_webview_on_message');
        $this->addDefaultMethod('saucer_webview_background');
        $this->addDefaultMethod('saucer_webview_force_dark_mode');
        $this->addDefaultMethod('saucer_webview_set_background');

        $this->addDefaultMethod('saucer_script_set_permanent');

        // cleanup

        $this->addDefaultMethod('saucer_free');
        $this->addDefaultMethod('saucer_desktop_free');
        $this->addDefaultMethod('saucer_preferences_free');
        $this->addDefaultMethod('saucer_options_free');
        $this->addDefaultMethod('saucer_script_free');
        $this->addDefaultMethod('saucer_application_free');

        $this->addDefaultMethod('saucer_webview_clear_scripts');
        $this->addDefaultMethod('saucer_webview_clear_embedded');

        $this->addDefaultMethod('saucer_application_quit');
    }

    /**
     * @param non-empty-string $type
     * @param array<array-key, mixed> $args
     */
    protected function createStruct(string $type, array $args = []): CData
    {
        return match (true) {
            $this->isWebViewEventsStruct($type) => \FFI::cdef(<<<'C'
                    typedef void* saucer_handle;
                    typedef void* saucer_navigation;
                    typedef void* saucer_icon;

                    typedef int32_t SAUCER_POLICY;
                    typedef int32_t SAUCER_STATE;
                    C)
                ->new($type),
            $this->isWindowEventsStruct($type) => \FFI::cdef(<<<'C'
                    typedef void* saucer_handle;

                    typedef int32_t SAUCER_POLICY;
                    C)
                ->new($type),
            default => \FFI::cdef()
                ->new('int64_t'),
        };
    }

    private function isWebViewEventsStruct(string $type): bool
    {
        return new \ReflectionClassConstant(LifecycleEventsListener::class, 'WEBVIEW_HANDLER_STRUCT')
                ->getValue() === $type;
    }

    private function isWindowEventsStruct(string $type): bool
    {
        return new \ReflectionClassConstant(SaucerWindowEventHandler::class, 'WINDOW_HANDLER_STRUCT')
                ->getValue() === $type;
    }
}
