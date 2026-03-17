<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Vendor_Events_API {
  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/vendor/events', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_my_events'],
      'permission_callback' => fn() => Access::vendor_can_manage_tickets(),
    ]);

    register_rest_route('koopo/v1', '/vendor/ticket-analytics', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_ticket_analytics'],
      'permission_callback' => fn() => Access::vendor_can_manage_tickets(),
    ]);
  }

  public static function get_my_events(\WP_REST_Request $req) {
    $user_id = get_current_user_id();
    if (!$user_id) return new \WP_REST_Response([], 200);

    $page = max(1, absint($req->get_param('page')));
    $per_page = absint($req->get_param('per_page'));
    if ($per_page < 1) {
      $per_page = 50;
    }
    if ($per_page > 200) {
      $per_page = 200;
    }

    $result = self::query_events($user_id, $page, $per_page, true);
    $response = new \WP_REST_Response($result['items'], 200);
    $response->header('X-WP-Page', (string) $page);
    $response->header('X-WP-Per-Page', (string) $per_page);
    $response->header('X-WP-Total', (string) ($result['total'] ?? 0));
    $response->header('X-WP-TotalPages', (string) ($result['total_pages'] ?? 0));

    return $response;
  }

  public static function get_ticket_analytics(\WP_REST_Request $req) {
    $user_id = get_current_user_id();
    if (!$user_id) {
      return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
    }

    $event_id = absint($req->get_param('event_id'));
    if ($event_id) {
      [$ok, $event_or_response] = self::assert_event_access($event_id, $user_id);
      if (!$ok) {
        return $event_or_response;
      }
    }

    $refresh = !empty($req->get_param('refresh'));
    $cache_ttl = (int) apply_filters('koopo_tickets_analytics_cache_ttl', 120, $user_id, $event_id);
    $cache_key = self::analytics_cache_key($user_id, $event_id);
    if (!$refresh && $cache_ttl > 0) {
      $cached = get_transient($cache_key);
      if (is_array($cached)) {
        return new \WP_REST_Response($cached, 200);
      }
    }

    $stats = self::calculate_ticket_analytics($user_id, $event_id);
    if ($cache_ttl > 0) {
      set_transient($cache_key, $stats, $cache_ttl);
    }
    return new \WP_REST_Response($stats, 200);
  }

  public static function get_events_for_user(int $user_id): array {
    if (!$user_id) return [];
    return self::query_events($user_id, 1, 200, false);
  }

  private static function assert_event_access(int $event_id, int $user_id): array {
    if (!$event_id) {
      return [false, new \WP_REST_Response(['error' => 'event_id is required'], 400)];
    }

    $event = get_post($event_id);
    if (!$event) {
      return [false, new \WP_REST_Response(['error' => 'Event not found'], 404)];
    }

    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter(array_map('sanitize_key', $types));
    if (!$types) {
      $types = ['gd_event'];
    }

    if (!in_array($event->post_type, $types, true)) {
      return [false, new \WP_REST_Response(['error' => 'Invalid event type'], 400)];
    }

    if (!Access::is_admin_bypass() && (int) $event->post_author !== $user_id) {
      return [false, new \WP_REST_Response(['error' => 'Forbidden'], 403)];
    }

    return [true, $event];
  }

  private static function calculate_ticket_analytics(int $user_id, int $event_id = 0): array {
    global $wpdb;

    $tickets_table = $wpdb->prefix . 'koopo_tickets';
    $base_where = "tt.post_author = %d AND tt.post_type = %s";
    $where_args = [$user_id, Ticket_Types_CPT::POST_TYPE];

    if ($event_id > 0) {
      $base_where .= " AND t.event_id = %d";
      $where_args[] = $event_id;
    }

    $sold_count_sql = "SELECT COUNT(1)
      FROM {$tickets_table} t
      INNER JOIN {$wpdb->posts} tt ON tt.ID = t.ticket_type_id
      WHERE {$base_where}";
    $tickets_sold = (int) $wpdb->get_var($wpdb->prepare($sold_count_sql, $where_args));

    $item_totals_sql = "SELECT ti.event_id, ti.order_id, ti.order_item_id,
        MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS line_total,
        MAX(CASE WHEN oim.meta_key = '_line_tax' THEN oim.meta_value END) AS line_tax
      FROM (
        SELECT t.event_id, t.order_id, t.order_item_id
        FROM {$tickets_table} t
        INNER JOIN {$wpdb->posts} tt ON tt.ID = t.ticket_type_id
        WHERE {$base_where}
        GROUP BY t.event_id, t.order_id, t.order_item_id
      ) ti
      LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
        ON oim.order_item_id = ti.order_item_id
        AND oim.meta_key IN ('_line_total', '_line_tax')
      GROUP BY ti.event_id, ti.order_id, ti.order_item_id";
    $item_totals = $wpdb->get_results($wpdb->prepare($item_totals_sql, $where_args), ARRAY_A);

    if (!$item_totals) {
      return [
        'tickets_sold' => $tickets_sold,
        'orders_count' => 0,
        'gross_sales' => 0.0,
        'commission_fees' => 0.0,
        'stripe_fees' => 0.0,
        'net_sales' => 0.0,
        'event_breakdown' => [],
      ];
    }

    $order_ticket_gross = [];
    $order_event_gross = [];
    $event_gross = [];
    foreach ($item_totals as $row) {
      $order_id = absint($row['order_id'] ?? 0);
      $event_id_for_row = absint($row['event_id'] ?? 0);
      if (!$order_id) {
        continue;
      }

      $line_total = (float) ($row['line_total'] ?? 0);
      $line_tax = (float) ($row['line_tax'] ?? 0);
      $gross = max(0, $line_total + $line_tax);

      if (!isset($order_ticket_gross[$order_id])) {
        $order_ticket_gross[$order_id] = 0.0;
      }
      if (!isset($order_event_gross[$order_id])) {
        $order_event_gross[$order_id] = [];
      }

      $order_ticket_gross[$order_id] += $gross;
      if ($event_id_for_row > 0) {
        if (!isset($order_event_gross[$order_id][$event_id_for_row])) {
          $order_event_gross[$order_id][$event_id_for_row] = 0.0;
        }
        if (!isset($event_gross[$event_id_for_row])) {
          $event_gross[$event_id_for_row] = 0.0;
        }
        $order_event_gross[$order_id][$event_id_for_row] += $gross;
        $event_gross[$event_id_for_row] += $gross;
      }
    }

    $order_ids = array_map('intval', array_keys($order_ticket_gross));
    if (!$order_ids) {
      return [
        'tickets_sold' => $tickets_sold,
        'orders_count' => 0,
        'gross_sales' => 0.0,
        'commission_fees' => 0.0,
        'stripe_fees' => 0.0,
        'net_sales' => 0.0,
        'event_breakdown' => [],
      ];
    }

    $gross_sales = array_sum($order_ticket_gross);
    $commission_fees = 0.0;
    $stripe_fees = 0.0;
    $event_commission_fees = [];
    $event_stripe_fees = [];

    $dokan_rows = [];
    $dokan_table = $wpdb->prefix . 'dokan_orders';
    $dokan_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $dokan_table));
    if ($dokan_table_exists === $dokan_table) {
      $order_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
      $dokan_sql = "SELECT order_id, order_total, net_amount
        FROM {$dokan_table}
        WHERE seller_id = %d
          AND order_id IN ({$order_placeholders})";
      $dokan_args = array_merge([$user_id], $order_ids);
      $raw_dokan_rows = $wpdb->get_results($wpdb->prepare($dokan_sql, $dokan_args), ARRAY_A);
      foreach ($raw_dokan_rows as $row) {
        $dokan_rows[(int) $row['order_id']] = [
          'order_total' => (float) ($row['order_total'] ?? 0),
          'net_amount' => (float) ($row['net_amount'] ?? 0),
        ];
      }
    }

    $gateway_meta = [];
    $meta_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
    $meta_sql = "SELECT post_id, meta_key, meta_value
      FROM {$wpdb->postmeta}
      WHERE post_id IN ({$meta_placeholders})
        AND meta_key IN ('dokan_gateway_stripe_fee', 'dokan_gateway_fee', 'dokan_admin_gateway_fee')";
    $meta_rows = $wpdb->get_results($wpdb->prepare($meta_sql, $order_ids), ARRAY_A);
    foreach ($meta_rows as $row) {
      $order_id = (int) ($row['post_id'] ?? 0);
      if (!$order_id) {
        continue;
      }

      if (!isset($gateway_meta[$order_id])) {
        $gateway_meta[$order_id] = [
          'stripe' => 0.0,
          'gateway' => 0.0,
          'admin_gateway' => 0.0,
        ];
      }

      $value = (float) ($row['meta_value'] ?? 0);
      if ($row['meta_key'] === 'dokan_gateway_stripe_fee') {
        $gateway_meta[$order_id]['stripe'] = abs($value);
      } elseif ($row['meta_key'] === 'dokan_gateway_fee') {
        $gateway_meta[$order_id]['gateway'] = abs($value);
      } elseif ($row['meta_key'] === 'dokan_admin_gateway_fee') {
        $gateway_meta[$order_id]['admin_gateway'] = abs($value);
      }
    }

    foreach ($order_ticket_gross as $order_id => $ticket_gross_for_order) {
      $order_total = $ticket_gross_for_order;
      $net_amount = $ticket_gross_for_order;

      if (isset($dokan_rows[$order_id])) {
        $order_total = (float) $dokan_rows[$order_id]['order_total'];
        $net_amount = (float) $dokan_rows[$order_id]['net_amount'];
      }

      $gateway_for_order = 0.0;
      if (isset($gateway_meta[$order_id])) {
        $gateway_row = $gateway_meta[$order_id];
        $base_gateway = $gateway_row['stripe'] > 0 ? $gateway_row['stripe'] : $gateway_row['gateway'];
        $gateway_for_order = $base_gateway + (float) $gateway_row['admin_gateway'];
      }

      $share = $order_total > 0 ? min(1, $ticket_gross_for_order / $order_total) : 1;
      $commission_for_order = max(0, $order_total - $net_amount - $gateway_for_order);

      $commission_fees += $commission_for_order * $share;
      $stripe_fees += $gateway_for_order * $share;

      if (!empty($order_event_gross[$order_id])) {
        foreach ($order_event_gross[$order_id] as $event_key => $event_order_gross) {
          $event_share = $order_total > 0 ? min(1, $event_order_gross / $order_total) : 1;
          if (!isset($event_commission_fees[$event_key])) {
            $event_commission_fees[$event_key] = 0.0;
          }
          if (!isset($event_stripe_fees[$event_key])) {
            $event_stripe_fees[$event_key] = 0.0;
          }
          $event_commission_fees[$event_key] += $commission_for_order * $event_share;
          $event_stripe_fees[$event_key] += $gateway_for_order * $event_share;
        }
      }
    }

    $net_sales = $gross_sales - $commission_fees - $stripe_fees;
    if ($net_sales < 0) {
      $net_sales = 0;
    }

    arsort($event_gross);
    $event_breakdown = [];
    foreach ($event_gross as $event_key => $event_gross_sales) {
      if ($event_gross_sales <= 0) {
        continue;
      }
      $event_fee_total = (float) ($event_commission_fees[$event_key] ?? 0) + (float) ($event_stripe_fees[$event_key] ?? 0);
      $event_net_sales = $event_gross_sales - $event_fee_total;
      if ($event_net_sales < 0) {
        $event_net_sales = 0;
      }
      $event_breakdown[] = [
        'event_id' => (int) $event_key,
        'event_title' => (string) get_the_title((int) $event_key),
        'gross_sales' => round((float) $event_gross_sales, 2),
        'net_sales' => round((float) $event_net_sales, 2),
      ];
    }

    return [
      'tickets_sold' => $tickets_sold,
      'orders_count' => count($order_ids),
      'gross_sales' => round($gross_sales, 2),
      'commission_fees' => round($commission_fees, 2),
      'stripe_fees' => round($stripe_fees, 2),
      'net_sales' => round($net_sales, 2),
      'event_breakdown' => $event_breakdown,
    ];
  }

  private static function analytics_cache_key(int $user_id, int $event_id = 0): string {
    return 'koopo_tickets_analytics_' . md5($user_id . ':' . $event_id);
  }

  private static function query_events(int $user_id, int $page = 1, int $per_page = 200, bool $with_totals = false): array {
    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter(array_map('sanitize_key', $types));
    if (!$types) $types = ['gd_event'];

    $q = new \WP_Query([
      'post_type' => $types,
      'post_status' => 'publish',
      'author' => $user_id,
      'posts_per_page' => $per_page,
      'paged' => $page,
      'orderby' => 'title',
      'order' => 'ASC',
      'fields' => 'ids',
      'no_found_rows' => !$with_totals,
    ]);

    $out = [];
    foreach ($q->posts as $id) {
      $dates = WC_Cart::get_event_date_options((int) $id);
      $dates = array_map(function ($entry) {
        return [
          'schedule_id' => (int) ($entry['schedule_id'] ?? 0),
          'label' => (string) ($entry['label'] ?? ''),
          'date' => (string) ($entry['date'] ?? ''),
          'time' => (string) ($entry['time'] ?? ''),
          'start_ts' => (int) ($entry['start_ts'] ?? 0),
        ];
      }, $dates);
      $out[] = [
        'id' => (int) $id,
        'title' => get_the_title($id),
        'type' => get_post_type($id),
        'thumbnail' => (string) get_the_post_thumbnail_url($id, 'medium'),
        'dates_count' => count($dates),
        'dates' => $dates,
      ];
    }

    if (!$with_totals) {
      return $out;
    }

    return [
      'items' => $out,
      'total' => (int) $q->found_posts,
      'total_pages' => (int) $q->max_num_pages,
    ];
  }
}
