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
    return $vars;
  }

  public static function maybe_render(): void {
    $item_id = absint(get_query_var('koopo_ticket_print'));
    if (!$item_id) return;

    if (!is_user_logged_in()) {
      wp_die(__('Please log in to view tickets.', 'koopo-tickets'));
    }

    $item = new \WC_Order_Item_Product($item_id);
    if (!$item || !$item->get_id()) {
      wp_die(__('Ticket not found.', 'koopo-tickets'));
    }

    $order = wc_get_order($item->get_order_id());
    if (!$order || (int) $order->get_user_id() !== get_current_user_id()) {
      wp_die(__('Ticket not found.', 'koopo-tickets'));
    }

    $event_id = (int) $item->get_meta('_koopo_ticket_event_id');
    $schedule_label = (string) $item->get_meta('_koopo_ticket_schedule_label');
    $location = WC_Cart::get_event_location($event_id);

    $contact = [
      'name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
      'email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
      'phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
    ];

    $codes = self::build_attendee_codes($item, $contact);
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

  private static function build_code(int $item_id, int $index): string {
    return 'KT-' . $item_id . '-' . $index;
  }

  private static function build_attendee_codes(\WC_Order_Item_Product $item, array $contact): array {
    global $wpdb;
    $table = $wpdb->prefix . 'koopo_tickets';
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY attendee_index ASC",
        $item->get_id()
      )
    );

    if (!empty($rows)) {
      $codes = [];
      foreach ($rows as $row) {
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
          'qr_data' => 'KTID:' . (int) $row->id,
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
      $payload = isset($entry['qr_data']) ? (string) $entry['qr_data'] : (string) $entry['code'];
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
        $prev = null;
        try {
          $prev = set_error_handler(function ($severity, $message) {
            throw new \RuntimeException($message, $severity);
          });
          $qr = new \geodir_tickets\QRCode();
          $qr->setTypeNumber($i);
          $qr->setErrorCorrectLevel($level);
          $qr->addData($data);
          $qr->make();
          ob_start();
          $qr->printSVG(3);
          if ($prev) set_error_handler($prev);
          return ob_get_clean();
        } catch (\Throwable $e) {
          if ($prev) set_error_handler($prev);
          continue;
        }
      }
    }

    return '';
  }
}
