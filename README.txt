=== PushPull ===
Contributors: jeromesteunenberg
Tags: git, version control, collaboration, publishing, devops
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.13
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

PushPull DevOps plugin for Wordpress

== Description ==
*A WordPress plugin to sync content with a Git repository*

Store your content in Git and manage your Wordpress instances the DevOps way!

PushPull allows you to store your contents (pages, posts, custom post types, forms, menus, configuration, blocks, patterns, etc.) into Git. This way, it is possible to deploy Wordpress the DevOps way, without manually changing content in production.

### PushPull allows you to ###

1. Push your content to Git and use your Git repository as a backup store
2. Import all your content to a new Wordpress instance
3. Have testing, development, staging and pre-production environments that mirror production
4. Collaborate on content
5. Easily instantiate new environments
6. Quickly recover from incidents on the production environment

PushPull is the missing link that allows you to manage your Wordpress contents the DevOps way.

### How does PushPull work ? ###

In Wordpress, the primary key of contents is always the ID. With PushPull, the primary key becomes the URL of the content. Everything is stored according to this URL and references to this URL in all contents are changed on the fly when stored in Git. When pulled from Git, they are restored with the ID values in the target Wordpress instance.

This means that PushPull will not necessarily work with plugins that it has not been tested with. Or at least, the information related to that plugin will not be stored effectively. Therefore, PushPull provides an extension mechanism where the 3rd party plugin developer or yourself, the user, can write code to handle the pushing and pulling of data specific to that 3rd party plugin.

== Installation ==

### Using the WordPress Dashboard ###

1. Navigate to the \'Add New\' in the plugins dashboard
2. Search for \'PushPull\'
3. Click \'Install Now\'
4. Activate the plugin on the Plugin dashboard

### Uploading in WordPress Dashboard ###

1. Download `wordpress-pushpull.zip` from the WordPress plugins repository.
2. Navigate to the \'Add New\' in the plugins dashboard
3. Navigate to the \'Upload\' area
4. Select `wordpress-pushpull.zip` from your computer
5. Click \'Install Now\'
6. Activate the plugin in the Plugin dashboard

### Installing from Source ###

Install the plugin and activate it via WordPress\'s plugin settings page.

  1. `cd wp-content/plugins`
  2. `git clone https://github.com/creativemoods/pushpull.git`
  3. `cd wordpress-pushpull && composer install`
  4. Activate the plugin in Wordpress\' Dashboard > Plugins > Installed Plugins

## Configuration ##

### With GitHub ###

Create a Fine-grained personal access token. This token should only give access to the repository that contains your contents and it only should have the following two permissions:

   1. Contents -> Access: Read and write
   2. Metadata -> Access: Read-only

### With GitLab ###

Create a token with the following permissions: api, read_api, read_user, create_runner, manage_runner, k8s_proxy, read_repository, write_repository, read_registry, write_registry, ai_features (TODO refine)

### PushPull configuration ###

1. Choose either the GitHub or GitLab provider
2. The API URL will be set automatically
3. Insert the token created in the previous step in the OAUTH TOKEN field (TODO Rename)
4. Specify you Git repository in the PROJECT field, in the form username/repository (this field must contain a slash)
5. Click on the TEST button. If your configuration is correct, the TEST button will turn green and the branch drop-down list will contain the list of branches available in your repository. Choose the branch that contains your contents and click on Save
6. Check which Worpdress post types you wish to manage with PushPull. We recommend starting with the default settings.

== Frequently Asked Questions ==
* How can I ?
test test test

## Contributing ##

Found a bug? Want to take a stab at [one of the open issues](https://github.com/creativemoods/pushpull/issues)? We'd love your help!

See [the contributing documentation](CONTRIBUTING.md) for details.
