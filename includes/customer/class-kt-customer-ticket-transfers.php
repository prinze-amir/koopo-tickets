<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Customer_Ticket_Transfers {
  public static function init(): void {
    add_filter('query_vars', [__CLASS__, 'register_query_vars']);
    add_action('template_redirect', [__CLASS__, 'maybe_render_accept_page']);
  }

  public static function register_query_vars(array $vars): array {
    $vars[] = 'koopo_ticket_transfer';
    $vars[] = 'kt_transfer_token';
    return $vars;
  }

  public static function build_accept_url(object $transfer): string {
    return add_query_arg([
      'koopo_ticket_transfer' => (int) ($transfer->id ?? 0),
      'kt_transfer_token' => (string) ($transfer->token ?? ''),
    ], home_url('/'));
  }

  public static function get_transfer_by_ticket_id(int $ticket_id, array $statuses = []) {
    global $wpdb;

    if ($ticket_id < 1) {
      return null;
    }

    $table = $wpdb->prefix . 'koopo_ticket_transfers';
    $sql = "SELECT * FROM {$table} WHERE ticket_id = %d";
    $args = [$ticket_id];

    if (!empty($statuses)) {
      $statuses = array_values(array_filter(array_map('sanitize_text_field', $statuses)));
      if (!empty($statuses)) {
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql .= " AND status IN ({$placeholders})";
        $args = array_merge($args, $statuses);
      }
    }

    $sql .= ' ORDER BY id DESC LIMIT 1';
    return $wpdb->get_row($wpdb->prepare($sql, $args));
  }

  public static function get_transfers_by_ticket_ids(array $ticket_ids, array $statuses = []): array {
    global $wpdb;

    $ticket_ids = array_values(array_filter(array_map('absint', $ticket_ids)));
    if (empty($ticket_ids)) {
      return [];
    }

    $table = $wpdb->prefix . 'koopo_ticket_transfers';
    $placeholders = implode(',', array_fill(0, count($ticket_ids), '%d'));
    $sql = "SELECT * FROM {$table} WHERE ticket_id IN ({$placeholders})";
    $args = $ticket_ids;

    if (!empty($statuses)) {
      $statuses = array_values(array_filter(array_map('sanitize_text_field', $statuses)));
      if (!empty($statuses)) {
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql .= " AND status IN ({$status_placeholders})";
        $args = array_merge($args, $statuses);
      }
    }

    $sql .= ' ORDER BY id DESC';
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
    $out = [];
    foreach ((array) $rows as $row) {
      $ticket_id = (int) ($row->ticket_id ?? 0);
      if ($ticket_id > 0 && !isset($out[$ticket_id])) {
        $out[$ticket_id] = $row;
      }
    }

    return $out;
  }

  public static function upsert_pending_transfer(object $ticket, array $data, int $sender_user_id) {
    global $wpdb;

    $ticket_id = (int) ($ticket->id ?? 0);
    $order_item_id = (int) ($ticket->order_item_id ?? 0);
    $email = sanitize_email((string) ($data['email'] ?? ''));
    if (!$ticket_id || !$order_item_id || !$email) {
      return new \WP_Error('invalid_transfer', __('Recipient email is required.', 'koopo-tickets'));
    }

    $user_id = absint($data['user_id'] ?? 0);
    if (!$user_id) {
      $user = get_user_by('email', $email);
      if ($user) {
        $user_id = (int) $user->ID;
      }
    }

    $table = $wpdb->prefix . 'koopo_ticket_transfers';
    $existing = self::get_transfer_by_ticket_id($ticket_id);
    $token = wp_generate_password(32, false, false);
    $timestamp = gmdate('Y-m-d H:i:s');
    $payload = [
      'ticket_id' => $ticket_id,
      'order_item_id' => $order_item_id,
      'sender_user_id' => $sender_user_id,
      'recipient_user_id' => $user_id,
      'recipient_name' => sanitize_text_field((string) ($data['name'] ?? '')),
      'recipient_email' => $email,
      'recipient_phone' => sanitize_text_field((string) ($data['phone'] ?? '')),
      'token' => $token,
      'status' => 'pending',
      'updated_at' => $timestamp,
      'accepted_at' => null,
      'cancelled_at' => null,
    ];

    if ($existing && !empty($existing->id)) {
      $wpdb->update(
        $table,
        $payload,
        ['id' => (int) $existing->id],
        ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        ['%d']
      );
      $transfer_id = (int) $existing->id;
    } else {
      $payload['created_at'] = $timestamp;
      $wpdb->insert(
        $table,
        $payload,
        ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
      );
      $transfer_id = (int) $wpdb->insert_id;
    }

    return self::get_transfer_by_id($transfer_id);
  }

  public static function cancel_transfer(object $transfer, int $actor_user_id) {
    global $wpdb;

    if ((string) ($transfer->status ?? '') !== 'pending') {
      return new \WP_Error('invalid_status', __('Only pending transfers can be cancelled.', 'koopo-tickets'));
    }

    if ($actor_user_id < 1 || (int) ($transfer->sender_user_id ?? 0) !== $actor_user_id) {
      return new \WP_Error('forbidden', __('You cannot cancel this transfer.', 'koopo-tickets'));
    }

    $table = $wpdb->prefix . 'koopo_ticket_transfers';
    $updated = $wpdb->update(
      $table,
      [
        'status' => 'cancelled',
        'updated_at' => gmdate('Y-m-d H:i:s'),
        'cancelled_at' => gmdate('Y-m-d H:i:s'),
      ],
      ['id' => (int) $transfer->id],
      ['%s', '%s', '%s'],
      ['%d']
    );

    if ($updated === false) {
      return new \WP_Error('db_error', __('Unable to cancel transfer.', 'koopo-tickets'));
    }

    return self::get_transfer_by_id((int) $transfer->id);
  }

  public static function accept_transfer(object $transfer, int $actor_user_id = 0) {
    global $wpdb;

    if ((string) ($transfer->status ?? '') !== 'pending') {
      return new \WP_Error('invalid_status', __('This transfer is no longer pending.', 'koopo-tickets'));
    }

    $ticket = self::get_ticket_by_id((int) ($transfer->ticket_id ?? 0));
    if (!$ticket) {
      return new \WP_Error('missing_ticket', __('Ticket not found.', 'koopo-tickets'));
    }

    $ticket_status = (string) ($ticket->status ?? '');
    if (in_array($ticket_status, ['redeemed', 'refunded', 'cancelled', 'transferred'], true)) {
      return new \WP_Error('invalid_ticket_status', __('This ticket can no longer be transferred.', 'koopo-tickets'));
    }

    $resolved_user_id = self::resolve_accepting_user_id($transfer, $actor_user_id);
    if (is_wp_error($resolved_user_id)) {
      return $resolved_user_id;
    }

    $order = wc_get_order((int) ($ticket->order_id ?? 0));
    if (!$order || !in_array((string) $order->get_status(), ['processing', 'completed', 'on-hold'], true)) {
      return new \WP_Error('invalid_order', __('This ticket order is no longer active.', 'koopo-tickets'));
    }

    $tickets_table = $wpdb->prefix . 'koopo_tickets';
    $wpdb->update(
      $tickets_table,
      [
        'attendee_name' => sanitize_text_field((string) ($transfer->recipient_name ?? '')),
        'attendee_email' => sanitize_email((string) ($transfer->recipient_email ?? '')),
        'attendee_phone' => sanitize_text_field((string) ($transfer->recipient_phone ?? '')),
        'attendee_user_id' => $resolved_user_id,
        'status' => 'transferred',
        'updated_at' => gmdate('Y-m-d H:i:s'),
      ],
      ['id' => (int) $ticket->id],
      ['%s', '%s', '%s', '%d', '%s', '%s'],
      ['%d']
    );

    self::sync_order_item_guest_meta($ticket, [
      'user_id' => $resolved_user_id,
      'name' => (string) ($transfer->recipient_name ?? ''),
      'email' => (string) ($transfer->recipient_email ?? ''),
      'phone' => (string) ($transfer->recipient_phone ?? ''),
    ]);

    $transfers_table = $wpdb->prefix . 'koopo_ticket_transfers';
    $wpdb->update(
      $transfers_table,
      [
        'recipient_user_id' => $resolved_user_id,
        'status' => 'accepted',
        'updated_at' => gmdate('Y-m-d H:i:s'),
        'accepted_at' => gmdate('Y-m-d H:i:s'),
      ],
      ['id' => (int) $transfer->id],
      ['%d', '%s', '%s', '%s'],
      ['%d']
    );

    $updated_ticket = self::get_ticket_by_id((int) $ticket->id);
    if ($updated_ticket) {
      self::send_acceptance_confirmation($updated_ticket);
    }

    return [
      'ticket' => $updated_ticket,
      'transfer' => self::get_transfer_by_id((int) $transfer->id),
    ];
  }

  public static function format_transfer(?object $transfer): ?array {
    if (!$transfer) {
      return null;
    }

    return [
      'id' => (int) ($transfer->id ?? 0),
      'status' => (string) ($transfer->status ?? ''),
      'recipient_user_id' => (int) ($transfer->recipient_user_id ?? 0),
      'recipient_name' => sanitize_text_field((string) ($transfer->recipient_name ?? '')),
      'recipient_email' => sanitize_email((string) ($transfer->recipient_email ?? '')),
      'recipient_phone' => sanitize_text_field((string) ($transfer->recipient_phone ?? '')),
      'accept_url' => (string) self::build_accept_url($transfer),
    ];
  }

  public static function get_transfer_by_id(int $transfer_id) {
    global $wpdb;

    if ($transfer_id < 1) {
      return null;
    }

    $table = $wpdb->prefix . 'koopo_ticket_transfers';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $transfer_id));
  }

  public static function get_transfer_by_token(int $transfer_id, string $token) {
    global $wpdb;

    if ($transfer_id < 1 || $token === '') {
      return null;
    }

    $table = $wpdb->prefix . 'koopo_ticket_transfers';
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE id = %d AND token = %s LIMIT 1",
      $transfer_id,
      sanitize_text_field($token)
    ));
  }

  public static function maybe_render_accept_page(): void {
    $transfer_id = absint(get_query_var('koopo_ticket_transfer') ?: wp_unslash($_GET['koopo_ticket_transfer'] ?? 0));
    if (!$transfer_id) {
      return;
    }

    $token = sanitize_text_field((string) (get_query_var('kt_transfer_token') ?: wp_unslash($_GET['kt_transfer_token'] ?? '')));
    $transfer = self::get_transfer_by_token($transfer_id, $token);
    if (!$transfer) {
      wp_die(__('Transfer link is invalid.', 'koopo-tickets'));
    }

    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['koopo_ticket_transfer_action'])) {
      check_admin_referer('koopo_ticket_transfer_' . $transfer_id, 'koopo_ticket_transfer_nonce');

      $action = sanitize_text_field(wp_unslash($_POST['koopo_ticket_transfer_action']));
      if ($action === 'accept') {
        $result = self::accept_transfer($transfer, get_current_user_id());
        if (!is_wp_error($result)) {
          $transfer = self::get_transfer_by_id($transfer_id);
        }
      }
    }

    $ticket = self::get_ticket_by_id((int) ($transfer->ticket_id ?? 0));
    $item = $ticket ? new \WC_Order_Item_Product((int) $ticket->order_item_id) : null;
    $event_id = $ticket ? (int) ($ticket->event_id ?? 0) : 0;
    $ticket_links = ($ticket && (string) ($transfer->status ?? '') === 'accepted')
      ? Customer_Tickets_Print::build_ticket_access_links($ticket)
      : [];

    $template = KOOPO_TICKETS_PATH . 'templates/customer/accept-transfer.php';
    if (!file_exists($template)) {
      wp_die(__('Transfer template not found.', 'koopo-tickets'));
    }

    wp_enqueue_style('koopo-ticket-dashboard', KOOPO_TICKETS_URL . 'assets/customer-tickets.css', [], KOOPO_TICKETS_VERSION);

    $data = [
      'transfer' => $transfer,
      'ticket' => $ticket,
      'ticket_item' => $item,
      'event_title' => $event_id ? get_the_title($event_id) : '',
      'event_url' => $event_id ? get_permalink($event_id) : '',
      'event_image' => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
      'schedule_label' => $ticket ? (string) ($ticket->schedule_label ?? '') : '',
      'error' => is_wp_error($result) ? $result->get_error_message() : '',
      'accepted' => !is_wp_error($result) && is_array($result),
      'links' => $ticket_links,
      'login_required' => self::recipient_requires_login($transfer),
    ];

    include $template;
    exit;
  }

  private static function recipient_requires_login(object $transfer): bool {
    return get_current_user_id() < 1;
  }

  private static function resolve_accepting_user_id(object $transfer, int $actor_user_id) {
    if ($actor_user_id < 1) {
      return new \WP_Error('login_required', __('Please log in to accept this ticket.', 'koopo-tickets'));
    }

    $expected_user_id = (int) ($transfer->recipient_user_id ?? 0);
    $recipient_email = sanitize_email((string) ($transfer->recipient_email ?? ''));
    $recipient_user = $recipient_email ? get_user_by('email', $recipient_email) : false;
    if (!$expected_user_id && $recipient_user) {
      $expected_user_id = (int) $recipient_user->ID;
    }

    if ($expected_user_id > 0) {
      if ($actor_user_id !== $expected_user_id) {
        return new \WP_Error('wrong_user', __('This transfer is assigned to a different user account.', 'koopo-tickets'));
      }

      return $expected_user_id;
    }

    $current_user = get_user_by('id', $actor_user_id);
    if (!$current_user) {
      return new \WP_Error('wrong_user', __('Please log in to accept this ticket.', 'koopo-tickets'));
    }

    if ($recipient_email && strcasecmp((string) $current_user->user_email, $recipient_email) !== 0) {
      return new \WP_Error('wrong_user', __('Please use the account that matches the recipient email to accept this ticket.', 'koopo-tickets'));
    }

    return $actor_user_id;
  }

  private static function sync_order_item_guest_meta(object $ticket, array $data): void {
    $order_item_id = (int) ($ticket->order_item_id ?? 0);
    $attendee_index = (int) ($ticket->attendee_index ?? 0);
    if ($order_item_id < 1 || $attendee_index <= 1) {
      return;
    }

    $item = new \WC_Order_Item_Product($order_item_id);
    if (!$item || !$item->get_id()) {
      return;
    }

    $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
    $guests = $guests_raw ? json_decode($guests_raw, true) : [];
    if (!is_array($guests)) {
      $guests = [];
    }

    $guest_index = max(0, $attendee_index - 2);
    $guests[$guest_index] = [
      'user_id' => absint($data['user_id'] ?? 0),
      'name' => sanitize_text_field((string) ($data['name'] ?? '')),
      'email' => sanitize_email((string) ($data['email'] ?? '')),
      'phone' => sanitize_text_field((string) ($data['phone'] ?? '')),
    ];
    $item->update_meta_data('_koopo_ticket_guests', wp_json_encode($guests));
    $item->save();
  }

  private static function get_ticket_by_id(int $ticket_id) {
    global $wpdb;

    if ($ticket_id < 1) {
      return null;
    }

    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $ticket_id));
  }

  private static function send_acceptance_confirmation(object $ticket): void {
    $email = sanitize_email((string) ($ticket->attendee_email ?? ''));
    if (!$email) {
      return;
    }

    $links = Customer_Tickets_Print::build_ticket_access_links($ticket);
    $event_title = !empty($ticket->event_id) ? (string) get_the_title((int) $ticket->event_id) : '';
    $subject = __('Your ticket is ready', 'koopo-tickets');
    $body = sprintf(
      "%s\n\n%s: %s\n%s: %s\n%s: %s\n%s: %s\n%s: %s\n",
      __('You accepted a transferred ticket.', 'koopo-tickets'),
      __('Event', 'koopo-tickets'),
      $event_title ?: __('See ticket link', 'koopo-tickets'),
      __('Date/Time', 'koopo-tickets'),
      (string) ($ticket->schedule_label ?? __('See ticket link', 'koopo-tickets')),
      __('View', 'koopo-tickets'),
      $links['view'],
      __('Print', 'koopo-tickets'),
      $links['print'],
      __('Download', 'koopo-tickets'),
      $links['download']
    );

    wp_mail($email, $subject, $body);
  }
}
