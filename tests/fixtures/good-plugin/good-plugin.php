<?php
/**
 * Plugin Name:       Good Plugin
 * Plugin URI:        https://prolificdigital.com/plugins/good-plugin
 * Description:       Minimal fixture that satisfies the Prolific plugin standard.
 * Version:           1.2.3
 * Requires at least: 6.5
 * Requires PHP:      8.4
 * Author:            Prolific Digital
 * Author URI:        https://prolificdigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       good-plugin
 * Domain Path:       /languages
 * Update URI:        https://api.prolificdigital.io/api/update?plugin=good-plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'GOOD_PLUGIN_VERSION', '1.2.3' );
define( 'GOOD_PLUGIN_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/update-checker.php';
