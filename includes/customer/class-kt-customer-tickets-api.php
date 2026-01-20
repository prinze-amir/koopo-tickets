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

        if (!$ticket_type_id && !$event_id) continue;

        $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
        $guests = $guests_raw ? json_decode($guests_raw, true) : [];
        if (!is_array($guests)) $guests = [];

        $out[] = [
          'order_id' => $order->get_id(),
          'order_number' => $order->get_order_number(),
          'item_id' => $item_id,
          'ticket_name' => $item->get_name(),
          'quantity' => $item->get_quantity(),
          'event_id' => $event_id,
          'event_title' => $event_id ? get_the_title($event_id) : '',
          'schedule_label' => $schedule_label,
          'contact_name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
          'contact_email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
          'contact_phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
          'guests' => $guests,
          'status' => $order->get_status(),
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

  private static function get_order_item_for_user(int $item_id) {
    $item = new \WC_Order_Item_Product($item_id);
    if (!$item || !$item->get_id()) return null;

    $order = wc_get_order($item->get_order_id());
    if (!$order) return null;

    if ((int) $order->get_user_id() !== get_current_user_id()) return null;

    return $item;
  }
}
