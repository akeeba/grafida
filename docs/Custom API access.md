# Custom API access for non-Super-Users

By default, Joomla only allows API access to and mints API tokens for Super Users. If you want to use a non-Super-User account with Grafida you will need to follow the guide below to configure your site and Grafida itself.

First, we need to create a special group called API Access. This will allow us to give Joomla API application access to non-Super-User accounts. This must be carried out be a Super User.

1. Log into your site as a Super User.
1. Go to Users Groups and create a new user group with the name **API Access**. Make sure the Group Parent is set to Public.
1. Go to System, Setup, Global Configuration.
1. Click the Permissions tab.
1. Click on the API Access user group.
1. Set the following permissions:
   * **Web Services Login**: Allowed.

Now, we need to tell Joomla it is allowed to mint API tokens for our API Access user group. Again, this must be carried out by a Super User.

1. Log into your site as a Super User.
2. Go to System, Manage, Plugins. 
3. Search for the "User - Joomla API Token" plugin.
4. Click on the plugin
5. In the "Allowed User Groups" section add our "API Access" user group.
6. Click on Save & Close.

You can now edit your non-Super-User user accounts who need access to Grafida and add them to the API Access user group _in addition to_ their normal user group (e.g. Author). 

Your users will now be able to log into your site, go to their profile, edit it, and enable (and see) their Joomla! API Token. They can use that token in Grafida.

## Caveats

Make sure the Public user group's permissions are all set to the value "Not Set". If you have set anything to "Denied", congratulations, you have broken Joomla's access control model and with it your site. Go fix it before doing anything else.

You must have a login module / menu iteam, and a menu item for the user's Profile or Edit Profile page on your site's frontend. This is necessary for non-Super-User accounts to be able to create and view their API Token. You CANNOT create or view API Tokens for other user accounts, even if you are a Super User; this is a security feature in Joomla itself. You can always use a "hidden" menu item to do that, and give your users the direct link to the login and profile edit pages on the public site even though these URLs are not linked to from any page of your site. 

Your actions are limited by the user groups your user belongs to _in addition to_ the API Access user group. Authors, for example, cannot change the publish status of an article; only Publishers and above can. Authors cannot edit an existing article either; clicking Grafida's Publish button on a draft of an article already published on the site will result in an Access Denied error toast. This means that you will perceive some features in Grafida "not working". They work fine; Joomla simply denied you access because your Permissions do not allow what you're trying to do. We cannot prevent the display of these features; the Joomla API does not return adequate information for us to determine your access to the site at the granular level required to decide which features to hide.

Grafida cannot get the Unicode Aliases setting of your site. The value of this setting is only available to Super Users. As a result, you will have to edit the article alias manually if you're using non-ASCII characters in the title; in any other case, you'd end up with an alias that's partial, or nonsensical. This is not a bug, it's a limitation in Joomla itself.
