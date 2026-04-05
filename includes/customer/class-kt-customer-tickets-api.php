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

    register_rest_route('koopo/v1', '/customer/tickets/(?P<item_id>\d+)/transfer/cancel', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'cancel_transfer'],
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
    $page = max(1, absint($req->get_param('page')));
    $per_page = absint($req->get_param('per_page'));
    if ($per_page < 1) {
      $per_page = 50;
    }
    if ($per_page > 100) {
      $per_page = 100;
    }

    $item_page = self::get_paginated_ticket_entries($user_id, $page, $per_page);

    $out = [];

    foreach ($item_page['entries'] as $entry) {
      $entry_type = (string) ($entry['entry_type'] ?? '');
      $entry_id = absint($entry['entry_id'] ?? 0);
      if (!$entry_id) {
        continue;
      }

      if ($entry_type === 'received_ticket') {
        $ticket = self::get_ticket_by_id($entry_id);
        if ($ticket) {
          $formatted = self::format_received_ticket($ticket, $user_id);
          if (is_array($formatted)) {
            $out[] = $formatted;
          }
        }
        continue;
      }

      $item = new \WC_Order_Item_Product($entry_id);
      if (!$item || !$item->get_id()) {
        continue;
      }

      $order = wc_get_order($item->get_order_id());
      if (!$order || (int) $order->get_user_id() !== $user_id) {
        continue;
      }

      $formatted = self::format_ticket_item($order, $item);
      if (is_array($formatted)) {
        $out[] = $formatted;
      }
    }

    $response = new \WP_REST_Response($out, 200);
    $response->header('X-WP-Page', (string) $page);
    $response->header('X-WP-Per-Page', (string) $per_page);
    $response->header('X-WP-Total', (string) $item_page['total']);
    $response->header('X-WP-TotalPages', (string) $item_page['total_pages']);
    return $response;
  }

  public static function update_guests(\WP_REST_Request $req) {
    $item_id = absint($req['item_id']);
    $item = self::get_order_item_for_user($item_id);
    if (!$item) return new \WP_REST_Response(['error' => 'Ticket not found'], 404);

    $guests = $req->get_param('guests');
    if (!is_array($guests)) $guests = [];

    $submitted_by_slot = [];
    foreach ($guests as $guest) {
      if (!is_array($guest)) continue;
      $slot_index = array_key_exists('slot_index', $guest) ? max(0, absint($guest['slot_index'])) : count($submitted_by_slot);
      $submitted_by_slot[$slot_index] = [
        'user_id' => absint($guest['user_id'] ?? 0),
        'name' => sanitize_text_field($guest['name'] ?? ''),
        'email' => sanitize_email($guest['email'] ?? ''),
        'phone' => sanitize_text_field($guest['phone'] ?? ''),
        'ticket_type_id' => absint($guest['ticket_type_id'] ?? 0),
        'ticket_name' => sanitize_text_field($guest['ticket_name'] ?? ''),
      ];
    }

    $existing_guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
    $existing_guests = $existing_guests_raw ? json_decode($existing_guests_raw, true) : [];
    if (!is_array($existing_guests)) {
      $existing_guests = [];
    }

    $max_slots = max(0, (int) $item->get_quantity() - 1);
    $rows = self::get_ticket_rows((int) $item->get_id());
    foreach ($rows as $row) {
      if ((int) ($row->attendee_index ?? 0) <= 1) {
        continue;
      }

      $slot_index = (int) $row->attendee_index - 2;
      $max_slots = max($max_slots, $slot_index + 1);
    }

    $clean = [];
    for ($slot_index = 0; $slot_index < $max_slots; $slot_index++) {
      $row = self::get_ticket_by_index((int) $item->get_id(), $slot_index + 2);
      $locked_transfer = $row
        ? Customer_Ticket_Transfers::get_transfer_by_ticket_id((int) ($row->id ?? 0), ['pending', 'accepted'])
        : null;

      if ($row && ($locked_transfer || (string) ($row->status ?? '') === 'transferred')) {
        $clean[$slot_index] = $existing_guests[$slot_index] ?? [
          'user_id' => (int) ($row->attendee_user_id ?? 0),
          'name' => (string) ($row->attendee_name ?? ''),
          'email' => (string) ($row->attendee_email ?? ''),
          'phone' => (string) ($row->attendee_phone ?? ''),
        ];
        continue;
      }

      if (array_key_exists($slot_index, $submitted_by_slot)) {
        $clean[$slot_index] = $submitted_by_slot[$slot_index];
        continue;
      }

      $clean[$slot_index] = $existing_guests[$slot_index] ?? [
        'user_id' => 0,
        'name' => '',
        'email' => '',
        'phone' => '',
      ];
    }

    $item->update_meta_data('_koopo_ticket_guests', wp_json_encode($clean));
    $item->save();

    self::sync_ticket_rows_with_guests($item, $clean);

    return new \WP_REST_Response(['success' => true], 200);
  }

  public static function send_tickets(\WP_REST_Request $req) {
    $item_id = absint($req['item_id']);
    $item = self::get_order_item_for_user($item_id);
    if (!$item) return new \WP_REST_Response(['error' => 'Ticket not found'], 404);

    $ticket_id = absint($req->get_param('ticket_id'));
    $guest_index = $req->get_param('guest_index');
    $guest_index = $guest_index === null ? null : absint($guest_index);
    $user_id = absint($req->get_param('user_id'));

    $name = sanitize_text_field((string) $req->get_param('name'));
    $email = sanitize_email((string) $req->get_param('email'));
    $phone = sanitize_text_field((string) $req->get_param('phone'));

    $ticket = null;
    if ($ticket_id) {
      $ticket = self::get_ticket_by_id_for_item($ticket_id, $item->get_id());
    } elseif ($guest_index !== null) {
      $ticket = self::get_ticket_by_index($item->get_id(), $guest_index + 2);
    }

    if ($user_id) {
      $user = get_user_by('id', $user_id);
      if (!$user) {
        return new \WP_REST_Response(['error' => 'Assigned user not found'], 404);
      }
      $name = $user->display_name;
      $email = $user->user_email;
    }

    if (!$email && $ticket) {
      $email = (string) $ticket->attendee_email;
      $name = $name ?: (string) $ticket->attendee_name;
      $phone = $phone ?: (string) $ticket->attendee_phone;
    }

    if (!$email) {
      return new \WP_REST_Response(['error' => 'Guest email is required'], 400);
    }

    if (!$ticket) {
      return new \WP_REST_Response(['error' => 'Ticket record not found for this attendee'], 409);
    }

    $current_status = (string) ($ticket->status ?? '');
    if ($current_status === 'redeemed') {
      return new \WP_REST_Response(['error' => 'Redeemed tickets cannot be reassigned'], 409);
    }
    if ($current_status === 'transferred') {
      return new \WP_REST_Response(['error' => 'Accepted transferred tickets cannot be reassigned'], 409);
    }
    if (in_array($current_status, ['refunded', 'cancelled'], true)) {
      return new \WP_REST_Response(['error' => 'Inactive tickets cannot be reassigned'], 409);
    }

    $transfer = Customer_Ticket_Transfers::upsert_pending_transfer($ticket, [
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'user_id' => $user_id,
    ], get_current_user_id());
    if (is_wp_error($transfer)) {
      return new \WP_REST_Response(['error' => $transfer->get_error_message()], 400);
    }

    $accept_url = Customer_Ticket_Transfers::build_accept_url($transfer);
    $event_title = '';
    $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
    if ($event_id) {
      $event_title = (string) get_the_title($event_id);
    }
    $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');

    $subject = __('Your ticket details', 'koopo-tickets');
    $body = sprintf(
      "%s\n\n%s: %s\n%s: %s\n%s: %s\n%s: %s\n%s: %s\n",
      __('A ticket has been offered to you. Accept it to take ownership.', 'koopo-tickets'),
      __('Ticket', 'koopo-tickets'),
      $item->get_name(),
      __('Event', 'koopo-tickets'),
      $event_title ?: __('Event details available on the ticket link.', 'koopo-tickets'),
      __('Date/Time', 'koopo-tickets'),
      $schedule_label ?: __('See ticket link', 'koopo-tickets'),
      __('Order', 'koopo-tickets'),
      $item->get_order_id(),
      __('Accept Ticket', 'koopo-tickets'),
      $accept_url
    );
    if (!wp_mail($email, $subject, $body)) {
      return new \WP_REST_Response(['error' => 'Unable to send ticket email'], 500);
    }

    return new \WP_REST_Response([
      'success' => true,
      'transfer' => Customer_Ticket_Transfers::format_transfer($transfer),
    ], 200);
  }

  public static function cancel_transfer(\WP_REST_Request $req) {
    $item_id = absint($req['item_id']);
    $item = self::get_order_item_for_user($item_id);
    if (!$item) {
      return new \WP_REST_Response(['error' => 'Ticket not found'], 404);
    }

    $ticket_id = absint($req->get_param('ticket_id'));
    $guest_index = $req->get_param('guest_index');
    $guest_index = $guest_index === null ? null : absint($guest_index);

    $ticket = null;
    if ($ticket_id) {
      $ticket = self::get_ticket_by_id_for_item($ticket_id, $item->get_id());
    } elseif ($guest_index !== null) {
      $ticket = self::get_ticket_by_index($item->get_id(), $guest_index + 2);
    }

    if (!$ticket) {
      return new \WP_REST_Response(['error' => 'Ticket record not found for this attendee'], 404);
    }

    $transfer = Customer_Ticket_Transfers::get_transfer_by_ticket_id((int) $ticket->id, ['pending']);
    if (!$transfer) {
      return new \WP_REST_Response(['error' => 'No pending transfer found'], 404);
    }

    $result = Customer_Ticket_Transfers::cancel_transfer($transfer, get_current_user_id());
    if (is_wp_error($result)) {
      return new \WP_REST_Response(['error' => $result->get_error_message()], 409);
    }

    return new \WP_REST_Response([
      'success' => true,
      'transfer' => Customer_Ticket_Transfers::format_transfer($result),
    ], 200);
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
        'id' => (int) $user->ID,
        'name' => sanitize_text_field((string) $user->display_name),
        'email' => sanitize_email((string) $user->user_email),
        'avatar' => esc_url_raw(function_exists('bp_core_fetch_avatar') ? bp_core_fetch_avatar([
          'item_id' => $user->ID,
          'type' => 'thumb',
          'width' => 48,
          'height' => 48,
          'html' => false,
        ]) : get_avatar_url($user->ID, ['size' => 48])),
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

  private static function get_paginated_ticket_entries(int $user_id, int $page, int $per_page): array {
    global $wpdb;

    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $tickets_table = $wpdb->prefix . 'koopo_tickets';
    $transfers_table = $wpdb->prefix . 'koopo_ticket_transfers';
    $offset = ($page - 1) * $per_page;
    $use_hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
      && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ($use_hpos) {
      $orders_table = $wpdb->prefix . 'wc_orders';
      $owner_sql = "
        SELECT 'owner_item' AS entry_type,
          order_items.order_item_id AS entry_id,
          orders.date_created_gmt AS sort_date,
          orders.id AS order_id
        FROM {$orders_table} orders
        INNER JOIN {$order_items_table} order_items
          ON order_items.order_id = orders.id
          AND order_items.order_item_type = 'line_item'
        INNER JOIN {$order_itemmeta_table} ticket_meta
          ON ticket_meta.order_item_id = order_items.order_item_id
          AND ticket_meta.meta_key IN ('_koopo_ticket_type_id', '_koopo_ticket_event_id')
        WHERE orders.type = 'shop_order'
          AND orders.status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
          AND orders.customer_id = %d
          AND (
            NOT EXISTS (
              SELECT 1
              FROM {$tickets_table} owner_tickets
              WHERE owner_tickets.order_item_id = order_items.order_item_id
            )
            OR EXISTS (
              SELECT 1
              FROM {$tickets_table} owner_tickets
              LEFT JOIN {$transfers_table} accepted_transfers
                ON accepted_transfers.ticket_id = owner_tickets.id
               AND accepted_transfers.status = 'accepted'
              WHERE owner_tickets.order_item_id = order_items.order_item_id
                AND accepted_transfers.id IS NULL
            )
          )
        GROUP BY order_items.order_item_id, orders.date_created_gmt, orders.id
      ";

      $received_sql = "
        SELECT 'received_ticket' AS entry_type,
          t.id AS entry_id,
          COALESCE(t.updated_at, t.created_at) AS sort_date,
          t.order_id AS order_id
        FROM {$tickets_table} t
        INNER JOIN {$orders_table} orders
          ON orders.id = t.order_id
        WHERE orders.type = 'shop_order'
          AND orders.status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
          AND orders.customer_id <> %d
          AND t.attendee_user_id = %d
          AND t.status IN ('transferred', 'redeemed')
      ";
      $union_args = [$user_id, $user_id, $user_id];
    } else {
      $owner_sql = "
        SELECT 'owner_item' AS entry_type,
          order_items.order_item_id AS entry_id,
          orders.post_date_gmt AS sort_date,
          orders.ID AS order_id
        FROM {$wpdb->posts} orders
        INNER JOIN {$wpdb->postmeta} customer_meta
          ON customer_meta.post_id = orders.ID
          AND customer_meta.meta_key = '_customer_user'
          AND customer_meta.meta_value = %d
        INNER JOIN {$order_items_table} order_items
          ON order_items.order_id = orders.ID
          AND order_items.order_item_type = 'line_item'
        INNER JOIN {$order_itemmeta_table} ticket_meta
          ON ticket_meta.order_item_id = order_items.order_item_id
          AND ticket_meta.meta_key IN ('_koopo_ticket_type_id', '_koopo_ticket_event_id')
        WHERE orders.post_type = 'shop_order'
          AND orders.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
          AND (
            NOT EXISTS (
              SELECT 1
              FROM {$tickets_table} owner_tickets
              WHERE owner_tickets.order_item_id = order_items.order_item_id
            )
            OR EXISTS (
              SELECT 1
              FROM {$tickets_table} owner_tickets
              LEFT JOIN {$transfers_table} accepted_transfers
                ON accepted_transfers.ticket_id = owner_tickets.id
               AND accepted_transfers.status = 'accepted'
              WHERE owner_tickets.order_item_id = order_items.order_item_id
                AND accepted_transfers.id IS NULL
            )
          )
        GROUP BY order_items.order_item_id, orders.post_date_gmt, orders.ID
      ";

      $received_sql = "
        SELECT 'received_ticket' AS entry_type,
          t.id AS entry_id,
          COALESCE(t.updated_at, t.created_at) AS sort_date,
          t.order_id AS order_id
        FROM {$tickets_table} t
        INNER JOIN {$wpdb->posts} orders
          ON orders.ID = t.order_id
        INNER JOIN {$wpdb->postmeta} customer_meta
          ON customer_meta.post_id = orders.ID
          AND customer_meta.meta_key = '_customer_user'
        WHERE orders.post_type = 'shop_order'
          AND orders.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
          AND customer_meta.meta_value <> %d
          AND t.attendee_user_id = %d
          AND t.status IN ('transferred', 'redeemed')
      ";
      $union_args = [$user_id, $user_id, $user_id];
    }

    $entries_sql = "{$owner_sql} UNION ALL {$received_sql}";
    $total_sql = "SELECT COUNT(1) FROM ({$entries_sql}) entries";
    $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, $union_args));

    $items_sql = "
      SELECT entry_type, entry_id, sort_date, order_id
      FROM ({$entries_sql}) entries
      ORDER BY sort_date DESC, order_id DESC, entry_id DESC
      LIMIT %d OFFSET %d
    ";

    $items = $wpdb->get_results($wpdb->prepare($items_sql, array_merge($union_args, [$per_page, $offset])), ARRAY_A);

    return [
      'entries' => is_array($items) ? $items : [],
      'total' => $total,
      'total_pages' => $total > 0 ? (int) ceil($total / $per_page) : 0,
    ];
  }

  private static function format_ticket_item(\WC_Order $order, \WC_Order_Item_Product $item): ?array {
    $item_id = (int) $item->get_id();
    $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
    $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');
    $schedule_id = (int) $item->get_meta('_koopo_ticket_schedule_id');

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

    if (!$schedule_date && !$schedule_time && $event_id) {
      if ($schedule_id) {
        $option = WC_Cart::get_event_date_option($event_id, $schedule_id);
        $schedule_date = $option['date'] ?? '';
        $schedule_time = $option['time'] ?? '';
        if (!$schedule_label && !empty($option['label'])) {
          $schedule_label = $option['label'];
        }
      }
      if (!$schedule_date && !$schedule_time) {
        $event_dt = WC_Cart::get_event_datetime($event_id);
        $schedule_date = $event_dt['date'] ?? '';
        $schedule_time = $event_dt['time'] ?? '';
        if (!$schedule_label && !empty($event_dt['label'])) {
          $schedule_label = $event_dt['label'];
        }
      }
    }

    $quantity = (int) $item->get_quantity();
    $rows = self::get_ticket_rows($item_id);
    if (!empty($rows)) {
      $transfer_map = Customer_Ticket_Transfers::get_transfers_by_ticket_ids(array_map(function ($row) {
        return (int) ($row->id ?? 0);
      }, $rows), ['pending', 'accepted']);
      $visible_rows = self::filter_owner_visible_ticket_rows($rows, $transfer_map);
      if (empty($visible_rows)) {
        return null;
      }
      $slots = self::build_attendee_slots_from_rows($visible_rows, $item, $transfer_map);
      $ticket_status = self::derive_ticket_status($visible_rows);
      $guests = self::build_guest_list_from_rows($visible_rows);
      $quantity = count($visible_rows);
    } else {
      $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
      $guests = $guests_raw ? json_decode($guests_raw, true) : [];
      if (!is_array($guests)) {
        $guests = [];
      }
      $slots = self::build_attendee_slots($item, $quantity);
      $ticket_status = self::map_ticket_status($order->get_status());
    }

    $links = Customer_Tickets_Print::build_order_item_links($item_id);

    return [
      'order_id' => $order->get_id(),
      'order_number' => $order->get_order_number(),
      'item_id' => $item_id,
      'ticket_name' => $item->get_name(),
      'quantity' => $quantity,
      'event_id' => $event_id,
      'event_title' => $event_id ? wp_strip_all_tags((string) get_the_title($event_id)) : '',
      'event_url' => $event_id ? esc_url_raw((string) get_permalink($event_id)) : '',
      'event_image' => $event_id ? esc_url_raw((string) get_the_post_thumbnail_url($event_id, 'medium')) : '',
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
      'view_url' => $links['view'],
      'print_url' => $links['print'],
      'download_url' => $links['download'],
      'ownership' => 'order_owner',
      'can_manage_guests' => true,
    ];
  }

  private static function format_received_ticket(object $ticket, int $user_id): ?array {
    if ((int) ($ticket->attendee_user_id ?? 0) !== $user_id) {
      return null;
    }

    $item = new \WC_Order_Item_Product((int) ($ticket->order_item_id ?? 0));
    if (!$item || !$item->get_id()) {
      return null;
    }

    $order = wc_get_order((int) ($ticket->order_id ?? 0));
    if (!$order) {
      return null;
    }

    $event_id = (int) ($ticket->event_id ?? 0);
    $schedule_label = (string) ($ticket->schedule_label ?? '');
    $schedule_date = '';
    $schedule_time = '';
    if (!empty($ticket->schedule_id) && $event_id) {
      $option = WC_Cart::get_event_date_option($event_id, (int) $ticket->schedule_id);
      $schedule_date = $option['date'] ?? '';
      $schedule_time = $option['time'] ?? '';
      if (!$schedule_label && !empty($option['label'])) {
        $schedule_label = $option['label'];
      }
    }
    if (!$schedule_date && !$schedule_time && $event_id) {
      $event_dt = WC_Cart::get_event_datetime($event_id);
      $schedule_date = $event_dt['date'] ?? '';
      $schedule_time = $event_dt['time'] ?? '';
      if (!$schedule_label && !empty($event_dt['label'])) {
        $schedule_label = $event_dt['label'];
      }
    }

    $links = Customer_Tickets_Print::build_ticket_access_links($ticket);
    $slot = self::build_attendee_from_row($ticket, [
      'name' => (string) ($ticket->attendee_name ?? ''),
      'email' => (string) ($ticket->attendee_email ?? ''),
      'phone' => (string) ($ticket->attendee_phone ?? ''),
    ]);

    $status = (string) ($ticket->status ?? 'transferred');
    $status_label = $status === 'transferred'
      ? __('Transferred', 'koopo-tickets')
      : ucfirst($status);

    return [
      'order_id' => (int) $order->get_id(),
      'order_number' => (string) $order->get_order_number(),
      'item_id' => (int) $item->get_id(),
      'ticket_name' => (string) $item->get_name(),
      'quantity' => 1,
      'event_id' => $event_id,
      'event_title' => $event_id ? wp_strip_all_tags((string) get_the_title($event_id)) : '',
      'event_url' => $event_id ? esc_url_raw((string) get_permalink($event_id)) : '',
      'event_image' => $event_id ? esc_url_raw((string) get_the_post_thumbnail_url($event_id, 'medium')) : '',
      'event_location' => $event_id ? WC_Cart::get_event_location($event_id) : '',
      'schedule_label' => $schedule_label,
      'schedule_date' => $schedule_date,
      'schedule_time' => $schedule_time,
      'contact_name' => (string) ($ticket->attendee_name ?? ''),
      'contact_email' => (string) ($ticket->attendee_email ?? ''),
      'contact_phone' => (string) ($ticket->attendee_phone ?? ''),
      'guests' => [],
      'attendees' => [$slot],
      'status' => $status,
      'status_label' => $status_label,
      'view_url' => $links['view'],
      'print_url' => $links['print'],
      'download_url' => $links['download'],
      'ownership' => 'recipient',
      'can_manage_guests' => false,
    ];
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
      $guest['guest_slot_index'] = $i;
      $guest['attendee_index'] = $i + 2;
      $slots[] = self::build_attendee($guest, sprintf(__('Guest %d', 'koopo-tickets'), $i + 1));
    }

    return $slots;
  }

  private static function build_attendee_slots_from_rows(array $rows, \WC_Order_Item_Product $item, array $transfer_map = []): array {
    $contact = [
      'name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
      'email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
      'phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
    ];

    $slots = [];
    foreach ($rows as $row) {
      $slots[] = self::build_attendee_from_row($row, $contact, $transfer_map[(int) ($row->id ?? 0)] ?? null);
    }

    return $slots;
  }

  private static function filter_owner_visible_ticket_rows(array $rows, array $transfer_map): array {
    $visible = [];

    foreach ($rows as $row) {
      $ticket_id = (int) ($row->id ?? 0);
      $transfer = $transfer_map[$ticket_id] ?? null;
      if ($transfer && (string) ($transfer->status ?? '') === 'accepted') {
        continue;
      }

      $visible[] = $row;
    }

    return $visible;
  }

  private static function build_guest_list_from_rows(array $rows): array {
    $guests = [];

    foreach ($rows as $row) {
      if ((int) ($row->attendee_index ?? 0) <= 1) {
        continue;
      }

      $guests[] = [
        'slot_index' => max(0, (int) ($row->attendee_index ?? 0) - 2),
        'user_id' => (int) ($row->attendee_user_id ?? 0),
        'name' => sanitize_text_field((string) ($row->attendee_name ?? '')),
        'email' => sanitize_email((string) ($row->attendee_email ?? '')),
        'phone' => sanitize_text_field((string) ($row->attendee_phone ?? '')),
      ];
    }

    return $guests;
  }

  private static function build_attendee_from_row(object $row, array $contact, ?object $transfer = null): array {
    $name = sanitize_text_field($row->attendee_name ?? '');
    $email = sanitize_email($row->attendee_email ?? '');
    $phone = sanitize_text_field($row->attendee_phone ?? '');
    if ((int) $row->attendee_index === 1 && !$name && !empty($contact['name'])) {
      $name = $contact['name'];
      $email = $email ?: $contact['email'];
      $phone = $phone ?: $contact['phone'];
    }

    $label = $name ?: sprintf(__('Guest %d', 'koopo-tickets'), (int) $row->attendee_index);
    if ((int) $row->attendee_index === 1) {
      $label = $name ?: __('You', 'koopo-tickets');
    }

    $avatar = '';
    $user_id = (int) ($row->attendee_user_id ?? 0);
    $user = $user_id ? get_user_by('id', $user_id) : false;
    if (!$user && $email) {
      $user = get_user_by('email', $email);
      if ($user) {
        $user_id = (int) $user->ID;
      }
    }
    if ($email || $user) {
      if ($user && function_exists('bp_core_fetch_avatar')) {
        $avatar = esc_url_raw(bp_core_fetch_avatar([
          'item_id' => $user->ID,
          'type' => 'thumb',
          'width' => 64,
          'height' => 64,
          'html' => false,
        ]));
      } else {
        $avatar = esc_url_raw(get_avatar_url($email, ['size' => 64]));
      }
    }

    return [
      'ticket_id' => (int) $row->id,
      'ticket_status' => (string) $row->status,
      'attendee_index' => (int) $row->attendee_index,
      'guest_slot_index' => (int) $row->attendee_index > 1 ? ((int) $row->attendee_index - 2) : -1,
      'label' => $label,
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'avatar' => $avatar,
      'user_id' => $user_id,
      'transfer' => Customer_Ticket_Transfers::format_transfer($transfer),
    ];
  }

  private static function build_attendee(array $data, string $fallback_label): array {
    $name = sanitize_text_field($data['name'] ?? '');
    $email = sanitize_email($data['email'] ?? '');
    $phone = sanitize_text_field($data['phone'] ?? '');
    $label = $name ?: $fallback_label;

    $avatar = '';
    $user_id = absint($data['user_id'] ?? 0);
    $user = $user_id ? get_user_by('id', $user_id) : null;
    if (!$user && $email) {
      $user = get_user_by('email', $email);
      if ($user) {
        $user_id = (int) $user->ID;
      }
    }
    if ($email || $user) {
      if ($user && function_exists('bp_core_fetch_avatar')) {
        $avatar = esc_url_raw(bp_core_fetch_avatar([
          'item_id' => $user->ID,
          'type' => 'thumb',
          'width' => 64,
          'height' => 64,
          'html' => false,
        ]));
      } else {
        $avatar = esc_url_raw(get_avatar_url($email, ['size' => 64]));
      }
    }

    return [
      'ticket_id' => 0,
      'ticket_status' => 'issued',
      'attendee_index' => absint($data['attendee_index'] ?? 0),
      'guest_slot_index' => array_key_exists('guest_slot_index', $data) ? absint($data['guest_slot_index']) : -1,
      'label' => $label,
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'avatar' => $avatar,
      'user_id' => $user_id,
      'transfer' => null,
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

  private static function get_ticket_rows(int $item_id): array {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY attendee_index ASC", $item_id)
    );
  }

  private static function get_ticket_by_id(int $ticket_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $ticket_id)
    );
  }

  private static function get_ticket_by_index(int $item_id, int $attendee_index) {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$table} WHERE order_item_id = %d AND attendee_index = %d LIMIT 1", $item_id, $attendee_index)
    );
  }

  private static function get_ticket_by_id_for_item(int $ticket_id, int $item_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND order_item_id = %d LIMIT 1", $ticket_id, $item_id)
    );
  }

  private static function derive_ticket_status(array $rows): string {
    $statuses = array_map(fn($row) => (string) $row->status, $rows);
    if (in_array('redeemed', $statuses, true)) return 'redeemed';
    if (!empty($statuses) && count(array_unique($statuses)) === 1 && $statuses[0] === 'transferred') return 'transferred';
    if (in_array('refunded', $statuses, true)) return 'refunded';
    if (in_array('cancelled', $statuses, true)) return 'cancelled';
    return 'issued';
  }

  private static function sync_ticket_rows_with_guests(\WC_Order_Item_Product $item, array $guests): void {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';

    foreach ($guests as $index => $guest) {
      $attendee_index = $index + 2;
      $row = self::get_ticket_by_index((int) $item->get_id(), $attendee_index);
      if (!$row) {
        continue;
      }
      if ((string) ($row->status ?? '') === 'transferred') {
        continue;
      }
      $locked_transfer = Customer_Ticket_Transfers::get_transfer_by_ticket_id((int) ($row->id ?? 0), ['pending', 'accepted']);
      if ($locked_transfer) {
        continue;
      }
      $name = sanitize_text_field($guest['name'] ?? '');
      $email = sanitize_email($guest['email'] ?? '');
      $phone = sanitize_text_field($guest['phone'] ?? '');
      $wpdb->update($table, [
        'attendee_name' => $name,
        'attendee_email' => $email,
        'attendee_phone' => $phone,
        'attendee_user_id' => absint($guest['user_id'] ?? 0),
        'updated_at' => gmdate('Y-m-d H:i:s'),
      ], [
        'id' => (int) $row->id,
      ], ['%s', '%s', '%s', '%d', '%s'], ['%d']);
    }
  }

  private static function update_ticket_recipient(object $ticket, array $data): void {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    $status = $ticket->status;
    if (!empty($data['user_id'])) {
      $status = 'transferred';
    }

    $wpdb->update($table, [
      'attendee_name' => $data['name'] ?? '',
      'attendee_email' => $data['email'] ?? '',
      'attendee_phone' => $data['phone'] ?? '',
      'attendee_user_id' => absint($data['user_id'] ?? 0),
      'status' => $status,
      'updated_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => (int) $ticket->id], ['%s', '%s', '%s', '%d', '%s', '%s'], ['%d']);

    $item = new \WC_Order_Item_Product($ticket->order_item_id);
    if ($item && $item->get_id() && (int) $ticket->attendee_index > 1) {
      $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
      $guests = $guests_raw ? json_decode($guests_raw, true) : [];
      if (!is_array($guests)) $guests = [];
      $guest_index = max(0, (int) $ticket->attendee_index - 2);
      $guests[$guest_index] = [
        'user_id' => absint($data['user_id'] ?? 0),
        'name' => $data['name'] ?? '',
        'email' => $data['email'] ?? '',
        'phone' => $data['phone'] ?? '',
      ];
      $item->update_meta_data('_koopo_ticket_guests', wp_json_encode($guests));
      $item->save();
    }
  }
}
