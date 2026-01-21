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
    $guests_raw = (string) $item->get_meta('_koopo_ticket_guests');
    $guests = $guests_raw ? json_decode($guests_raw, true) : [];
    if (!is_array($guests)) $guests = [];

    $contact = [
      'name' => (string) $item->get_meta('_koopo_ticket_contact_name'),
      'email' => (string) $item->get_meta('_koopo_ticket_contact_email'),
      'phone' => (string) $item->get_meta('_koopo_ticket_contact_phone'),
    ];

    $codes = [];
    if (!empty($guests)) {
      foreach ($guests as $index => $guest) {
        $codes[] = [
          'label' => $guest['name'] ?? 'Guest ' . ($index + 1),
          'email' => $guest['email'] ?? '',
          'phone' => $guest['phone'] ?? '',
          'code' => self::build_code($item_id, $index + 1),
        ];
      }
    } else {
      $codes[] = [
        'label' => $contact['name'] ?: __('Attendee', 'koopo-tickets'),
        'email' => $contact['email'],
        'phone' => $contact['phone'],
        'code' => self::build_code($item_id, 1),
      ];
    }

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

  private static function build_qr_svgs(array $codes): array {
    if (!class_exists('\geodir_tickets\QRCode')) return [];

    $svgs = [];
    foreach ($codes as $entry) {
      $qr = new \geodir_tickets\QRCode();
      $qr->addData($entry['code']);
      $qr->make();
      ob_start();
      $qr->printSVG(3);
      $svgs[] = ob_get_clean();
    }

    return $svgs;
  }
}
