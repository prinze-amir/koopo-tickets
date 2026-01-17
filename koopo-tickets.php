<?php
/**
 * Plugin Name: Koopo Tickets
 * Description: Ticketing engine for Koopo Online with WooCommerce/Dokan integration.
 * Version: 0.1.0
 * Author: Koopo
 */

defined('ABSPATH') || exit;

define('KOOPO_TICKETS_VERSION', '0.1.0');
define('KOOPO_TICKETS_PATH', plugin_dir_path(__FILE__));
define('KOOPO_TICKETS_URL', plugin_dir_url(__FILE__));

final class Koopo_Tickets {
  const VERSION = '0.1.0';
  const SLUG = 'koopo-tickets';

  private static $instance = null;

  public static function instance() {
    if (null === self::$instance) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    add_action('plugins_loaded', [$this, 'boot'], 20);
    register_activation_hook(__FILE__, [$this, 'activate']);
  }

  public function activate() {
    require_once __DIR__ . '/includes/core/class-kt-db.php';
    Koopo_Tickets\DB::create_tables();
  }

  public function boot() {
    if (!class_exists('WooCommerce')) return;

    require_once __DIR__ . '/includes/core/class-kt-db.php';
    require_once __DIR__ . '/includes/core/class-kt-logger.php';
    require_once __DIR__ . '/includes/settings/class-kt-settings.php';
    require_once __DIR__ . '/includes/admin/class-kt-admin-settings.php';

    Koopo_Tickets\DB::maybe_upgrade();
    Koopo_Tickets\Settings::init();
    Koopo_Tickets\Admin_Settings::init();
  }
}

Koopo_Tickets::instance();
