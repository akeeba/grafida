# Key Features

- **Multiple sites**. Connect to several Joomla sites. You can even publish the same content on multiple sites without leaving the editor screen.
- **Rich editing**. Uses the same TinyMCE editor as Joomla, it has full support for Joomla's “Read More”, and it's styled with your site template's `editor.css`.
- **AI assistance on your terms**. Connect to an inference provider, or a local inference app (e.g. LM Studio) to get AI-powered assistance writing and editing your content. Keeps track of the discussions. Completely optional – you won't even see it if you don't configure it.
- **Categories, tags, and access levels**. Picked from live, cached site data; new tags are created automatically on publish.
- **Joomla Fields (partial support)**. Edit the supported core field types (`calendar`, `checkboxes`, `color`, `integer`, `list`, `radio`, `text`, `textarea`, `url`). The app warns you when a required field uses a type only Joomla's backend can edit (and offers the article HTML to copy).
- **Media**. Pick and upload to the Joomla Media Manager; images added offline are stored locally and uploaded automatically on publish.
- **Markdown import**. Import a Markdown file to HTML in one click.
- **Offline drafts**. Everything is saved locally in SQLite; publishing is a deliberate action.
- **Speaks your language**. English (en-GB; canonical language) plus machine-translated into Greek, French, German, Spanish, Italian, and Portuguese. Automatic Operating System language detection and a manual override. Uses standard Joomla INI language files for easier translation.
- **Dark Mode**. Never again will you burn your retinas writing a blog post at night. Detect the Operating System's setting, lets you override it.
- **Plays nice with your OS**. Application storage and configuration is stored in the OS-prescribed locations. The app tells you exactly where that is.
- **Security first**. API tokens are stored in your OS secret store (macOS Keychain, Windows DPAPI, Linux libsecret), never in plaintext unless you explicitly opt in.
