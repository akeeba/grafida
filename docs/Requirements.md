# Requirements

## Desktop

Grafida supports Windows 10/11, macOS 15 Sequoia or later, and Linux with GTK 4 and WebKitGTK 6.0 (tested on Arch Linux with the KDE Plasma desktop). It has minimal memory and CPU requirements; if you can run a lightweight browser, you can run Grafida.

> [!IMPORTANT]
> **On a Mac, macOS 15 Sequoia is the minimum.** The system webview component Grafida is built on cannot be loaded by macOS 14 Sonoma or anything earlier — on those systems Grafida refuses to start and tells you so. There is no workaround; macOS has to be updated.

On Linux you need the GTK 4 and WebKitGTK 6.0 libraries — on Debian and Ubuntu the `libgtk-4-1` and `libwebkitgtk-6.0-4` packages, `gtk4` and `webkitgtk6.0` on Fedora, `gtk4` and `webkitgtk-6.0` on Arch. A distribution released from 2022 onwards is new enough; older ones lack the system libraries the application is built against.

On Windows you need the Microsoft Edge WebView2 Runtime. It is part of Windows 11 and of current Windows 10 installations, so you almost certainly have it already; if Grafida tells you it is missing, [install it from Microsoft](https://developer.microsoft.com/microsoft-edge/webview2/).

> [!IMPORTANT]
> This application may not work on Windows 11 Home with Smart App Control (SAC) enabled due to a limitation on Microsoft's side. You may try [disabling SAC](https://www.elevenforum.com/t/turn-on-or-off-smart-app-control-in-windows-11.4996/). For more information, please read the project's [README](https://github.com/akeeba/grafida/#readme).

## Server

On the server side, you need a Joomla **5.4, or 6.x** site, and a Joomla API token for a user account that is allowed to use these features. Super User accounts can do this out of the box. For anything else, please read the [Custom API access](Custom-API-Access) page.
