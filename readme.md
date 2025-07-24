# Post IO

WordPress plugin that syncs posts from the database onto the file system, & vice versa.

## Installation

Download the [latest release](https://github.com/BikeBearLabs/post-io/releases/latest) of the plugin & install it on your WordPress site.

## Usage

Ensure you're logged in as an admin, then navigate to a page of interest. Once the page loads, open a file browser or SSH agent on your server, & you'll see that page as a `.html` file in the `wp-content/posts` directory.

When you save the HTML file, refresh the page in your browser, & you'll see the changes reflected in your browser, & in the WordPress database.

When you save the page in the WordPress admin, refresh the page in your browser, & you'll see the changes reflected in your browser, & in the `wp-content/posts` directory.

## URL Migration

Migration of posts between domains is as simple as copying the `wp-content/posts` directory from one domain to another. Then, ensuring the `posts.json` is copied as well & deactivate & reactivate the plugin to ensure the posts' URLs are migrated.

## Future Plans

[ ] Deprecate this plugin & roll it into [Bear](https://github.com/sxxov/bear). It is currently reimplementing a lot of functionality, such as the surprisingly complex dependency crawling based on blocks. Would be great if we didn't have to do that!

## License

MIT

