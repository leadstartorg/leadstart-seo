<?php
/**
 * Plugin Name: Leadstart SEO
 * Description: SEO, search engine indexing, schema, GEO, analytics and Content AI for WordPress.
 * Version: 1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Leadstart
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: leadstart-seo
 */
namespace Leadstart\SEO;
if (!defined('ABSPATH')) { exit; }
const VERSION = '1.0';
const FILE = __FILE__;
foreach (['config','api','schema','seo','geo','queue','reports','ai','admin'] as $file) { require_once __DIR__ . '/includes/' . $file . '.php'; }
add_action('plugins_loaded', static function () { Admin::boot(); SEO::boot(); GEO::boot(); Queue::boot(); });
register_activation_hook(__FILE__, static function ($network) {
    if ($network) { wp_die(esc_html__('Activate Leadstart SEO per site, not network-wide.', 'leadstart-seo')); }
    if (!wp_next_scheduled('lst_seo_tick')) { wp_schedule_event(time()+60, 'hourly', 'lst_seo_tick'); }
});
register_deactivation_hook(__FILE__, static function () { GEO::clear_cache(); wp_clear_scheduled_hook('lst_seo_tick'); wp_clear_scheduled_hook('lst_seo_run'); });
