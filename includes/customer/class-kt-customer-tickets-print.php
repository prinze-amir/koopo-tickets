<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Customer_Tickets_Print {
  public static function init(): void {
    add_filter('query_vars', [__CLASS__, 'register_query_var']);
    add_action('template_redirect', [__CLASS__, 'maybe_render']);
  }

  public static function register_query_var(array $vars): array {
    $vars[] = 'koopo_ticket_print';
    $vars[] = 'koopo_ticket_access';
    $vars[] = 'kt_token';
    return $vars;
  }

  public static function maybe_render(): void {
    $item_id = absint(get_query_var('koopo_ticket_print'));
    $ticket_id = absint(get_query_var('koopo_ticket_access'));
    $token = sanitize_text_field((string) (get_query_var('kt_token') ?: wp_unslash($_GET['kt_token'] ?? '')));

    $specific_ticket = null;
    if ($ticket_id) {
      $specific_ticket = self::get_ticket_by_id($ticket_id);
      if (!$specific_ticket) {
        wp_die(__('Ticket not found.', 'koopo-tickets'));
      }
      $item_id = (int) $specific_ticket->order_item_id;
    }

    if (!$item_id) {
      return;
    }

    if (!$specific_ticket && !is_user_logged_in()) {
      wp_die(__('Please log in to view tickets.', 'koopo-tickets'));
    }

    $item = new \WC_Order_Item_Product($item_id);
    if (!$item || !$item->get_id()) {
      wp_die(__('Ticket not found.', 'koopo-tickets'));
    }

    $order = wc_get_order($item->get_order_id());
    if (
      !$order
      || !self::current_user_can_view_ticket(
        $order,
        $specific_ticket,
        $token
      )
    ) {
      wp_die(__('Ticket not found.', 'koopo-tickets'));
    }

    $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
    $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');
    if (!$schedule_label && $event_id) {
      $option = WC_Cart::get_event_date_option($event_id, (int) $item->get_meta('_koopo_ticket_schedule_id'));
      if (!empty($option['label'])) {
        $schedule_label = (string) $option['label'];
      } else {
        $event_dt = WC_Cart::get_event_datetime($event_id);
        $schedule_label = (string) ($event_dt['label'] ?? '');
      }
    }
    $location = WC_Cart::get_event_location($event_id);

    $contact = [
      'name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
      'email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
      'phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
    ];

    $codes = self::build_attendee_codes($item, $contact, $specific_ticket ? (int) $specific_ticket->id : 0);
    $logo = self::get_site_logo();

    $data = [
      'item_id' => $item_id,
      'order_id' => $order->get_id(),
      'ticket_name' => $item->get_name(),
      'event_id' => $event_id,
      'event_title' => $event_id ? get_the_title($event_id) : '',
      'event_url' => $event_id ? get_permalink($event_id) : '',
      'event_image' => $event_id ? get_the_post_thumbnail_url($event_id, 'large') : '',
      'schedule_label' => $schedule_label,
      'location' => $location,
      'codes' => $codes,
      'logo' => $logo,
    ];

    $template = KOOPO_TICKETS_PATH . 'templates/customer/print-ticket.php';
    if (!file_exists($template)) {
      wp_die(__('Print template not found.', 'koopo-tickets'));
    }

    require_once KOOPO_TICKETS_PATH . 'includes/lib/kt-qrcode.php';
    wp_enqueue_style('koopo-ticket-print', KOOPO_TICKETS_URL . 'assets/print-ticket.css', [], KOOPO_TICKETS_VERSION);

    $data['qr_svgs'] = self::build_qr_svgs($codes);
    $print_data = $data;
    include $template;
    exit;
  }

  public static function build_order_item_links(int $item_id): array {
    $base = add_query_arg(['koopo_ticket_print' => $item_id], home_url('/'));
    return self::build_links_from_base($base);
  }

  public static function build_ticket_access_links(object $ticket): array {
    $base = add_query_arg([
      'koopo_ticket_access' => (int) ($ticket->id ?? 0),
      'kt_token' => self::build_ticket_access_token($ticket),
    ], home_url('/'));

    return self::build_links_from_base($base);
  }

  private static function build_code(int $item_id, int $index): string {
    return 'KT-' . $item_id . '-' . $index;
  }

  private static function build_attendee_codes(\WC_Order_Item_Product $item, array $contact, int $ticket_id = 0): array {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY attendee_index ASC",
        $item->get_id()
      )
    );

    if (!empty($rows)) {
      $transfer_map = Customer_Ticket_Transfers::get_transfers_by_ticket_ids(array_map(function ($row) {
        return (int) ($row->id ?? 0);
      }, $rows), ['accepted']);
      $owner_user_id = 0;
      $order = wc_get_order($item->get_order_id());
      if ($order instanceof \WC_Order) {
        $owner_user_id = (int) $order->get_user_id();
      }
      $viewer_user_id = get_current_user_id();
      $codes = [];
      foreach ($rows as $row) {
        if ($ticket_id > 0 && (int) $row->id !== $ticket_id) {
          continue;
        }

        $transfer = $transfer_map[(int) ($row->id ?? 0)] ?? null;
        if ($ticket_id === 0 && self::viewer_is_original_owner($viewer_user_id, $owner_user_id) && $transfer) {
          continue;
        }

        $label = $row->attendee_name ?: sprintf(__('Guest %d', 'koopo-tickets'), (int) $row->attendee_index);
        if ((int) $row->attendee_index === 1 && $contact['name']) {
          $label = $contact['name'];
        }
        $codes[] = [
          'id' => (int) $row->id,
          'label' => $label,
          'email' => (string) $row->attendee_email,
          'phone' => (string) $row->attendee_phone,
          'code' => (string) $row->code,
          'qr_data' => (string) (int) $row->id,
          'verify_url' => self::build_verification_url((string) $row->code ?: (string) (int) $row->id),
        ];
      }
      return $codes;
    }

    $quantity = (int) $item->get_quantity();
    $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
    $guests = $guests_raw ? json_decode($guests_raw, true) : [];
    if (!is_array($guests)) $guests = [];

    $codes = [];
    $codes[] = [
      'id' => 0,
      'label' => $contact['name'] ?: __('You', 'koopo-tickets'),
      'email' => $contact['email'],
      'phone' => $contact['phone'],
      'code' => self::build_code($item->get_id(), 1),
      'qr_data' => self::build_code($item->get_id(), 1),
      'verify_url' => self::build_verification_url(self::build_code($item->get_id(), 1)),
    ];

    for ($i = 0; $i < max(0, $quantity - 1); $i++) {
      $guest = $guests[$i] ?? [];
      $label = sanitize_text_field($guest['name'] ?? '') ?: sprintf(__('Guest %d', 'koopo-tickets'), $i + 1);
      $codes[] = [
        'id' => 0,
        'label' => $label,
        'email' => sanitize_email($guest['email'] ?? ''),
        'phone' => sanitize_text_field($guest['phone'] ?? ''),
        'code' => self::build_code($item->get_id(), $i + 2),
        'qr_data' => self::build_code($item->get_id(), $i + 2),
        'verify_url' => self::build_verification_url(self::build_code($item->get_id(), $i + 2)),
      ];
    }

    return $codes;
  }

  private static function get_site_logo(): string {
    $logo_id = get_theme_mod('custom_logo');
    if ($logo_id) {
      $url = wp_get_attachment_image_url($logo_id, 'medium');
      if ($url) return $url;
    }

    $icon = get_site_icon_url(128);
    return $icon ?: '';
  }

  private static function build_qr_svgs(array $codes): array {
    if (!class_exists('\geodir_tickets\QRCode')) return [];

    $svgs = [];
    foreach ($codes as $entry) {
      $payload = !empty($entry['verify_url'])
        ? (string) $entry['verify_url']
        : (isset($entry['qr_data']) ? (string) $entry['qr_data'] : (string) $entry['code']);
      $qr_svg = self::generate_qr_svg($payload);
      $svgs[] = $qr_svg;
    }

    return $svgs;
  }

  private static function generate_qr_svg(string $data): string {
    $levels = [QR_ERROR_CORRECT_LEVEL_L, QR_ERROR_CORRECT_LEVEL_M, QR_ERROR_CORRECT_LEVEL_Q, QR_ERROR_CORRECT_LEVEL_H];
    $type = 2;

    foreach ($levels as $level) {
      for ($i = $type; $i <= 10; $i++) {
        try {
          set_error_handler(function ($severity, $message) {
            throw new \RuntimeException($message, $severity);
          });
          $qr = new \geodir_tickets\QRCode();
          $qr->setTypeNumber($i);
          $qr->setErrorCorrectLevel($level);
          $qr->addData($data);
          $qr->make();
          ob_start();
          $qr->printSVG(3);
          return ob_get_clean();
        } catch (\Throwable $e) {
          continue;
        } finally {
          restore_error_handler();
        }
      }
    }

    return '';
  }

  private static function build_verification_url(string $payload): string {
    $base = '';
    if (function_exists('dokan_get_navigation_url')) {
      $base = dokan_get_navigation_url('koopo-tickets');
    }

    $base = apply_filters('koopo_tickets_verification_base_url', $base);
    if (!$base) {
      $base = home_url('/');
    }

    $url = add_query_arg(['kt_code' => $payload], $base);
    return (string) apply_filters('koopo_tickets_verification_url', $url, $payload);
  }

  private static function build_links_from_base(string $base): array {
    return [
      'view' => $base,
      'print' => add_query_arg(['kt_action' => 'print'], $base),
      'download' => add_query_arg(['kt_action' => 'download'], $base),
    ];
  }

  private static function build_ticket_access_token(object $ticket): string {
    $payload = implode('|', [
      (int) ($ticket->id ?? 0),
      (int) ($ticket->order_item_id ?? 0),
      strtolower(sanitize_email((string) ($ticket->attendee_email ?? ''))),
      (string) ($ticket->code ?? ''),
    ]);

    return hash_hmac('sha256', $payload, wp_salt('auth'));
  }

  private static function current_user_can_view_ticket(\WC_Order $order, ?object $ticket, string $token): bool {
    $user_id = get_current_user_id();
    if ($user_id && (int) $order->get_user_id() === $user_id && !self::ticket_is_accepted_transfer($ticket)) {
      return true;
    }

    if ($user_id && $ticket && (int) ($ticket->attendee_user_id ?? 0) === $user_id) {
      return true;
    }

    if (!$ticket || !$token) {
      return false;
    }

    return hash_equals(self::build_ticket_access_token($ticket), $token);
  }

  private static function viewer_is_original_owner(int $viewer_user_id, int $owner_user_id): bool {
    return $viewer_user_id > 0 && $owner_user_id > 0 && $viewer_user_id === $owner_user_id;
  }

  private static function ticket_is_accepted_transfer(?object $ticket): bool {
    if (!$ticket || empty($ticket->id)) {
      return false;
    }

    return (bool) Customer_Ticket_Transfers::get_transfer_by_ticket_id((int) $ticket->id, ['accepted']);
  }

  private static function get_ticket_by_id(int $ticket_id) {
    global $wpdb;

    if ($ticket_id < 1) {
      return null;
    }

    $table = $wpdb->prefix . 'koopo_tickets';
    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $ticket_id)
    );
  }
}
