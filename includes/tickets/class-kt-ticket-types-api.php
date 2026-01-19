<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Ticket_Types_API {
  const META_EVENT_ID   = '_koopo_ticket_event_id';
  const META_PRICE      = '_koopo_ticket_price';
  const META_CAPACITY   = '_koopo_ticket_capacity';
  const META_SALES_START = '_koopo_ticket_sales_start';
  const META_SALES_END   = '_koopo_ticket_sales_end';
  const META_STATUS     = '_koopo_ticket_status';
  const META_VISIBILITY = '_koopo_ticket_visibility';
  const META_SKU        = '_koopo_ticket_sku';
  const META_PRODUCT_ID = '_koopo_wc_product_id';
  const META_VARIATION_ID = '_koopo_wc_variation_id';

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('koopo/v1', '/ticket-types', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'list_ticket_types'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    register_rest_route('koopo/v1', '/ticket-types', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'create_ticket_type'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    register_rest_route('koopo/v1', '/ticket-types/(?P<id>\d+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'update_ticket_type'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    register_rest_route('koopo/v1', '/ticket-types/(?P<id>\d+)', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'get_ticket_type'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    register_rest_route('koopo/v1', '/ticket-types/(?P<id>\d+)', [
      'methods' => 'DELETE',
      'callback' => [__CLASS__, 'delete_ticket_type'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);
  }

  public static function can_manage(): bool {
    return Access::vendor_can_manage_tickets();
  }

  private static function assert_owner(int $post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== Ticket_Types_CPT::POST_TYPE) {
      return [false, new \WP_REST_Response(['error' => 'Ticket type not found'], 404)];
    }
    if (!Access::is_admin_bypass() && (int) $post->post_author !== get_current_user_id()) {
      return [false, new \WP_REST_Response(['error' => 'Forbidden'], 403)];
    }
    return [true, $post];
  }

  private static function assert_event_access(int $event_id, int $user_id) {
    if (!$event_id) return [false, new \WP_REST_Response(['error' => 'event_id is required'], 400)];

    $event = get_post($event_id);
    if (!$event) return [false, new \WP_REST_Response(['error' => 'Event not found'], 404)];

    $event_cpt = Settings::get('event_cpt');
    $allowed = is_array($event_cpt) ? $event_cpt : [$event_cpt];
    if (!in_array($event->post_type, $allowed, true)) {
      return [false, new \WP_REST_Response(['error' => 'Invalid event type'], 400)];
    }

    if (!Access::is_admin_bypass() && (int) $event->post_author !== $user_id) {
      return [false, new \WP_REST_Response(['error' => 'Invalid event ownership'], 403)];
    }

    return [true, $event];
  }

  public static function create_ticket_type(\WP_REST_Request $req) {
    $user_id = get_current_user_id();

    $title     = sanitize_text_field((string) $req->get_param('title'));
    $event_id  = absint($req->get_param('event_id'));
    $price     = $req->get_param('price');
    $capacity  = $req->get_param('capacity');
    $status    = sanitize_text_field((string) $req->get_param('status'));
    $visibility = sanitize_text_field((string) $req->get_param('visibility'));
    $sales_start = sanitize_text_field((string) $req->get_param('sales_start'));
    $sales_end = sanitize_text_field((string) $req->get_param('sales_end'));
    $sku = sanitize_text_field((string) $req->get_param('sku'));

    if (!$title) return new \WP_REST_Response(['error' => 'title is required'], 400);

    [$ok, $event_or_resp] = self::assert_event_access($event_id, $user_id);
    if (!$ok) return $event_or_resp;

    $validation = self::validate_meta([
      'price' => $price,
      'capacity' => $capacity,
      'status' => $status,
      'visibility' => $visibility,
      'sales_start' => $sales_start,
      'sales_end' => $sales_end,
    ]);
    if ($validation) return $validation;

    $ticket_type_id = wp_insert_post([
      'post_type' => Ticket_Types_CPT::POST_TYPE,
      'post_status' => 'publish',
      'post_title' => $title,
      'post_author' => $user_id,
    ], true);

    if (is_wp_error($ticket_type_id)) {
      return new \WP_REST_Response(['error' => $ticket_type_id->get_error_message()], 500);
    }

    self::persist_meta($ticket_type_id, [
      'event_id' => $event_id,
      'price' => $price,
      'capacity' => $capacity,
      'status' => $status,
      'visibility' => $visibility,
      'sales_start' => $sales_start,
      'sales_end' => $sales_end,
      'sku' => $sku,
    ]);

    WC_Ticket_Product::create_or_update_for_ticket_type($ticket_type_id);

    return new \WP_REST_Response(self::format_ticket_type($ticket_type_id), 201);
  }

  public static function update_ticket_type(\WP_REST_Request $req) {
    $ticket_type_id = absint($req['id']);
    [$ok, $post_or_resp] = self::assert_owner($ticket_type_id);
    if (!$ok) return $post_or_resp;

    $title = $req->get_param('title');
    if ($title) {
      wp_update_post(['ID' => $ticket_type_id, 'post_title' => sanitize_text_field((string) $title)]);
    }

    $event_id = $req->get_param('event_id');
    if ($event_id !== null) {
      $event_id = absint($event_id);
      [$ok_event, $event_or_resp] = self::assert_event_access($event_id, get_current_user_id());
      if (!$ok_event) return $event_or_resp;
    }

    $validation = self::validate_meta([
      'price' => $req->get_param('price'),
      'capacity' => $req->get_param('capacity'),
      'status' => $req->get_param('status'),
      'visibility' => $req->get_param('visibility'),
      'sales_start' => $req->get_param('sales_start'),
      'sales_end' => $req->get_param('sales_end'),
    ]);
    if ($validation) return $validation;

    self::persist_meta($ticket_type_id, [
      'event_id' => $event_id,
      'price' => $req->get_param('price'),
      'capacity' => $req->get_param('capacity'),
      'status' => $req->get_param('status'),
      'visibility' => $req->get_param('visibility'),
      'sales_start' => $req->get_param('sales_start'),
      'sales_end' => $req->get_param('sales_end'),
      'sku' => $req->get_param('sku'),
    ]);

    WC_Ticket_Product::create_or_update_for_ticket_type($ticket_type_id);

    return new \WP_REST_Response(self::format_ticket_type($ticket_type_id), 200);
  }

  public static function delete_ticket_type(\WP_REST_Request $req) {
    $ticket_type_id = absint($req['id']);
    [$ok, $post_or_resp] = self::assert_owner($ticket_type_id);
    if (!$ok) return $post_or_resp;

    wp_trash_post($ticket_type_id);
    WC_Ticket_Product::maybe_trash_for_ticket_type($ticket_type_id);

    return new \WP_REST_Response([
      'deleted_ticket_type_id' => $ticket_type_id,
    ], 200);
  }

  public static function get_ticket_type(\WP_REST_Request $req) {
    $ticket_type_id = absint($req['id']);
    [$ok, $post_or_resp] = self::assert_owner($ticket_type_id);
    if (!$ok) return $post_or_resp;

    return new \WP_REST_Response(self::format_ticket_type($ticket_type_id), 200);
  }

  public static function list_ticket_types(\WP_REST_Request $req) {
    $user_id = get_current_user_id();

    $event_id = absint($req->get_param('event_id'));
    $query_args = [
      'post_type' => Ticket_Types_CPT::POST_TYPE,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'fields' => 'ids',
    ];

    if (!Access::is_admin_bypass()) {
      $query_args['author'] = $user_id;
    }

    if ($event_id) {
      $query_args['meta_query'] = [
        [
          'key' => self::META_EVENT_ID,
          'value' => $event_id,
          'compare' => '=',
        ],
      ];
    }

    $q = new \WP_Query($query_args);

    $items = [];
    foreach ($q->posts as $ticket_type_id) {
      $items[] = self::format_ticket_type($ticket_type_id);
    }

    return new \WP_REST_Response($items, 200);
  }

  private static function validate_meta(array $payload) {
    if (array_key_exists('price', $payload) && $payload['price'] !== null) {
      if ((float) $payload['price'] < 0) {
        return new \WP_REST_Response(['error' => 'price must be zero or more'], 400);
      }
    }
    if (array_key_exists('capacity', $payload) && $payload['capacity'] !== null) {
      if ((int) $payload['capacity'] < 0) {
        return new \WP_REST_Response(['error' => 'capacity must be zero or more'], 400);
      }
    }
    if (array_key_exists('status', $payload) && $payload['status'] !== null) {
      $status = sanitize_text_field((string) $payload['status']);
      if ($status && !in_array($status, ['active', 'inactive'], true)) {
        return new \WP_REST_Response(['error' => 'status must be active or inactive'], 400);
      }
    }
    if (array_key_exists('visibility', $payload) && $payload['visibility'] !== null) {
      $visibility = sanitize_text_field((string) $payload['visibility']);
      if ($visibility && !in_array($visibility, ['public', 'private'], true)) {
        return new \WP_REST_Response(['error' => 'visibility must be public or private'], 400);
      }
    }
    if (!empty($payload['sales_start']) && !empty($payload['sales_end'])) {
      $start = strtotime((string) $payload['sales_start']);
      $end = strtotime((string) $payload['sales_end']);
      if ($start && $end && $end < $start) {
        return new \WP_REST_Response(['error' => 'sales_end must be after sales_start'], 400);
      }
    }

    return null;
  }

  private static function persist_meta(int $ticket_type_id, array $payload): void {
    if (array_key_exists('event_id', $payload) && $payload['event_id'] !== null) {
      update_post_meta($ticket_type_id, self::META_EVENT_ID, absint($payload['event_id']));
    }
    if (array_key_exists('price', $payload) && $payload['price'] !== null) {
      update_post_meta($ticket_type_id, self::META_PRICE, (float) $payload['price']);
    }
    if (array_key_exists('capacity', $payload) && $payload['capacity'] !== null) {
      update_post_meta($ticket_type_id, self::META_CAPACITY, absint($payload['capacity']));
    }
    if (array_key_exists('status', $payload) && $payload['status'] !== null) {
      $status = sanitize_text_field((string) $payload['status']);
      update_post_meta($ticket_type_id, self::META_STATUS, ($status === 'inactive' ? 'inactive' : 'active'));
    }
    if (array_key_exists('visibility', $payload) && $payload['visibility'] !== null) {
      $visibility = sanitize_text_field((string) $payload['visibility']);
      update_post_meta($ticket_type_id, self::META_VISIBILITY, ($visibility === 'private' ? 'private' : 'public'));
    }
    if (array_key_exists('sales_start', $payload) && $payload['sales_start'] !== null) {
      update_post_meta($ticket_type_id, self::META_SALES_START, sanitize_text_field((string) $payload['sales_start']));
    }
    if (array_key_exists('sales_end', $payload) && $payload['sales_end'] !== null) {
      update_post_meta($ticket_type_id, self::META_SALES_END, sanitize_text_field((string) $payload['sales_end']));
    }
    if (array_key_exists('sku', $payload) && $payload['sku'] !== null) {
      update_post_meta($ticket_type_id, self::META_SKU, sanitize_text_field((string) $payload['sku']));
    }
  }

  private static function format_ticket_type(int $ticket_type_id): array {
    $event_id = (int) get_post_meta($ticket_type_id, self::META_EVENT_ID, true);
    return [
      'id' => $ticket_type_id,
      'title' => get_the_title($ticket_type_id),
      'event_id' => $event_id,
      'event_title' => $event_id ? get_the_title($event_id) : '',
      'price' => (float) get_post_meta($ticket_type_id, self::META_PRICE, true),
      'capacity' => (int) get_post_meta($ticket_type_id, self::META_CAPACITY, true),
      'status' => (string) get_post_meta($ticket_type_id, self::META_STATUS, true),
      'visibility' => (string) get_post_meta($ticket_type_id, self::META_VISIBILITY, true),
      'sales_start' => (string) get_post_meta($ticket_type_id, self::META_SALES_START, true),
      'sales_end' => (string) get_post_meta($ticket_type_id, self::META_SALES_END, true),
      'sku' => (string) get_post_meta($ticket_type_id, self::META_SKU, true),
      'product_id' => (int) get_post_meta($ticket_type_id, self::META_PRODUCT_ID, true),
      'variation_id' => (int) get_post_meta($ticket_type_id, self::META_VARIATION_ID, true),
      'author' => (int) get_post_field('post_author', $ticket_type_id),
    ];
  }
}
