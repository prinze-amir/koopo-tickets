<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class CLI_Tests {
  public static function init(): void {
    if (!defined('WP_CLI') || !\WP_CLI) {
      return;
    }

    \WP_CLI::add_command('koopo-tickets lifecycle-test', [__CLASS__, 'lifecycle_test']);
  }

  public static function lifecycle_test(array $args, array $assoc_args): void {
    $keep = !empty($assoc_args['keep']);
    $artifact = self::create_fixture();

    try {
      Ticket_Instances::issue_tickets_for_order($artifact['order_id']);

      $rows = self::get_ticket_rows_by_item($artifact['item_id']);
      self::assert_count($rows, 2, 'Expected two issued ticket rows.');
      self::assert_condition((string) $rows[0]->status === 'issued', 'Primary ticket should start as issued.');

      $pending = Customer_Ticket_Transfers::upsert_pending_transfer($rows[1], [
        'user_id' => $artifact['recipient_id'],
        'name' => 'Recipient User',
        'email' => 'koopo-recipient@example.test',
        'phone' => '3135550110',
      ], $artifact['customer_id']);
      self::assert_condition(!is_wp_error($pending) && $pending && (string) $pending->status === 'pending', 'Pending transfer should be created.');

      $cancelled = Customer_Ticket_Transfers::cancel_transfer($pending, $artifact['customer_id']);
      self::assert_condition(!is_wp_error($cancelled) && (string) $cancelled->status === 'cancelled', 'Pending transfer should cancel cleanly.');

      $pending = Customer_Ticket_Transfers::upsert_pending_transfer($rows[1], [
        'user_id' => $artifact['recipient_id'],
        'name' => 'Recipient User',
        'email' => 'koopo-recipient@example.test',
        'phone' => '3135550110',
      ], $artifact['customer_id']);
      self::assert_condition(!is_wp_error($pending) && (string) $pending->status === 'pending', 'Second pending transfer should be created.');

      $accepted = Customer_Ticket_Transfers::accept_transfer($pending, $artifact['recipient_id']);
      self::assert_condition(!is_wp_error($accepted) && !empty($accepted['ticket']), 'Pending transfer should accept.');

      $transferred_ticket = self::get_ticket_by_id((int) $rows[1]->id);
      self::assert_condition((string) $transferred_ticket->status === 'transferred', 'Accepted ticket should be marked transferred.');
      self::assert_condition((int) $transferred_ticket->attendee_user_id === $artifact['recipient_id'], 'Accepted ticket should move ownership to the recipient user.');

      wp_set_current_user($artifact['customer_id']);
      $owner_list = Customer_Tickets_API::list_tickets(new \WP_REST_Request('GET', '/koopo/v1/customer/tickets'));
      $owner_items = $owner_list instanceof \WP_REST_Response ? $owner_list->get_data() : [];
      self::assert_count($owner_items, 1, 'Original owner should still see one order item.');
      self::assert_condition((int) ($owner_items[0]['quantity'] ?? 0) === 1, 'Original owner should only retain one visible ticket after acceptance.');

      wp_set_current_user($artifact['recipient_id']);
      $recipient_list = Customer_Tickets_API::list_tickets(new \WP_REST_Request('GET', '/koopo/v1/customer/tickets'));
      $recipient_items = $recipient_list instanceof \WP_REST_Response ? $recipient_list->get_data() : [];
      self::assert_count($recipient_items, 1, 'Recipient should receive one accepted ticket in the dashboard.');
      self::assert_condition((string) ($recipient_items[0]['ownership'] ?? '') === 'recipient', 'Accepted tickets should become recipient-owned entries.');

      wp_set_current_user($artifact['vendor_id']);
      $redeem_request = new \WP_REST_Request('POST', '/koopo/v1/tickets/redeem');
      $redeem_request->set_param('code', (string) $rows[0]->code);
      $redeem_response = Ticket_Validation::redeem_ticket($redeem_request);
      self::assert_condition($redeem_response instanceof \WP_REST_Response && $redeem_response->get_status() === 200, 'Vendor should be able to redeem an issued ticket.');

      $redeem_request = new \WP_REST_Request('POST', '/koopo/v1/tickets/redeem');
      $redeem_request->set_param('code', (string) $transferred_ticket->code);
      $redeem_response = Ticket_Validation::redeem_ticket($redeem_request);
      self::assert_condition($redeem_response instanceof \WP_REST_Response && $redeem_response->get_status() === 200, 'Vendor should be able to redeem an accepted transferred ticket.');

      wp_set_current_user($artifact['recipient_id']);
      $recipient_list = Customer_Tickets_API::list_tickets(new \WP_REST_Request('GET', '/koopo/v1/customer/tickets'));
      $recipient_items = $recipient_list instanceof \WP_REST_Response ? $recipient_list->get_data() : [];
      self::assert_count($recipient_items, 1, 'Recipient should still see the ticket after redemption.');
      self::assert_condition((string) ($recipient_items[0]['status'] ?? '') === 'redeemed', 'Recipient dashboard should show redeemed status after check-in.');

      $order = wc_get_order($artifact['order_id']);
      $order->set_status('refunded');
      $order->save();

      $rows = self::get_ticket_rows_by_item($artifact['item_id']);
      self::assert_condition((string) $rows[0]->status === 'refunded', 'Refund should mark redeemed ticket refunded.');
      self::assert_condition((string) $rows[1]->status === 'refunded', 'Refund should mark transferred ticket refunded.');

      $order = wc_get_order($artifact['order_id']);
      $order->set_status('completed');
      $order->save();

      $rows = self::get_ticket_rows_by_item($artifact['item_id']);
      self::assert_condition((string) $rows[0]->status === 'issued', 'Re-completing should restore the primary ticket to issued.');
      self::assert_condition((string) $rows[1]->status === 'transferred', 'Re-completing should restore transferred ownership from guest meta.');

      \WP_CLI::success('Koopo Tickets lifecycle test passed.');
    } finally {
      wp_set_current_user(0);
      if (!$keep) {
        self::cleanup_fixture($artifact);
      }
    }
  }

  private static function create_fixture(): array {
    $vendor_id = self::ensure_user('koopo_vendor_test', 'koopo-vendor@example.test');
    $customer_id = self::ensure_user('koopo_customer_test', 'koopo-customer@example.test');
    $recipient_id = self::ensure_user('koopo_recipient_test', 'koopo-recipient@example.test');

    $event_type = Settings::get('event_cpt');
    $event_type = is_array($event_type) ? reset($event_type) : $event_type;
    $event_type = sanitize_key((string) $event_type);
    if (!$event_type) {
      $event_type = 'gd_event';
    }
    if (!post_type_exists($event_type)) {
      register_post_type($event_type, ['public' => true, 'label' => 'Events']);
    }

    $event_id = wp_insert_post([
      'post_type' => $event_type,
      'post_status' => 'publish',
      'post_author' => $vendor_id,
      'post_title' => 'CLI Lifecycle Event',
    ]);
    update_post_meta($event_id, 'event_start_date_time', gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * 7));
    update_post_meta($event_id, 'event_end_date_time', gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * 7 + HOUR_IN_SECONDS * 2));

    $ticket_type_id = wp_insert_post([
      'post_type' => Ticket_Types_CPT::POST_TYPE,
      'post_status' => 'publish',
      'post_author' => $vendor_id,
      'post_title' => 'CLI General Admission',
    ]);

    update_post_meta($ticket_type_id, Ticket_Types_API::META_EVENT_ID, $event_id);
    update_post_meta($ticket_type_id, Ticket_Types_API::META_PRICE, 25);
    update_post_meta($ticket_type_id, Ticket_Types_API::META_CAPACITY, 100);
    update_post_meta($ticket_type_id, Ticket_Types_API::META_STATUS, 'active');
    update_post_meta($ticket_type_id, Ticket_Types_API::META_VISIBILITY, 'public');
    update_post_meta($ticket_type_id, Ticket_Types_API::META_SALES_MODE, 'event_end');

    $variation_id = WC_Ticket_Product::create_or_update_for_ticket_type($ticket_type_id);
    self::assert_condition($variation_id > 0, 'Fixture variation should be created.');

    $product = wc_get_product($variation_id);
    self::assert_condition($product instanceof \WC_Product, 'Fixture variation should load as a WooCommerce product.');

    $order = wc_create_order(['customer_id' => $customer_id]);
    $item_id = $order->add_product($product, 2);
    $item = $order->get_item($item_id);
    $item->add_meta_data('_koopo_ticket_event_id', $event_id, true);
    $item->add_meta_data('_koopo_ticket_type_id', $ticket_type_id, true);
    $item->add_meta_data('_koopo_ticket_contact_name', 'Customer Owner', true);
    $item->add_meta_data('_koopo_ticket_contact_email', 'koopo-customer@example.test', true);
    $item->add_meta_data('_koopo_ticket_contact_phone', '3135550100', true);
    $item->add_meta_data('_koopo_ticket_guests', wp_json_encode([
      [
        'user_id' => 0,
        'name' => '',
        'email' => '',
        'phone' => '',
      ],
    ]), true);
    $item->save();

    $order->calculate_totals();
    $order->set_status('completed');
    $order->save();

    return [
      'vendor_id' => $vendor_id,
      'customer_id' => $customer_id,
      'recipient_id' => $recipient_id,
      'event_id' => $event_id,
      'ticket_type_id' => $ticket_type_id,
      'variation_id' => $variation_id,
      'order_id' => $order->get_id(),
      'item_id' => $item_id,
    ];
  }

  private static function cleanup_fixture(array $artifact): void {
    global $wpdb;

    if (!empty($artifact['item_id'])) {
      $tickets_table = $wpdb->prefix . 'koopo_tickets';
      $transfers_table = $wpdb->prefix . 'koopo_ticket_transfers';
      $ticket_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tickets_table} WHERE order_item_id = %d",
        (int) $artifact['item_id']
      ));

      if (!empty($ticket_ids)) {
        $placeholders = implode(',', array_fill(0, count($ticket_ids), '%d'));
        $wpdb->query($wpdb->prepare(
          "DELETE FROM {$transfers_table} WHERE ticket_id IN ({$placeholders})",
          $ticket_ids
        ));
      }

      $wpdb->delete($tickets_table, ['order_item_id' => (int) $artifact['item_id']], ['%d']);
    }

    if (!empty($artifact['order_id'])) {
      wp_delete_post((int) $artifact['order_id'], true);
    }

    if (!empty($artifact['ticket_type_id'])) {
      WC_Ticket_Product::maybe_trash_for_ticket_type((int) $artifact['ticket_type_id']);
      wp_delete_post((int) $artifact['ticket_type_id'], true);
    }

    if (!empty($artifact['event_id'])) {
      wp_delete_post((int) $artifact['event_id'], true);
    }

    foreach (['vendor_id', 'customer_id', 'recipient_id'] as $key) {
      if (!empty($artifact[$key])) {
        wp_delete_user((int) $artifact[$key]);
      }
    }
  }

  private static function ensure_user(string $login, string $email): int {
    $user = get_user_by('login', $login);
    if ($user) {
      return (int) $user->ID;
    }

    $user_id = wp_create_user($login, wp_generate_password(20, true, true), $email);
    self::assert_condition(!is_wp_error($user_id) && $user_id > 0, 'Fixture user should be created.');
    return (int) $user_id;
  }

  private static function get_ticket_rows_by_item(int $item_id): array {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY attendee_index ASC",
      $item_id
    ));
  }

  private static function get_ticket_by_id(int $ticket_id) {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $ticket_id));
  }

  private static function assert_condition(bool $condition, string $message): void {
    if ($condition) {
      return;
    }

    \WP_CLI::error($message);
  }

  private static function assert_count(array $items, int $expected, string $message): void {
    self::assert_condition(count($items) === $expected, $message);
  }
}
