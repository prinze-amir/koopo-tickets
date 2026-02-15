<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Ticket_Instances {
  public static function init(): void {
    add_action('woocommerce_order_status_completed', [__CLASS__, 'issue_tickets_for_order']);
    add_filter('woocommerce_payment_complete_order_status', [__CLASS__, 'force_completed_for_tickets'], 10, 3);
  }

  public static function force_completed_for_tickets(string $status, int $order_id, 
    \WC_Order $order): string {
    if (self::order_has_tickets($order)) return 'completed';
    return $status;
  }

  public static function issue_tickets_for_order(int $order_id): void {
    $order = wc_get_order($order_id);
    if (!$order) return;

    if (!self::order_has_tickets($order)) return;

    foreach ($order->get_items() as $item_id => $item) {
      $ticket_type_id = (int) $item->get_meta('_koopo_ticket_type_id');
      $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
      if (!$ticket_type_id || !$event_id) continue;

      if ($item->get_meta('_koopo_tickets_issued')) continue;

      $quantity = (int) $item->get_quantity();
      if ($quantity < 1) continue;

      $schedule_id = (int) $item->get_meta('_koopo_ticket_schedule_id');
      $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');
      $variation_id = (int) $item->get_variation_id();

      $contact = [
        'name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
        'email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
        'phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
      ];

      $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
      $guests = $guests_raw ? json_decode($guests_raw, true) : [];
      if (!is_array($guests)) $guests = [];

      $ticket_ids = [];
      for ($i = 0; $i < $quantity; $i++) {
        $guest = $i === 0 ? $contact : ($guests[$i - 1] ?? []);
        $ticket_ids[] = self::create_ticket([
          'order_id' => $order_id,
          'order_item_id' => $item_id,
          'event_id' => $event_id,
          'ticket_type_id' => $ticket_type_id,
          'variation_id' => $variation_id,
          'schedule_id' => $schedule_id,
          'schedule_label' => $schedule_label,
          'attendee_name' => sanitize_text_field($guest['name'] ?? ''),
          'attendee_email' => sanitize_email($guest['email'] ?? ''),
          'attendee_phone' => sanitize_text_field($guest['phone'] ?? ''),
          'attendee_index' => $i + 1,
        ]);
      }

      self::sync_event_rsvp($order, $item, $event_id, $quantity);

      $item->add_meta_data('_koopo_tickets_issued', 1, true);
      $item->add_meta_data('_koopo_ticket_ids', $ticket_ids, true);
      $item->save();
    }
  }

  private static function create_ticket(array $data): int {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    $code = self::generate_code($data['order_id'], $data['order_item_id'], $data['attendee_index']);

    $wpdb->insert($table, [
      'order_id' => (int) $data['order_id'],
      'order_item_id' => (int) $data['order_item_id'],
      'event_id' => (int) $data['event_id'],
      'ticket_type_id' => (int) $data['ticket_type_id'],
      'variation_id' => (int) $data['variation_id'],
      'code' => $code,
      'status' => 'issued',
      'attendee_name' => $data['attendee_name'],
      'attendee_email' => $data['attendee_email'],
      'attendee_phone' => $data['attendee_phone'],
      'attendee_index' => (int) $data['attendee_index'],
      'schedule_id' => (int) $data['schedule_id'],
      'schedule_label' => $data['schedule_label'],
      'created_at' => gmdate('Y-m-d H:i:s'),
    ], [
      '%d','%d','%d','%d','%d','%s','%s','%s','%s','%s','%d','%d','%s','%s'
    ]);

    return (int) $wpdb->insert_id;
  }

  private static function generate_code(int $order_id, int $item_id, int $index): string {
    $suffix = wp_generate_password(6, false, false);
    return strtoupper('KT-' . $order_id . '-' . $item_id . '-' . $index . '-' . $suffix);
  }

  private static function order_has_tickets(\WC_Order $order): bool {
    foreach ($order->get_items() as $item) {
      if ($item->get_meta('_koopo_ticket_type_id')) return true;
    }
    return false;
  }

  private static function sync_event_rsvp(\WC_Order $order, \WC_Order_Item_Product $item, int $event_id, int $quantity): void {
    if ($quantity < 1 || !$event_id) return;
    if (!class_exists('\GeoDir_Event_AYI')) return;

    $users = get_post_meta($event_id, 'event_rsvp_yes', true);
    if (!is_array($users)) $users = [];

    $user_id = (int) $order->get_user_id();
    $user_added = false;
    if ($user_id) {
      if (!array_key_exists($user_id, $users) && !in_array($user_id, $users, true)) {
        $users[$user_id] = $user_id;
        $user_added = true;
      }
    }

    $pseudo_count = $quantity - ($user_added ? 1 : 0);
    if ($pseudo_count < 0) $pseudo_count = 0;

    $order_id = (int) $order->get_id();
    $item_id = (int) $item->get_id();
    for ($i = 0; $i < $pseudo_count; $i++) {
      $key = 'kt-' . $order_id . '-' . $item_id . '-' . ($i + 1);
      if (isset($users[$key])) {
        $suffix = 2;
        while (isset($users[$key . '-' . $suffix])) {
          $suffix++;
        }
        $key = $key . '-' . $suffix;
      }
      $users[$key] = $key;
    }

    update_post_meta($event_id, 'event_rsvp_yes', $users);

    if ($user_id) {
      $posts = get_user_meta($user_id, 'event_rsvp_yes', true);
      if (!is_array($posts)) $posts = [];
      if (!array_key_exists($event_id, $posts)) {
        $posts[$event_id] = $event_id;
        update_user_meta($user_id, 'event_rsvp_yes', $posts);
      }
    }

    self::update_event_rsvp_count($event_id);
  }

  private static function update_event_rsvp_count(int $event_id): void {
    if (!$event_id) return;
    if (!class_exists('\GeoDir_Event_AYI')) return;
    if (!function_exists('geodir_db_cpt_table')) return;

    global $wpdb;
    $table = geodir_db_cpt_table(get_post_type($event_id));
    if (!$table) return;

    $count = \GeoDir_Event_AYI::count_interested($event_id);
    if (!is_array($count)) return;

    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
    if ($exists !== $table) return;

    $wpdb->query($wpdb->prepare(
      "UPDATE {$table} SET rsvp_count = %d WHERE post_id = %d",
      (int) ($count['total'] ?? 0),
      $event_id
    ));
  }
}
