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
    require_once __DIR__ . '/includes/core/class-kt-access.php';
    require_once __DIR__ . '/includes/core/class-kt-logger.php';
    require_once __DIR__ . '/includes/settings/class-kt-settings.php';
    require_once __DIR__ . '/includes/admin/class-kt-admin-settings.php';
    require_once __DIR__ . '/includes/tickets/class-kt-ticket-types-cpt.php';
    require_once __DIR__ . '/includes/tickets/class-kt-ticket-types-api.php';
    require_once __DIR__ . '/includes/vendor/class-kt-vendor-events-api.php';
    require_once __DIR__ . '/includes/dokan/class-kt-dokan-dashboard.php';
    require_once __DIR__ . '/includes/woocommerce/class-kt-wc-ticket-product.php';
    require_once __DIR__ . '/includes/woocommerce/class-kt-wc-cart.php';
    require_once __DIR__ . '/includes/frontend/class-kt-ticket-cards.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-dashboard.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-api.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-print.php';

    Koopo_Tickets\DB::maybe_upgrade();
    Koopo_Tickets\Settings::init();
    Koopo_Tickets\Admin_Settings::init();
    Koopo_Tickets\Ticket_Types_CPT::init();
    Koopo_Tickets\Ticket_Types_API::init();
    Koopo_Tickets\Vendor_Events_API::init();
    Koopo_Tickets\Dokan_Dashboard::init();
    Koopo_Tickets\WC_Cart::init();
    Koopo_Tickets\Ticket_Cards::init();
    Koopo_Tickets\Customer_Tickets_Dashboard::init();
    Koopo_Tickets\Customer_Tickets_API::init();
    Koopo_Tickets\Customer_Tickets_Print::init();
  }
}

Koopo_Tickets::instance();
