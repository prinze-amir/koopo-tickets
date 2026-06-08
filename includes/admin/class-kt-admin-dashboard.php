<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Admin_Dashboard {
  const PAGE_SLUG = 'koopo-tickets-dashboard';

  public static function init(): void {
    add_action('admin_menu', [__CLASS__, 'register_menu']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function register_menu(): void {
    add_menu_page(
      __('Koopo Tickets', 'koopo-tickets'),
      __('Koopo Tickets', 'koopo-tickets'),
      'manage_options',
      self::PAGE_SLUG,
      [__CLASS__, 'render_page'],
      'dashicons-tickets-alt',
      56
    );
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/admin/events', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_events'],
      'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
  }

  public static function get_events(\WP_REST_Request $req): \WP_REST_Response {
    $page = max(1, absint($req->get_param('page')));
    $per_page = absint($req->get_param('per_page'));
    $search = sanitize_text_field((string) $req->get_param('search'));
    if ($per_page < 1) {
      $per_page = 50;
    }
    if ($per_page > 200) {
      $per_page = 200;
    }

    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter(array_map('sanitize_key', $types));
    if (!$types) {
      $types = ['gd_event'];
    }

    $query_args = [
      'post_type' => $types,
      'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
      'posts_per_page' => $per_page,
      'paged' => $page,
      'orderby' => 'modified',
      'order' => 'DESC',
      'fields' => 'ids',
      'no_found_rows' => false,
    ];

    $title_filter = null;
    if ($search !== '') {
      global $wpdb;
      $like = '%' . $wpdb->esc_like($search) . '%';
      $title_filter = function (string $where) use ($wpdb, $like): string {
        return $where . $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s", $like);
      };
      add_filter('posts_where', $title_filter);
    }

    $query = new \WP_Query($query_args);

    if ($title_filter) {
      remove_filter('posts_where', $title_filter);
    }

    $items = array_map([__CLASS__, 'format_event'], $query->posts);
    $response = new \WP_REST_Response($items, 200);
    $response->header('X-WP-Page', (string) $page);
    $response->header('X-WP-Per-Page', (string) $per_page);
    $response->header('X-WP-Total', (string) $query->found_posts);
    $response->header('X-WP-TotalPages', (string) $query->max_num_pages);

    return $response;
  }

  private static function format_event(int $event_id): array {
    $post = get_post($event_id);
    if (!$post) {
      return [];
    }

    $dates = WC_Cart::get_event_date_options($event_id);
    $dates = array_map(function ($entry) {
      return [
        'schedule_id' => (int) ($entry['schedule_id'] ?? 0),
        'label' => (string) ($entry['label'] ?? ''),
        'date' => (string) ($entry['date'] ?? ''),
        'time' => (string) ($entry['time'] ?? ''),
        'start_ts' => (int) ($entry['start_ts'] ?? 0),
      ];
    }, $dates);

    return [
      'id' => $event_id,
      'title' => get_the_title($event_id),
      'type' => get_post_type($event_id),
      'status' => (string) $post->post_status,
      'author' => (int) $post->post_author,
      'author_name' => get_the_author_meta('display_name', (int) $post->post_author),
      'thumbnail' => (string) get_the_post_thumbnail_url($event_id, 'medium'),
      'dates_count' => count($dates),
      'dates' => $dates,
    ];
  }

  public static function enqueue_assets(string $hook): void {
    if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
      return;
    }

    wp_enqueue_media();
    wp_enqueue_style('koopo-tickets-vendor', KOOPO_TICKETS_URL . 'assets/vendor.css', [], KOOPO_TICKETS_VERSION);
    wp_enqueue_script('koopo-tickets-vendor', KOOPO_TICKETS_URL . 'assets/vendor-tickets.js', ['jquery'], KOOPO_TICKETS_VERSION, true);

    wp_localize_script('koopo-tickets-vendor', 'KOOPO_TICKETS_VENDOR', [
      'rest' => esc_url_raw(rest_url('koopo/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'userId' => get_current_user_id(),
      'events' => [],
      'events_endpoint' => 'admin/events',
      'disable_analytics' => true,
      'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
      'events_per_page' => 12,
      'tickets_per_page' => 10,
    ]);
  }

  public static function render_page(): void {
    echo '<div class="wrap koopo-admin-tickets-page">';
    include KOOPO_TICKETS_PATH . 'templates/admin/tickets.php';
    echo '</div>';
  }
}
