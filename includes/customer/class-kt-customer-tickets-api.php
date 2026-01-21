<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Customer_Tickets_API {
  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/customer/tickets', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'list_tickets'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);

    register_rest_route('koopo/v1', '/customer/tickets/(?P<item_id>\d+)/guests', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'update_guests'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);

    register_rest_route('koopo/v1', '/customer/tickets/(?P<item_id>\d+)/send', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'send_tickets'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);

    register_rest_route('koopo/v1', '/customer/friends', [
      'methods' => 'GET',
      'callback' => [__CLASS__, 'list_friends'],
      'permission_callback' => fn() => is_user_logged_in(),
    ]);
  }

  public static function list_tickets(\WP_REST_Request $req) {
    $user_id = get_current_user_id();
    $orders = wc_get_orders([
      'customer_id' => $user_id,
      'status' => ['processing', 'completed', 'on-hold'],
      'limit' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    $out = [];

    foreach ($orders as $order) {
      foreach ($order->get_items() as $item_id => $item) {
        $ticket_type_id = (int) $item->get_meta('_koopo_ticket_type_id');
        $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
        $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');
        $schedule_id = (int) $item->get_meta('_koopo_ticket_schedule_id');

        if (!$ticket_type_id && !$event_id) continue;

        $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
        $guests = $guests_raw ? json_decode($guests_raw, true) : [];
        if (!is_array($guests)) $guests = [];

        $schedule = $schedule_id && class_exists('GeoDir_Event_Schedules')
          ? \GeoDir_Event_Schedules::get_schedule($schedule_id)
          : null;

        $schedule_date = '';
        $schedule_time = '';
        if ($schedule && !empty($schedule->start_date)) {
          $date_format = function_exists('geodir_event_date_format') ? geodir_event_date_format() : 'Y-m-d';
          $time_format = function_exists('geodir_event_time_format') ? geodir_event_time_format() : 'H:i';
          $start_date = $schedule->start_date;
          $start_time = $schedule->start_time ?? '00:00:00';
          $end_time = $schedule->end_time ?? '';
          $schedule_date = date_i18n($date_format, strtotime($start_date));
          if (!empty($schedule->all_day)) {
            $schedule_time = __('All day', 'koopo-tickets');
          } else {
            $schedule_time = date_i18n($time_format, strtotime($start_time));
            if (!empty($end_time)) {
              $schedule_time .= ' - ' . date_i18n($time_format, strtotime($end_time));
            }
          }
        }

        $quantity = (int) $item->get_quantity();
        $slots = self::build_attendee_slots($item, $quantity);

        $ticket_status = self::map_ticket_status($order->get_status());

        $out[] = [
          'order_id' => $order->get_id(),
          'order_number' => $order->get_order_number(),
          'item_id' => $item_id,
          'ticket_name' => $item->get_name(),
          'quantity' => $quantity,
          'event_id' => $event_id,
          'event_title' => $event_id ? get_the_title($event_id) : '',
          'event_url' => $event_id ? get_permalink($event_id) : '',
          'event_image' => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
          'event_location' => $event_id ? WC_Cart::get_event_location($event_id) : '',
          'schedule_label' => $schedule_label,
          'schedule_date' => $schedule_date,
          'schedule_time' => $schedule_time,
          'contact_name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
          'contact_email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
          'contact_phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
          'guests' => $guests,
          'attendees' => $slots,
          'status' => $ticket_status,
          'status_label' => ucfirst($ticket_status),
        ];
      }
    }

    return new \WP_REST_Response($out, 200);
  }

  public static function update_guests(\WP_REST_Request $req) {
    $item_id = absint($req['item_id']);
    $item = self::get_order_item_for_user($item_id);
    if (!$item) return new \WP_REST_Response(['error' => 'Ticket not found'], 404);

    $guests = $req->get_param('guests');
    if (!is_array($guests)) $guests = [];

    $clean = [];
    foreach ($guests as $guest) {
      if (!is_array($guest)) continue;
      $clean[] = [
        'name' => sanitize_text_field($guest['name'] ?? ''),
        'email' => sanitize_email($guest['email'] ?? ''),
        'phone' => sanitize_text_field($guest['phone'] ?? ''),
        'ticket_type_id' => absint($guest['ticket_type_id'] ?? 0),
        'ticket_name' => sanitize_text_field($guest['ticket_name'] ?? ''),
      ];
    }

    $item->update_meta_data('_koopo_ticket_guests', wp_json_encode($clean));
    $item->save();

    return new \WP_REST_Response(['success' => true], 200);
  }

  public static function send_tickets(\WP_REST_Request $req) {
    $item_id = absint($req['item_id']);
    $item = self::get_order_item_for_user($item_id);
    if (!$item) return new \WP_REST_Response(['error' => 'Ticket not found'], 404);

    $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
    $guests = $guests_raw ? json_decode($guests_raw, true) : [];
    if (!is_array($guests) || empty($guests)) {
      return new \WP_REST_Response(['error' => 'No guests to notify'], 400);
    }

    foreach ($guests as $guest) {
      $email = sanitize_email($guest['email'] ?? '');
      if (!$email) continue;
      $subject = __('Your ticket details', 'koopo-tickets');
      $body = sprintf(
        "%s\n\n%s: %s\n%s: %s\n",
        __('You have been assigned a ticket.', 'koopo-tickets'),
        __('Ticket', 'koopo-tickets'),
        sanitize_text_field($guest['ticket_name'] ?? $item->get_name()),
        __('Order', 'koopo-tickets'),
        $item->get_order_id()
      );
      wp_mail($email, $subject, $body);
    }

    return new \WP_REST_Response(['success' => true], 200);
  }

  public static function list_friends(\WP_REST_Request $req) {
    if (!function_exists('friends_get_friend_user_ids')) {
      return new \WP_REST_Response([], 200);
    }

    $user_id = get_current_user_id();
    $friend_ids = friends_get_friend_user_ids($user_id);
    if (empty($friend_ids)) return new \WP_REST_Response([], 200);

    $search = sanitize_text_field((string) $req->get_param('search'));
    $args = [
      'include' => $friend_ids,
      'number' => 20,
    ];
    if ($search) {
      $args['search'] = '*' . $search . '*';
      $args['search_columns'] = ['user_login', 'display_name', 'user_email'];
    }

    $users = get_users($args);
    $out = [];
    foreach ($users as $user) {
      $out[] = [
        'id' => $user->ID,
        'name' => $user->display_name,
        'email' => $user->user_email,
        'avatar' => function_exists('bp_core_fetch_avatar') ? bp_core_fetch_avatar([
          'item_id' => $user->ID,
          'type' => 'thumb',
          'width' => 48,
          'height' => 48,
          'html' => false,
        ]) : get_avatar_url($user->ID, ['size' => 48]),
      ];
    }

    return new \WP_REST_Response($out, 200);
  }

  private static function get_order_item_for_user(int $item_id) {
    $item = new \WC_Order_Item_Product($item_id);
    if (!$item || !$item->get_id()) return null;

    $order = wc_get_order($item->get_order_id());
    if (!$order) return null;

    if ((int) $order->get_user_id() !== get_current_user_id()) return null;

    return $item;
  }

  private static function build_attendee_slots($item, int $quantity): array {
    $contact = [
      'name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
      'email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
      'phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
    ];

    $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
    $guests = $guests_raw ? json_decode($guests_raw, true) : [];
    if (!is_array($guests)) $guests = [];

    $slots = [];
    $slots[] = self::build_attendee($contact, __('You', 'koopo-tickets'));

    for ($i = 0; $i < max(0, $quantity - 1); $i++) {
      $guest = $guests[$i] ?? [];
      $slots[] = self::build_attendee($guest, sprintf(__('Guest %d', 'koopo-tickets'), $i + 1));
    }

    return $slots;
  }

  private static function build_attendee(array $data, string $fallback_label): array {
    $name = sanitize_text_field($data['name'] ?? '');
    $email = sanitize_email($data['email'] ?? '');
    $phone = sanitize_text_field($data['phone'] ?? '');
    $label = $name ?: $fallback_label;

    $avatar = '';
    if ($email) {
      $user = get_user_by('email', $email);
      if ($user && function_exists('bp_core_fetch_avatar')) {
        $avatar = bp_core_fetch_avatar([
          'item_id' => $user->ID,
          'type' => 'thumb',
          'width' => 64,
          'height' => 64,
          'html' => false,
        ]);
      } else {
        $avatar = get_avatar_url($email, ['size' => 64]);
      }
    }

    return [
      'label' => $label,
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'avatar' => $avatar,
    ];
  }

  private static function map_ticket_status(string $order_status): string {
    switch ($order_status) {
      case 'completed':
      case 'processing':
        return 'issued';
      case 'refunded':
        return 'refunded';
      case 'cancelled':
        return 'cancelled';
      case 'failed':
        return 'cancelled';
      default:
        return 'issued';
    }
  }
}
