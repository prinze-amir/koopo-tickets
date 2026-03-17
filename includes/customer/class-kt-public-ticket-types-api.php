<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Public_Ticket_Types_API {

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/public/ticket-types', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'list_ticket_types'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('koopo/v1', '/public/events/(?P<event_id>\\d+)/ticket-types', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'list_event_ticket_types'],
      'permission_callback' => '__return_true',
    ]);
  }

  public static function list_event_ticket_types(\WP_REST_Request $req) {
    $event_id = absint($req['event_id']);
    $req->set_param('event_id', $event_id);
    return self::list_ticket_types($req);
  }

  public static function list_ticket_types(\WP_REST_Request $req) {
    $event_id = absint($req->get_param('event_id'));
    $page = max(1, absint($req->get_param('page')));
    $per_page = absint($req->get_param('per_page'));
    if ($per_page < 1) {
      $per_page = 50;
    }
    if ($per_page > 200) {
      $per_page = 200;
    }

    $query_args = [
      'post_type' => Ticket_Types_CPT::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => $per_page,
      'paged' => $page,
      'orderby' => 'title',
      'order' => 'ASC',
      'fields' => 'ids',
      'no_found_rows' => false,
    ];

    if ($event_id > 0) {
      $query_args['meta_query'] = [
        [
          'key' => Ticket_Types_API::META_EVENT_ID,
          'value' => $event_id,
          'compare' => '=',
        ],
      ];
    }

    $q = new \WP_Query($query_args);

    $items = [];
    foreach ($q->posts as $ticket_type_id) {
      $item = self::format_public_ticket_type((int) $ticket_type_id);
      if (is_array($item)) {
        $items[] = $item;
      }
    }

    return new \WP_REST_Response([
      'items' => $items,
      'count' => count($items),
      'event_id' => $event_id > 0 ? $event_id : null,
      'page' => $page,
      'per_page' => $per_page,
      'total' => (int) $q->found_posts,
      'total_pages' => (int) $q->max_num_pages,
    ], 200);
  }

  private static function format_public_ticket_type(int $ticket_type_id): ?array {
    $status = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_STATUS, true);
    if ($status && 'active' !== $status) {
      return null;
    }

    $visibility = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_VISIBILITY, true);
    if ($visibility && 'public' !== $visibility) {
      return null;
    }

    $event_id = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_EVENT_ID, true);
    if ($event_id <= 0) {
      return null;
    }

    $event = get_post($event_id);
    if (!$event || 'publish' !== $event->post_status) {
      return null;
    }

    $price = (float) get_post_meta($ticket_type_id, Ticket_Types_API::META_PRICE, true);
    $capacity = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_CAPACITY, true);
    $max_per_order = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_MAX_PER_ORDER, true);
    $unlimited_capacity = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_UNLIMITED_CAPACITY, true) === 1;
    $sales_mode = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_SALES_MODE, true);
    $sales_start = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_SALES_START, true);
    $sales_end = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_SALES_END, true);
    $date_prices = get_post_meta($ticket_type_id, Ticket_Types_API::META_DATE_PRICES, true);
    $product_id = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_PRODUCT_ID, true);

    $sold_count = self::get_sold_count($ticket_type_id);
    $remaining_capacity = $unlimited_capacity ? null : max(0, $capacity - $sold_count);

    return [
      'id' => $ticket_type_id,
      'title' => get_the_title($ticket_type_id),
      'event_id' => $event_id,
      'event_title' => get_the_title($event_id),
      'event_url' => get_permalink($event_id),
      'price' => $price,
      'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
      'capacity' => $capacity,
      'sold_count' => $sold_count,
      'remaining_capacity' => $remaining_capacity,
      'is_sold_out' => !$unlimited_capacity && $remaining_capacity !== null && $remaining_capacity <= 0,
      'max_per_order' => $max_per_order,
      'sales_mode' => $sales_mode,
      'sales_start' => $sales_start,
      'sales_end' => $sales_end,
      'date_prices' => is_array($date_prices) ? $date_prices : [],
      'product_id' => $product_id,
      'author' => (int) get_post_field('post_author', $ticket_type_id),
      'status' => $status ?: 'active',
      'visibility' => $visibility ?: 'public',
    ];
  }

  private static function get_sold_count(int $ticket_type_id): int {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($table_exists !== $table) {
      return 0;
    }

    $sql = $wpdb->prepare(
      "SELECT COUNT(1) FROM {$table} WHERE ticket_type_id = %d AND status IN (%s, %s)",
      $ticket_type_id,
      'issued',
      'redeemed'
    );

    return (int) $wpdb->get_var($sql);
  }
}
