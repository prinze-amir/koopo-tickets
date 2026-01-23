<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Ticket_Validation {
  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/tickets/verify', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'verify_ticket'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);

    register_rest_route('koopo/v1', '/tickets/redeem', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'redeem_ticket'],
      'permission_callback' => [__CLASS__, 'can_manage'],
    ]);
  }

  public static function can_manage(): bool {
    return Access::vendor_can_manage_tickets();
  }

  public static function verify_ticket(\WP_REST_Request $req) {
    $code = sanitize_text_field((string) $req->get_param('code'));
    if (!$code) return new \WP_REST_Response(['error' => 'code is required'], 400);

    $ticket = self::resolve_ticket($code);
    if (!$ticket) return new \WP_REST_Response(['error' => 'Ticket not found'], 404);

    return new \WP_REST_Response(self::format_ticket($ticket), 200);
  }

  public static function redeem_ticket(\WP_REST_Request $req) {
    $code = sanitize_text_field((string) $req->get_param('code'));
    if (!$code) return new \WP_REST_Response(['error' => 'code is required'], 400);

    $ticket = self::resolve_ticket($code);
    if (!$ticket) return new \WP_REST_Response(['error' => 'Ticket not found'], 404);

    if ($ticket->status === 'redeemed') {
      return new \WP_REST_Response(['error' => 'Ticket already redeemed'], 409);
    }

    self::update_ticket_status($ticket->id, 'redeemed');
    $ticket->status = 'redeemed';

    return new \WP_REST_Response(self::format_ticket($ticket), 200);
  }

  private static function get_ticket_by_code(string $code) {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE code = %s LIMIT 1", $code));
  }

  private static function get_ticket_by_id(int $id) {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
  }

  private static function resolve_ticket(string $payload) {
    if (strpos($payload, 'KTID:') === 0) {
      $id = absint(substr($payload, 5));
      return $id ? self::get_ticket_by_id($id) : null;
    }
    if (ctype_digit($payload)) {
      $id = absint($payload);
      $ticket = self::get_ticket_by_id($id);
      if ($ticket) return $ticket;
    }
    return self::get_ticket_by_code($payload);
  }

  private static function update_ticket_status(int $ticket_id, string $status): void {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    $wpdb->update($table, [
      'status' => $status,
      'updated_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => $ticket_id], ['%s', '%s'], ['%d']);
  }

  private static function format_ticket($ticket): array {
    $event_title = $ticket->event_id ? get_the_title($ticket->event_id) : '';
    $schedule_label = (string) $ticket->schedule_label;
    $schedule_date = '';
    $schedule_time = '';
    $seat = '';
    if ($ticket->schedule_id && class_exists('GeoDir_Event_Schedules')) {
      $schedule = \GeoDir_Event_Schedules::get_schedule((int) $ticket->schedule_id);
      if ($schedule && !empty($schedule->start_date)) {
        $date_format = function_exists('geodir_event_date_format') ? geodir_event_date_format() : 'Y-m-d';
        $time_format = function_exists('geodir_event_time_format') ? geodir_event_time_format() : 'H:i';
        $schedule_date = date_i18n($date_format, strtotime($schedule->start_date));
        if (!empty($schedule->all_day)) {
          $schedule_time = __('All day', 'koopo-tickets');
        } else {
          $start_time = $schedule->start_time ?? '00:00:00';
          $end_time = $schedule->end_time ?? '';
          $schedule_time = date_i18n($time_format, strtotime($start_time));
          if ($end_time) {
            $schedule_time .= ' - ' . date_i18n($time_format, strtotime($end_time));
          }
        }
      }
    }
    if ($ticket->order_item_id) {
      $order_item = new \WC_Order_Item_Product((int) $ticket->order_item_id);
      if ($order_item && $order_item->get_id()) {
        $seat = (string) $order_item->get_meta('_koopo_ticket_seat');
        if (!$seat) {
          $seat = (string) $order_item->get_meta('_koopo_ticket_seat_label');
        }
      }
    }

    return [
      'id' => (int) $ticket->id,
      'code' => (string) $ticket->code,
      'status' => (string) $ticket->status,
      'event_id' => (int) $ticket->event_id,
      'event_title' => (string) $event_title,
      'ticket_type_id' => (int) $ticket->ticket_type_id,
      'attendee_name' => (string) $ticket->attendee_name,
      'attendee_email' => (string) $ticket->attendee_email,
      'attendee_phone' => (string) $ticket->attendee_phone,
      'schedule_label' => $schedule_label,
      'schedule_date' => $schedule_date,
      'schedule_time' => $schedule_time,
      'seat' => $seat,
      'order_id' => (int) $ticket->order_id,
      'order_item_id' => (int) $ticket->order_item_id,
    ];
  }
}
