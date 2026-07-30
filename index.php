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
use Grafida\Application\ContainerFactory;
use Grafida\Editor\MacSpellCheck;
use Grafida\FrontController;
use Grafida\Startup\FailureReporter;
use Grafida\Startup\StartupCheck;

require __DIR__ . '/vendor/autoload.php';

// The webview library Boson bundles is built for the macOS version in App::MIN_MACOS and cannot
// be loaded by anything older, where the app died inside FFI::cdef() with a PHP fatal error nobody
// ever saw (gh-58). The app bundle's LSMinimumSystemVersion stops a Finder launch with Apple's own
// alert, but the PHAR has no bundle and running the binary from a terminal bypasses LaunchServices
// — so say why, here, before we mutate any process state (the hidden console, the spell-check
// default) and before anything can throw. ⚠️ It sits ahead of the console-hiding block below only
// because it spawns nothing off macOS; a probe that runs a subprocess on Windows belongs AFTER
// that block, or it flashes a console window (see the note on it).
$startup  = new StartupCheck();
$reporter = new FailureReporter();
$tooOld   = $startup->unsupportedMacOs();

if ($tooOld !== null) {
    $reporter->report($tooOld);

    exit(StartupCheck::EXIT_UNSUPPORTED);
}

// Windows: grafida.exe runs on a console-subsystem PHP runtime (the phpmicro
// SFX is a CLI build), so Windows hands the process a console window. Grafida is
// a GUI app, so hide it immediately. Crucially, this also stops the "flashing
// CMD window on every click": the console subprocesses the backend spawns
// (registry theme probe, DPAPI secret store — see Grafida\Secret\ProcessRunner)
// INHERIT this now-hidden console instead of each allocating a fresh, visible one.
// Best-effort via FFI (already loaded for Boson); any failure just leaves the
// console as-is. Must run before the first proc_open, i.e. before the app boots.
if (\PHP_OS_FAMILY === 'Windows' && \extension_loaded('ffi')) {
    try {
        $kernel32 = \FFI::cdef('void* GetConsoleWindow(void);', 'kernel32.dll');
        $user32   = \FFI::cdef('int ShowWindow(void *hWnd, int nCmdShow);', 'user32.dll');
        $console  = $kernel32->GetConsoleWindow();

        if (!\FFI::isNull($console)) {
            $user32->ShowWindow($console, 0); // SW_HIDE
        }
    } catch (\Throwable) {
        // No console to hide, or FFI unavailable — nothing to do.
    }
}

// macOS: WKWebView gates all native spell checking on the WebContinuousSpellChecking-
// Enabled user default, read once on the first spell-check. A normal Mac app toggles it
// from its Edit ▸ Spelling menu; Boson wires up no menu bar, so the flag stays off and no
// misspelling is ever underlined (gh-24). Turn it on in Grafida's own preferences domain
// before the webview boots. Best-effort; a failure just leaves spell checking off, as before.
MacSpellCheck::enable();

$createInfo = new ApplicationCreateInfo(
    schemes: ['boson'],
    debug: (bool) filter_var(getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
    // Normally null, so Boson finds the library it ships. GRAFIDA_BOSON_LIBRARY exists because
    // that library's minimum macOS is upstream's choice, not ours (gh-58): someone who re-links
    // it for an older system can point us at their copy.
    library: $startup->libraryOverride(),
    window: new WindowCreateInfo(
        title: 'Grafida',
        width: 1280,
        height: 860,
        decoration: WindowDecoration::DarkMode,
        webview: new WebViewCreateInfo(
            // Enable the native webview context menu (gh-26). Boson leaves it off in
            // non-debug builds (WebViewCreateInfo::$contextMenu defaults to $app->isDebug),
            // but it is what carries the spell-check suggestions: Ctrl/Cmd + right-click
            // makes TinyMCE step aside so the native menu shows. With it disabled (as in a
            // release build) there is no native menu to fall back to — on Windows/WebView2
            // Ctrl+right-click did nothing at all. TinyMCE preventDefaults its own
            // plain-right-click menu, so this never produces a double menu inside the editor;
            // devTools stays debug-gated, so "Inspect element" does not appear in production.
            contextMenu: true,
            devTools: (bool) filter_var(getenv('BOSON_DEBUG'), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE),
        ),
    ),
);

// Everything native fails inside this constructor: the library load, Boson's ABI check, and the
// creation of the window and webview. So an uncaught throwable here is a missing or incompatible
// OS component — too old a macOS (gh-58), no WebKitGTK on Linux, no WebView2 runtime on Windows —
// never a Grafida bug, and PHP's own handling of it is a fatal error nobody sees because there is
// no console. Deliberately narrow: the container, the controller and $app->run() below stay
// UNWRAPPED, so a genuine application error keeps its normal, traceable behaviour. Note it must
// be \Throwable, not \Exception: FFI\Exception extends \Error. The full throwable goes to stderr
// either way, so there is nothing for a debug flag to reveal.
try {
    $app = new Application($createInfo);
} catch (\Throwable $e) {
    $reporter->report(FailureReporter::describe($e), (string) $e);

    exit(StartupCheck::EXIT_UNSUPPORTED);
}

$static = new FilesystemStaticProvider([
    __DIR__ . '/assets/private',
    __DIR__ . '/assets/public',
]);

$container = ContainerFactory::create([
    'static.provider' => $static,
    'dialog'          => $app->dialog,
]);

$controller = $container->get(FrontController::class);

$app->on(static function (SchemeRequestReceived $e) use ($controller): void {
    $e->response = $controller($e->request);
});

$app->webview->url = 'boson://app/';

$app->run();
