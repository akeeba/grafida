# Requirements

- A Joomla **4.0 or later** site with the Web Services API enabled, and an API token for an account
  authorised for API access. Super Users work by default. Non-Super-User accounts can be configured
  for Grafida; see [Custom API access](https://grafida.app/docs/desktop/Custom-API-Access.html).
- To run a pre-built release: **macOS 15 Sequoia+**, **Windows 10+** (with the Microsoft Edge
  WebView2 Runtime, which ships with Windows 11 and current Windows 10), or **Linux** with GTK4
  and WebKitGTK 6.0 (`libgtk-4-1`, `libwebkitgtk-6.0-4`). The macOS floor is set by the prebuilt
  Boson webview library, which is linked against a macOS 15 SDK and cannot be loaded by macOS 14
  Sonoma or earlier ([gh-58](https://github.com/grafida/grafida/issues/58)).
