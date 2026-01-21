<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Dokan_Dashboard {
  public static function init() {
    if (!function_exists('dokan_get_navigation_url')) return;

    add_filter('dokan_query_var_filter', [__CLASS__, 'register_query_vars']);
    add_filter('dokan_get_dashboard_nav', [__CLASS__, 'add_nav_items'], 20);
    add_action('dokan_load_custom_template', [__CLASS__, 'load_templates'], 20);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 20);
  }

  public static function register_query_vars($vars) {
    $vars[] = 'koopo-tickets';
    return $vars;
  }

  public static function add_nav_items($urls) {
    if (!Access::vendor_can_manage_tickets(get_current_user_id())) return $urls;

    $urls['tickets'] = [
      'title' => __('Tickets', 'koopo-tickets'),
      'icon' => '<i class="fas fa-ticket-alt"></i>',
      'url' => dokan_get_navigation_url('koopo-tickets'),
      'pos' => 56,
    ];

    return $urls;
  }

  public static function load_templates($query_vars) {
    if (!is_array($query_vars)) return;

    if (isset($query_vars['koopo-tickets'])) {
      self::load('tickets.php');
      return;
    }
  }

  private static function load(string $template_file): void {
    $file = KOOPO_TICKETS_PATH . 'templates/dokan/' . $template_file;
    if (file_exists($file)) include $file;
  }

  public static function enqueue_assets(): void {
    if (!function_exists('dokan_is_seller_dashboard')) return;
    if (!dokan_is_seller_dashboard()) return;

    global $wp_query;
    $is_koopo = isset($wp_query->query_vars['koopo-tickets']);
    if (!$is_koopo) return;

    wp_enqueue_style('koopo-tickets-vendor', KOOPO_TICKETS_URL . 'assets/vendor.css', [], KOOPO_TICKETS_VERSION);
    wp_enqueue_script('koopo-tickets-vendor', KOOPO_TICKETS_URL . 'assets/vendor-tickets.js', ['jquery'], KOOPO_TICKETS_VERSION, true);
    wp_enqueue_script('koopo-tickets-checkin', KOOPO_TICKETS_URL . 'assets/vendor-checkin.js', ['jquery'], KOOPO_TICKETS_VERSION, true);

    wp_localize_script('koopo-tickets-vendor', 'KOOPO_TICKETS_VENDOR', [
      'rest' => esc_url_raw(rest_url('koopo/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'userId' => get_current_user_id(),
      'events' => Vendor_Events_API::get_events_for_user(get_current_user_id()),
      'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
    ]);
  }
}
