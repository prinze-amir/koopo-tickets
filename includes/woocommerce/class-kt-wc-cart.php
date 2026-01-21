<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class WC_Cart {
  public static function init() {
    add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 2);
    add_filter('woocommerce_get_item_data', [__CLASS__, 'display_item_data'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_order_item_meta'], 10, 4);
    add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 3);
  }

  public static function validate_add_to_cart($passed, $product_id, $quantity) {
    if (empty($_POST['koopo_ticket_contact_name']) || empty($_POST['koopo_ticket_contact_email'])) {
      wc_add_notice(__('Contact name and email are required.', 'koopo-tickets'), 'error');
      return false;
    }

    if (!empty($_POST['koopo_ticket_require_schedule']) && empty($_POST['koopo_ticket_schedule_id'])) {
      wc_add_notice(__('Please select an event date.', 'koopo-tickets'), 'error');
      return false;
    }

    $ticket_type_id = absint($_POST['koopo_ticket_type_id'] ?? 0);
    $max_per_order = $ticket_type_id ? (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_MAX_PER_ORDER, true) : 0;
    if ($max_per_order && $quantity > $max_per_order) {
      wc_add_notice(__('Selected quantity exceeds the ticket limit.', 'koopo-tickets'), 'error');
      return false;
    }

    if (!$max_per_order) {
      $global_max = (int) Settings::get('max_tickets_per_order');
      if ($global_max && $quantity > $global_max) {
        wc_add_notice(__('Selected quantity exceeds the order limit.', 'koopo-tickets'), 'error');
        return false;
      }
    }

    return $passed;
  }

  public static function add_cart_item_data($cart_item_data, $product_id) {
    if (empty($_POST['koopo_ticket_contact_name']) && empty($_POST['koopo_ticket_contact_email'])) {
      return $cart_item_data;
    }

    $cart_item_data['koopo_ticket_contact_name'] = sanitize_text_field(wp_unslash($_POST['koopo_ticket_contact_name'] ?? ''));
    $cart_item_data['koopo_ticket_contact_email'] = sanitize_email(wp_unslash($_POST['koopo_ticket_contact_email'] ?? ''));
    $cart_item_data['koopo_ticket_contact_phone'] = sanitize_text_field(wp_unslash($_POST['koopo_ticket_contact_phone'] ?? ''));
    $cart_item_data['koopo_ticket_event_id'] = absint($_POST['koopo_ticket_event_id'] ?? 0);
    $cart_item_data['koopo_ticket_type_id'] = absint($_POST['koopo_ticket_type_id'] ?? 0);
    $cart_item_data['koopo_ticket_schedule_id'] = absint($_POST['koopo_ticket_schedule_id'] ?? 0);
    $cart_item_data['koopo_ticket_schedule_label'] = sanitize_text_field(wp_unslash($_POST['koopo_ticket_schedule_label'] ?? ''));
    $cart_item_data['koopo_ticket_guests'] = self::sanitize_guests($_POST['koopo_ticket_guests'] ?? '');

    $cart_item_data['koopo_ticket_key'] = wp_generate_password(12, false);
    $cart_item_data['unique_key'] = md5($cart_item_data['koopo_ticket_key'] . microtime(true));

    return $cart_item_data;
  }

  public static function display_item_data($item_data, $cart_item) {
    if (!empty($cart_item['koopo_ticket_schedule_label'])) {
      $item_data[] = [
        'name' => __('Event Date', 'koopo-tickets'),
        'value' => $cart_item['koopo_ticket_schedule_label'],
      ];
    }

    if (!empty($cart_item['koopo_ticket_event_id'])) {
      $location = self::get_event_location((int) $cart_item['koopo_ticket_event_id']);
      if ($location) {
        $item_data[] = [
          'name' => __('Location', 'koopo-tickets'),
          'value' => $location,
        ];
      }
    }

    return $item_data;
  }

  public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
    $hidden = [
      '_koopo_ticket_event_id' => $values['koopo_ticket_event_id'] ?? null,
      '_koopo_ticket_type_id' => $values['koopo_ticket_type_id'] ?? null,
      '_koopo_ticket_schedule_id' => $values['koopo_ticket_schedule_id'] ?? null,
      '_koopo_ticket_schedule_label' => $values['koopo_ticket_schedule_label'] ?? null,
    ];

    foreach ($hidden as $key => $value) {
      if (!empty($value)) {
        $item->add_meta_data($key, $value, true);
      }
    }

    if (!empty($values['koopo_ticket_contact_name'])) {
      $item->add_meta_data('_koopo_ticket_contact_name', $values['koopo_ticket_contact_name'], true);
    }
    if (!empty($values['koopo_ticket_contact_email'])) {
      $item->add_meta_data('_koopo_ticket_contact_email', $values['koopo_ticket_contact_email'], true);
    }
    if (!empty($values['koopo_ticket_contact_phone'])) {
      $item->add_meta_data('_koopo_ticket_contact_phone', $values['koopo_ticket_contact_phone'], true);
    }
    if (!empty($values['koopo_ticket_guests'])) {
      $item->add_meta_data('_koopo_ticket_guests', wp_json_encode($values['koopo_ticket_guests']), true);
    }
  }

  private static function sanitize_guests($raw): array {
    if (empty($raw)) return [];
    if (is_array($raw)) return $raw;

    $decoded = json_decode(wp_unslash($raw), true);
    if (!is_array($decoded)) return [];

    $out = [];
    foreach ($decoded as $guest) {
      if (!is_array($guest)) continue;
      $out[] = [
        'name' => sanitize_text_field($guest['name'] ?? ''),
        'email' => sanitize_email($guest['email'] ?? ''),
        'phone' => sanitize_text_field($guest['phone'] ?? ''),
        'ticket_type_id' => absint($guest['ticket_type_id'] ?? 0),
        'ticket_name' => sanitize_text_field($guest['ticket_name'] ?? ''),
      ];
    }

    return $out;
  }

  public static function get_event_location(int $event_id): string {
    if (!$event_id || !function_exists('geodir_get_post_meta')) return '';

    $address = geodir_get_post_meta($event_id, 'address', true);
    if ($address) return (string) $address;

    $city = geodir_get_post_meta($event_id, 'city', true);
    $region = geodir_get_post_meta($event_id, 'region', true);
    $zip = geodir_get_post_meta($event_id, 'zip', true);
    $country = geodir_get_post_meta($event_id, 'country', true);

    $parts = array_filter([$city, $region, $zip, $country]);
    return $parts ? implode(', ', $parts) : '';
  }
}
