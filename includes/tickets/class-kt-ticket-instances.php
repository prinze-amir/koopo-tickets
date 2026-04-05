<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Ticket_Instances {
  public static function init(): void {
    add_action('woocommerce_order_status_completed', [__CLASS__, 'issue_tickets_for_order']);
    add_action('woocommerce_order_status_changed', [__CLASS__, 'handle_order_status_changed'], 10, 4);
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
      if (!wc_add_order_item_meta($item_id, '_koopo_tickets_issuing', time(), true)) continue;

      try {
        $quantity = (int) $item->get_quantity();
        if ($quantity < 1) continue;

        $schedule_id = (int) $item->get_meta('_koopo_ticket_schedule_id');
        $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');
        $variation_id = (int) $item->get_variation_id();

        $contact = [
          'user_id' => (int) $order->get_user_id(),
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
            'attendee_user_id' => absint($guest['user_id'] ?? 0),
            'attendee_index' => $i + 1,
          ]);
        }

        self::sync_event_rsvp($order, $item, $event_id, $quantity);

        $item->add_meta_data('_koopo_tickets_issued', 1, true);
        $item->add_meta_data('_koopo_ticket_ids', $ticket_ids, true);
        $item->update_meta_data('_koopo_tickets_rsvp_synced', 1);
        $item->save();
      } finally {
        wc_delete_order_item_meta($item_id, '_koopo_tickets_issuing');
      }
    }
  }

  public static function handle_order_status_changed(int $order_id, string $from_status, string $to_status, 
    \WC_Order $order): void {
    if (!$order || !self::order_has_tickets($order)) {
      return;
    }

    if (self::is_active_order_status($to_status)) {
      self::issue_tickets_for_order($order_id);
      $fresh_order = wc_get_order($order_id);
      if (!$fresh_order) {
        return;
      }
      self::restore_order_ticket_states($fresh_order);
      self::sync_order_rsvp($fresh_order);
      return;
    }

    $inactive_status = self::map_inactive_ticket_status($to_status);
    if ($inactive_status) {
      self::set_order_ticket_status($order, $inactive_status);
      self::unsync_order_rsvp($order);
    }
  }

  private static function create_ticket(array $data): int {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE order_item_id = %d AND attendee_index = %d LIMIT 1",
      (int) $data['order_item_id'],
      (int) $data['attendee_index']
    ));
    if ($existing_id > 0) {
      return $existing_id;
    }

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
      'attendee_user_id' => (int) ($data['attendee_user_id'] ?? 0),
      'attendee_index' => (int) $data['attendee_index'],
      'schedule_id' => (int) $data['schedule_id'],
      'schedule_label' => $data['schedule_label'],
      'created_at' => gmdate('Y-m-d H:i:s'),
    ], [
      '%d', '%d', '%d', '%d', '%d',
      '%s', '%s', '%s', '%s', '%s',
      '%d', '%d', '%d', '%s', '%s',
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

  private static function is_active_order_status(string $status): bool {
    return in_array($status, ['processing', 'completed', 'on-hold'], true);
  }

  private static function map_inactive_ticket_status(string $order_status): string {
    if ($order_status === 'refunded') {
      return 'refunded';
    }

    if (in_array($order_status, ['cancelled', 'failed'], true)) {
      return 'cancelled';
    }

    return '';
  }

  private static function is_ticket_item(\WC_Order_Item_Product $item): bool {
    return (int) $item->get_meta('_koopo_ticket_type_id') > 0 && (int) $item->get_meta('_koopo_ticket_event_id') > 0;
  }

  private static function set_order_ticket_status(\WC_Order $order, string $status): void {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    $timestamp = gmdate('Y-m-d H:i:s');

    foreach ($order->get_items() as $item) {
      if (!$item instanceof \WC_Order_Item_Product || !self::is_ticket_item($item)) {
        continue;
      }

      $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status = %s, updated_at = %s
         WHERE order_item_id = %d
           AND status <> %s",
        $status,
        $timestamp,
        (int) $item->get_id(),
        $status
      ));
    }
  }

  private static function restore_order_ticket_states(\WC_Order $order): void {
    foreach ($order->get_items() as $item) {
      if (!$item instanceof \WC_Order_Item_Product || !self::is_ticket_item($item)) {
        continue;
      }

      self::restore_ticket_rows_for_item($item);
    }
  }

  private static function restore_ticket_rows_for_item(\WC_Order_Item_Product $item): void {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, attendee_index, status
       FROM {$table}
       WHERE order_item_id = %d
       ORDER BY attendee_index ASC",
      (int) $item->get_id()
    ));

    if (empty($rows)) {
      return;
    }

    foreach ($rows as $row) {
      if (!in_array((string) ($row->status ?? ''), ['refunded', 'cancelled'], true)) {
        continue;
      }

      $wpdb->update(
        $table,
        [
          'status' => self::derive_restored_ticket_status($item, (int) ($row->id ?? 0), (int) ($row->attendee_index ?? 0)),
          'updated_at' => gmdate('Y-m-d H:i:s'),
        ],
        ['id' => (int) $row->id],
        ['%s', '%s'],
        ['%d']
      );
    }
  }

  private static function derive_restored_ticket_status(\WC_Order_Item_Product $item, int $ticket_id, int $attendee_index): string {
    if ($ticket_id > 0) {
      $accepted_transfer = Customer_Ticket_Transfers::get_transfer_by_ticket_id($ticket_id, ['accepted']);
      if ($accepted_transfer) {
        return 'transferred';
      }
    }

    if ($attendee_index > 1) {
      $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
      $guests = $guests_raw ? json_decode($guests_raw, true) : [];
      if (!is_array($guests)) {
        $guests = [];
      }

      $guest_index = max(0, $attendee_index - 2);
      $guest = $guests[$guest_index] ?? [];
      if (absint($guest['user_id'] ?? 0) > 0) {
        return 'transferred';
      }
    }

    return 'issued';
  }

  private static function sync_order_rsvp(\WC_Order $order): void {
    foreach ($order->get_items() as $item) {
      if (!$item instanceof \WC_Order_Item_Product || !self::is_ticket_item($item)) {
        continue;
      }

      if ($item->get_meta('_koopo_tickets_rsvp_synced')) {
        continue;
      }

      $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
      $quantity = (int) $item->get_quantity();
      if ($event_id < 1 || $quantity < 1) {
        continue;
      }

      self::sync_event_rsvp($order, $item, $event_id, $quantity);
      $item->update_meta_data('_koopo_tickets_rsvp_synced', 1);
      $item->save();
    }
  }

  private static function unsync_order_rsvp(\WC_Order $order): void {
    foreach ($order->get_items() as $item) {
      if (!$item instanceof \WC_Order_Item_Product || !self::is_ticket_item($item)) {
        continue;
      }

      if (!$item->get_meta('_koopo_tickets_rsvp_synced')) {
        continue;
      }

      $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
      $quantity = (int) $item->get_quantity();
      if ($event_id > 0 && $quantity > 0) {
        self::unsync_event_rsvp($order, $item, $event_id, $quantity);
      }

      $item->delete_meta_data('_koopo_tickets_rsvp_synced');
      $item->save();
    }
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

  private static function unsync_event_rsvp(\WC_Order $order, \WC_Order_Item_Product $item, int $event_id, int $quantity): void {
    if ($quantity < 1 || !$event_id) return;
    if (!class_exists('\GeoDir_Event_AYI')) return;

    $users = get_post_meta($event_id, 'event_rsvp_yes', true);
    if (!is_array($users)) {
      $users = [];
    }

    $prefix = 'kt-' . (int) $order->get_id() . '-' . (int) $item->get_id() . '-';
    foreach (array_keys($users) as $key) {
      if (is_string($key) && strpos($key, $prefix) === 0) {
        unset($users[$key]);
      }
    }

    $user_id = (int) $order->get_user_id();
    if ($user_id && !self::user_has_other_active_ticket_for_event($user_id, $event_id, (int) $order->get_id())) {
      foreach ($users as $key => $value) {
        if ((string) $key === (string) $user_id || (string) $value === (string) $user_id) {
          unset($users[$key]);
        }
      }

      $posts = get_user_meta($user_id, 'event_rsvp_yes', true);
      if (is_array($posts) && array_key_exists($event_id, $posts)) {
        unset($posts[$event_id]);
        update_user_meta($user_id, 'event_rsvp_yes', $posts);
      }
    }

    update_post_meta($event_id, 'event_rsvp_yes', $users);
    self::update_event_rsvp_count($event_id);
  }

  private static function user_has_other_active_ticket_for_event(int $user_id, int $event_id, int $exclude_order_id): bool {
    global $wpdb;

    if ($user_id < 1 || $event_id < 1) {
      return false;
    }

    $tickets_table = $wpdb->prefix . 'koopo_tickets';
    $use_hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
      && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ($use_hpos) {
      $orders_table = $wpdb->prefix . 'wc_orders';
      $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1)
         FROM {$tickets_table} t
         INNER JOIN {$orders_table} orders
           ON orders.id = t.order_id
         WHERE t.event_id = %d
           AND t.order_id <> %d
           AND t.status NOT IN ('refunded', 'cancelled')
           AND orders.customer_id = %d
           AND orders.type = 'shop_order'
           AND orders.status IN ('wc-processing', 'wc-completed', 'wc-on-hold')",
        $event_id,
        $exclude_order_id,
        $user_id
      ));
    } else {
      $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1)
         FROM {$tickets_table} t
         INNER JOIN {$wpdb->posts} orders
           ON orders.ID = t.order_id
         INNER JOIN {$wpdb->postmeta} customer_meta
           ON customer_meta.post_id = orders.ID
           AND customer_meta.meta_key = '_customer_user'
         WHERE t.event_id = %d
           AND t.order_id <> %d
           AND t.status NOT IN ('refunded', 'cancelled')
           AND customer_meta.meta_value = %d
           AND orders.post_type = 'shop_order'
           AND orders.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')",
        $event_id,
        $exclude_order_id,
        $user_id
      ));
    }

    return (int) $count > 0;
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

    $safe_table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    if (!$safe_table) return;

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $safe_table));
    if ($exists !== $table) return;

    $wpdb->query($wpdb->prepare(
      "UPDATE `{$safe_table}` SET rsvp_count = %d WHERE post_id = %d",
      (int) ($count['total'] ?? 0),
      $event_id
    ));
  }
}
