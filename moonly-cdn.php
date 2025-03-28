<?php

/**
 * Plugin Name:       Moonly CDN
 * Description:       Moonly CDN is a plugin that allows you to host your videos on the Moonly CDN.
 * Requires at least: 6.3.0
 * Requires PHP:      8.1
 * Version:           1.0.1
 * Author:            MOONLY 
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       moonly_cdn
 * Website:           
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$plugin_prefix = 'MOONLYCDN';

// Extract the version number
$plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);

// Plugin Constants
define($plugin_prefix . '_DIR', plugin_basename(__DIR__));
define($plugin_prefix . '_BASE', plugin_basename(__FILE__));
define($plugin_prefix . '_PATH', plugin_dir_path(__FILE__));
define($plugin_prefix . '_VER', $plugin_data['Version']);
define($plugin_prefix . '_CACHE_KEY', 'moonly_cdn-cache-key-for-plugin');
define($plugin_prefix . '_REMOTE_URL', 'https://viudvikler.dk/wp-content/plugins/hoster/inc/secure-download.php?file=json&download=988&token=84a880fccb79b0f1a67b8a2d98d3f4843d58f26a87b41585360831a632320b04');

require constant($plugin_prefix . '_PATH') . 'inc/update.php';
require constant($plugin_prefix . '_PATH') . 'includes/video-handler.php';

new MOONLYCDN_DPUpdateChecker(
	constant($plugin_prefix . '_BASE'),
	constant($plugin_prefix . '_VER'),
	constant($plugin_prefix . '_CACHE_KEY'),
	constant($plugin_prefix . '_REMOTE_URL'),
);

