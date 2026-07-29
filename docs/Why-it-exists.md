# Why does it exist?

Joomla! is an excellent Content _Management_ System. However, its plethora of content management features has made content authoring in the Joomla article edit page fairly painful. This is not a complaint; power comes with complexity, and we – the Joomla community – chose power over simplicity.

Inexperienced users find the tiny content area surrounded by a swarm of inscrutable options utterly bewildering. Experienced users find the tiny content area unusable, having to use external tools for authoring, resulting in excessive copying and pasting. Nobody's happy – which is why we see the overuse (and abuse) of page builders on sites that can't have possibly benefited from using one. They put content first.

You know what else puts content first? Desktop content authoring applications made for WordPress. Remember Windows Live Writer? MarsEdit? What if we could bring that to Joomla? After all, modern Joomla has a rich API which should allow making that possible.

## Focused content authoring

The driving force behind Grafida is making _content authoring_ simple but powerful. A title. A big content area. Sit down and work on your words. You need some AI assistance to elucidate your point? Configure it once, and it's right there when you need it. It's like a word processor, but it “speaks” the native data type of your CMS. When you're done, you hit Publish. Spotted a problem? Fix it, hit Publish again. No fuss, no mess, no copy-pasting shenanigans, no stress.

You need to _manage_ your content? Log into your site with your browser. Manage your content with the plethora of options Joomla and its extensions give you. Tell Joomla how to show the article page. Who can edit it. Add OpenGraph images. Work your SEO magic. You don't really need to touch the content for most, if not all, of that anyway.

Content authoring and content management are not mutually exclusive. _They work together_. Any changes you made in Joomla can be brought back to Grafida for further editing and published back into Joomla without messing up your content management options.

The goal is to use Grafida for distraction-free content authoring, and Joomla itself for content management.

## Out-of-scope features

Grafida is not your Joomla article editor page wrapped in a web view. It is a standalone desktop application, designed to work off-line. Between this fundamental design choice and Joomla's architecture, there are several features and interactions with core and third party extensions which are explicitly out-of-scope of this project.

Indicatively:

* **Editor buttons** (typically provided by `editors-xtd` plugins). Not exposed through the API. Rendered server-side by Joomla. Many interact with Joomla extensions in the front- or backend. Cannot implement outside Joomla.
* **Live preview on your site**. Cannot implement outside Joomla. If you're wondering why, look at its core code. It's a clever hack, but it cannot be replicated externally 😊
* **Joomla Fields**. Only a small subset of core field types (calendar, checkboxes, color, integer, list, radio, text, textarea, url) are supported. Other core field types require server-side rendering. Field types provided by third party plugins are explicitly unsupported as we have no way of knowing how they are supposed to be rendered. You need to edit those fields in the backend of your site.
* **Content plugins**. “Plugin codes” such as `{loadmodule 123}` will be rendered as plain text in the preview. Cannot be addressed. These are rendered server-side by Joomla itself using the `onContentPrepare` plugin event which cannot be accessed over the API.
* **Plugins rendering additional editor fields or tabs**. Some system, content, etc plugins implement additional fields or editors tab. For example, what we do in SocialMagick and AITiny. This hinges on server-side plugin events which are not exposed by the API. Even if that wasn't the case, many fields rely on server-side rendering, or make assumptions about running in the Joomla's backend with a known CSS and JavaScript framework which is not the case for Grafida.
* **Article Permissions**. This data is not exposed by the API.
* **Workflows**. Beyond the fact this relies on server-side rendering, we consider this a management –not content authoring– feature, making it explicitly out-of-scope for this project.
* **The full collection of the Joomla article edit page fields**. These fields are not exposed through the API. They depend on your Joomla version and access level, therefore we can't “fake it” by doing our own implementation either.
* **Joomla Media Manager**. Grafida does not actually show you Joomla's Media Manager. It shows you its own, cut-down media manager using the media information it receives over the API. It is intentionally kept simple. This is a design choice which won't change; we are not interested in creating a full-blown media manager!
* **Third party media managers**. Third party media managers are rendered server-side inside Joomla itself. We cannot even access them through the API. Even if we could, it would require creating an explicit implementation for each make and version of third party media manager, making this thoroughly impractical.
* **Third party content extensions** such as Page Builders, CCKs, etc. They replace Joomla's content management completely, and / or use undocumented data structures which are heavily dependent on the make and version of the third party extension you are using. You would need a separate implementation of each page builder / CCK inside Grafida which is both impractical and morally questionable (it would amount to “stealing” other people's software).
