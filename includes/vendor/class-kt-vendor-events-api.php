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
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  public static function get_my_events(\WP_REST_Request $req) {
    $user_id = get_current_user_id();
    if (!$user_id) return new \WP_REST_Response([], 200);

    $out = self::query_events($user_id);
    return new \WP_REST_Response($out, 200);
  }

  public static function get_events_for_user(int $user_id): array {
    if (!$user_id) return [];
    return self::query_events($user_id);
  }

  private static function query_events(int $user_id): array {
    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter(array_map('sanitize_key', $types));
    if (!$types) $types = ['gd_event'];

    $q = new \WP_Query([
      'post_type' => $types,
      'post_status' => 'publish',
      'author' => $user_id,
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'fields' => 'ids',
      'no_found_rows' => true,
    ]);

    $out = [];
    foreach ($q->posts as $id) {
      $out[] = [
        'id' => (int) $id,
        'title' => get_the_title($id),
        'type' => get_post_type($id),
      ];
    }

    return $out;
  }
}
