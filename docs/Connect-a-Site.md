# Connect a site

Grafida talks to your Joomla! site using the Joomla Web Services (REST) API. You need to create an API token on your Joomla! site, then configure Grafida to use it.

You can connect multiple sites to Grafida by repeating this process for each site.

## Preparing the Joomla site

> [!IMPORTANT]
> If you are not a Super User on the site you are connecting to, please ask a Super User to follow the instructions in this documentation section _as well as_ those in the [Custom API Access](Custom-API-Access).

Make sure the necessary plugins are published on your site.

* Log into your site's backend (`administrator`) as a Super User.
* From the sidebar, click on System
* Find the Manage panel and click on its Plugins item.
* Look for the following plugins and make sure they are published:
    * **User - Joomla API Token** (Type: `user`, Element: `token`)
    * **API Authentication - Web Services Joomla Token** (Type: `api-authentication`, Element: `token`)
    * **Web Services - Config** (Type: `webservices`, Element: `config`)
    * **Web Services - Content** (Type: `webservices`, Element: `content`)
    * **Web Services - Languages** (Type: `webservices`, Element: `languages`)
    * **Web Services - Media** (Type: `webservices`, Element: `media`)
    * **Web Services - Tags** (Type: `webservices`, Element: `tags`)
    * **Web Services - Templates** (Type: `webservices`, Element: `templates`)
    * **Web Services - Users** (Type: `webservices`, Element: `users`)

## Get the Joomla API token

If you are a Super User:

* Log into your site's backend (`administrator`).
* Click on the User Menu drop-down in the top right corner.
* Click on Edit Account.
* Click on the Joomla API Token tab.
* Set Active to Yes
* Click on Save
* You now see a Token field. Copy its contents. This is your personal Joomla API token. _Keep it safe_. It is your username and password all rolled into one, and it bypasses Multi-factor Authentication (MFA). We recommend keeping it in your password manager. Grafida keeps its own copy in your operating system's secret store, not in its database — see [Secrets security](Secrets-Security).
* Click on the Close button to dismiss this page.

If you are not a Super User, you need to get your API token after logging into the site's frontend and going to your user account's Edit Profile page. This is site-specific. If you're unsure how to get there, please ask your site's Super User.

Then, you need to do this:

* Find the Joomla API Token heading.
* Set Active to Yes under the Joomla API Token
* Click on Save
* The page should reload. If not, go back to the Profile Edit page.
* You now see a Token field under the Joomla API Token heading. Copy its contents. This is your personal Joomla API token.

## Set up a site in Grafida

* Click Sites on the sidebar.
* Click on the “+ Add Site” button.
  * Title: A (short) title that makes sense to you, e.g. “Acme Blog”.
  * Site URL: The site's base URL, e.g. `https://www.example.com`. See the Gotchas below.
  * API Token: your personal Joomla API Token. See the section above.
  * Editor CSS URL: Leave it blank. See the Gotchas below.
* Click on Test Connection. It should come back with a small green success message.

**Gotchas**

If you're using a multi-language site, you will see a browser URL with the language code in it such as `https://www.example.com/en` or `https://www.example.com/index.php/en`. Do not include the language code in the Site URL field. 

The Editor CSS URL field is optional. If you are not a Super User and / or your site is using a non-standard path for its template's media files **AND** you want your editor in Grafida to use the same text styling used across your site you will need to enter the relative path to the `editor.css` or equivalent file on your site, e.g. `media/custom_stuff/editor.css`. You can read more about this file and how it's used in Joomla co-founder [Brian Teeman's blog](https://brian.teeman.net/joomla/876-improve-the-joomla-content-editor).
