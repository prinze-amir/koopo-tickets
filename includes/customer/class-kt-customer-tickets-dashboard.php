<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Customer_Tickets_Dashboard {
  public static function init(): void {
    add_action('init', [__CLASS__, 'add_endpoints']);
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_item'], 20);
    add_action('woocommerce_account_tickets_endpoint', [__CLASS__, 'tickets_content']);

    add_shortcode('koopo_my_tickets', [__CLASS__, 'tickets_shortcode']);

    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
  }

  public static function add_endpoints(): void {
    add_rewrite_endpoint('tickets', EP_ROOT | EP_PAGES);
  }

  public static function add_menu_item(array $items): array {
    $new_items = [];
    foreach ($items as $key => $label) {
      $new_items[$key] = $label;
      if ($key === 'orders') {
        $new_items['tickets'] = __('Tickets', 'koopo-tickets');
      }
    }

    return $new_items;
  }

  public static function tickets_content(): void {
    if (!is_user_logged_in()) {
      echo '<p>' . esc_html__('Please log in to view your tickets.', 'koopo-tickets') . '</p>';
      return;
    }

    self::load_template();
  }

  public static function tickets_shortcode($atts = []): string {
    if (!is_user_logged_in()) {
      return '<p>' . esc_html__('Please log in to view your tickets.', 'koopo-tickets') . '</p>';
    }

    ob_start();
    self::load_template();
    return ob_get_clean();
  }

  private static function load_template(): void {
    $template_path = KOOPO_TICKETS_PATH . 'templates/customer/my-tickets.php';
    if (file_exists($template_path)) {
      include $template_path;
      return;
    }

    echo '<p>' . esc_html__('Tickets template not found.', 'koopo-tickets') . '</p>';
  }

  public static function enqueue_assets(): void {
    if (!is_user_logged_in()) return;

    $load_assets = false;
    $url = wc_get_account_endpoint_url('tickets');

    if (is_account_page() && $url === home_url('/my-account-koopo/tickets/')) {
      $load_assets = true;
    }

    global $post;
    if ($post && has_shortcode($post->post_content, 'koopo_my_tickets')) {
      $load_assets = true;
    }

    if (function_exists('bp_is_user') && bp_is_user()) {
      if (function_exists('bp_current_component') && bp_current_component() === 'tickets') {
        $load_assets = true;
      }
    }

    if (!$load_assets) return;

    wp_enqueue_style('koopo-ticket-dashboard', KOOPO_TICKETS_URL . 'assets/customer-tickets.css', [], KOOPO_TICKETS_VERSION);
    wp_enqueue_script('koopo-ticket-dashboard', KOOPO_TICKETS_URL . 'assets/customer-tickets.js', ['jquery'], KOOPO_TICKETS_VERSION, true);

    wp_localize_script('koopo-ticket-dashboard', 'KOOPO_TICKETS_DASH', [
      'api_url' => rest_url('koopo/v1'),
      'nonce' => wp_create_nonce('wp_rest'),
      'i18n' => [
        'loading' => __('Loading...', 'koopo-tickets'),
        'no_tickets' => __('No tickets found yet.', 'koopo-tickets'),
        'save_success' => __('Guest details saved.', 'koopo-tickets'),
        'save_error' => __('Unable to save guest details.', 'koopo-tickets'),
        'send_success' => __('Tickets sent successfully.', 'koopo-tickets'),
        'send_error' => __('Unable to send tickets. Please try again.', 'koopo-tickets'),
      ],
    ]);
  }

  public static function flush_rewrite_rules(): void {
    self::add_endpoints();
    flush_rewrite_rules();
  }
}
