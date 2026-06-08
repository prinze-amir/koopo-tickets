<?php
/**
 * Plugin Name: Koopo Tickets
 * Description: Ticketing engine for Koopo Online with WooCommerce/Dokan integration.
 * Version: 0.2.6
 * Author: Koopo
 */

defined('ABSPATH') || exit;

define('KOOPO_TICKETS_VERSION', '0.2.6');
define('KOOPO_TICKETS_PATH', plugin_dir_path(__FILE__));
define('KOOPO_TICKETS_URL', plugin_dir_url(__FILE__));

final class Koopo_Tickets {
  const VERSION = '0.2.6';
  const SLUG = 'koopo-tickets';

  private static $instance = null;

  public static function instance() {
    if (null === self::$instance) self::$instance = new self();
    return self::$instance;
  }

  private function __construct() {
    add_action('before_woocommerce_init', [$this, 'declare_woocommerce_compatibility']);
    add_action('plugins_loaded', [$this, 'boot'], 20);
    register_activation_hook(__FILE__, [$this, 'activate']);
    register_deactivation_hook(__FILE__, [$this, 'deactivate']);
  }

  public function activate() {
    require_once __DIR__ . '/includes/core/class-kt-db.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-dashboard.php';
    Koopo_Tickets\DB::create_tables();
    Koopo_Tickets\Customer_Tickets_Dashboard::flush_rewrite_rules();
  }

  public function deactivate() {
    flush_rewrite_rules();
  }

  public function declare_woocommerce_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
  }

  public function boot() {
    if (!class_exists('WooCommerce')) return;

    require_once __DIR__ . '/includes/core/class-kt-db.php';
    require_once __DIR__ . '/includes/core/class-kt-access.php';
    require_once __DIR__ . '/includes/core/class-kt-author-sync.php';
    require_once __DIR__ . '/includes/core/class-kt-logger.php';
    require_once __DIR__ . '/includes/settings/class-kt-settings.php';
    require_once __DIR__ . '/includes/admin/class-kt-admin-dashboard.php';
    require_once __DIR__ . '/includes/admin/class-kt-admin-settings.php';
    require_once __DIR__ . '/includes/tickets/class-kt-ticket-types-cpt.php';
    require_once __DIR__ . '/includes/tickets/class-kt-ticket-types-api.php';
    require_once __DIR__ . '/includes/tickets/class-kt-ticket-instances.php';
    require_once __DIR__ . '/includes/tickets/class-kt-ticket-validation.php';
    require_once __DIR__ . '/includes/vendor/class-kt-vendor-events-api.php';
    require_once __DIR__ . '/includes/dokan/class-kt-dokan-dashboard.php';
    require_once __DIR__ . '/includes/woocommerce/class-kt-wc-ticket-product.php';
    require_once __DIR__ . '/includes/woocommerce/class-kt-wc-cart.php';
    require_once __DIR__ . '/includes/woocommerce/class-kt-wc-order-tickets.php';
    require_once __DIR__ . '/includes/frontend/class-kt-ticket-cards.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-dashboard.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-ticket-transfers.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-api.php';
    require_once __DIR__ . '/includes/customer/class-kt-customer-tickets-print.php';
    require_once __DIR__ . '/includes/customer/class-kt-public-ticket-types-api.php';
    if (defined('WP_CLI') && WP_CLI) {
      require_once __DIR__ . '/includes/cli/class-kt-cli-tests.php';
    }

    Koopo_Tickets\DB::maybe_upgrade();
    Koopo_Tickets\Settings::init();
    Koopo_Tickets\Author_Sync::init();
    Koopo_Tickets\Admin_Dashboard::init();
    Koopo_Tickets\Admin_Settings::init();
    Koopo_Tickets\Ticket_Types_CPT::init();
    Koopo_Tickets\Ticket_Types_API::init();
    Koopo_Tickets\Ticket_Instances::init();
    Koopo_Tickets\Ticket_Validation::init();
    Koopo_Tickets\Vendor_Events_API::init();
    Koopo_Tickets\Dokan_Dashboard::init();
    Koopo_Tickets\WC_Cart::init();
    Koopo_Tickets\WC_Order_Tickets::init();
    Koopo_Tickets\Ticket_Cards::init();
    Koopo_Tickets\Customer_Tickets_Dashboard::init();
    Koopo_Tickets\Customer_Ticket_Transfers::init();
    Koopo_Tickets\Customer_Tickets_API::init();
    Koopo_Tickets\Customer_Tickets_Print::init();
    Koopo_Tickets\Public_Ticket_Types_API::init();
    if (defined('WP_CLI') && WP_CLI) {
      Koopo_Tickets\CLI_Tests::init();
    }
  }
}

Koopo_Tickets::instance();
