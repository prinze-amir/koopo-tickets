<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class WC_Cart {
  public static function init() {
    add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 2);
    add_filter('woocommerce_get_item_data', [__CLASS__, 'display_item_data'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'add_order_item_meta'], 10, 4);
    add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 3);
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_schedule_pricing'], 20);
  }

  public static function validate_add_to_cart($passed, $product_id, $quantity) {
    if (!self::is_ticket_product_request((int) $product_id)) {
      return $passed;
    }

    if (!$passed) {
      return false;
    }

    $contact_name = sanitize_text_field(wp_unslash($_REQUEST['koopo_ticket_contact_name'] ?? ''));
    $contact_email = sanitize_email(wp_unslash($_REQUEST['koopo_ticket_contact_email'] ?? ''));

    if ($contact_name === '' || $contact_email === '') {
      wc_add_notice(__('Contact name and email are required.', 'koopo-tickets'), 'error');
      return false;
    }

    if (!empty($_REQUEST['koopo_ticket_require_schedule']) && empty($_REQUEST['koopo_ticket_schedule_id'])) {
      wc_add_notice(__('Please select an event date.', 'koopo-tickets'), 'error');
      return false;
    }

    $ticket_type_id = absint($_REQUEST['koopo_ticket_type_id'] ?? 0);
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

  private static function is_ticket_product_request(int $product_id): bool {
    if (!empty($_REQUEST['koopo_ticket_type_id']) || !empty($_REQUEST['koopo_ticket_event_id'])) {
      return true;
    }

    $variation_id = absint($_REQUEST['variation_id'] ?? 0);
    $candidate_id = $variation_id ?: $product_id;
    if ($candidate_id) {
      $ticket_type_id = (int) get_post_meta($candidate_id, WC_Ticket_Product::META_TICKET_TYPE_ID, true);
      if ($ticket_type_id) {
        return true;
      }
    }

    $product = $product_id ? wc_get_product($product_id) : null;
    if ($product && $product instanceof \WC_Product_Variable) {
      foreach ($product->get_children() as $child_id) {
        if ((int) get_post_meta($child_id, WC_Ticket_Product::META_TICKET_TYPE_ID, true)) {
          return true;
        }
      }
    }

    return false;
  }

  public static function add_cart_item_data($cart_item_data, $product_id) {
    if (empty($_POST['koopo_ticket_contact_name']) && empty($_POST['koopo_ticket_contact_email'])) {
      return $cart_item_data;
    }

    $cart_item_data['koopo_ticket_contact_name'] = sanitize_text_field(wp_unslash($_POST['koopo_ticket_contact_name'] ?? ''));
    $cart_item_data['koopo_ticket_contact_email'] = sanitize_email(wp_unslash($_POST['koopo_ticket_contact_email'] ?? ''));
    $cart_item_data['koopo_ticket_contact_phone'] = sanitize_text_field(wp_unslash($_POST['koopo_ticket_contact_phone'] ?? ''));
    $event_id = absint($_POST['koopo_ticket_event_id'] ?? 0);
    $schedule_id = absint($_POST['koopo_ticket_schedule_id'] ?? 0);
    $schedule_label = sanitize_text_field(wp_unslash($_POST['koopo_ticket_schedule_label'] ?? ''));

    if (!$schedule_label && $event_id) {
      if ($schedule_id) {
        $option = self::get_event_date_option($event_id, $schedule_id);
        $schedule_label = $option['label'] ?? '';
      }
      if (!$schedule_label) {
        $event_dt = self::get_event_datetime($event_id);
        $schedule_label = $event_dt['label'] ?? '';
      }
    }

    $cart_item_data['koopo_ticket_event_id'] = $event_id;
    $cart_item_data['koopo_ticket_type_id'] = absint($_POST['koopo_ticket_type_id'] ?? 0);
    $cart_item_data['koopo_ticket_schedule_id'] = $schedule_id;
    $cart_item_data['koopo_ticket_schedule_label'] = $schedule_label;
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

    $event_label = $values['koopo_ticket_schedule_label'] ?? '';
    if (!$event_label && !empty($values['koopo_ticket_event_id'])) {
      $event_id = (int) $values['koopo_ticket_event_id'];
      $schedule_id = (int) ($values['koopo_ticket_schedule_id'] ?? 0);
      if ($schedule_id) {
        $option = self::get_event_date_option($event_id, $schedule_id);
        $event_label = $option['label'] ?? '';
      }
      if (!$event_label) {
        $event_dt = self::get_event_datetime($event_id);
        $event_label = $event_dt['label'] ?? '';
      }
    }
    if ($event_label) {
      $item->add_meta_data(__('Event Date/Time', 'koopo-tickets'), $event_label, true);
    }
  }

  public static function apply_schedule_pricing($cart): void {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!$cart || !method_exists($cart, 'get_cart')) return;

    foreach ($cart->get_cart() as $cart_item) {
      $ticket_type_id = (int) ($cart_item['koopo_ticket_type_id'] ?? 0);
      $schedule_id = $cart_item['koopo_ticket_schedule_id'] ?? '';
      $schedule_label = (string) ($cart_item['koopo_ticket_schedule_label'] ?? '');
      if (!$ticket_type_id) continue;
      if (!$schedule_id && $schedule_label === '') continue;

      $prices = get_post_meta($ticket_type_id, Ticket_Types_API::META_DATE_PRICES, true);
      if (!is_array($prices)) continue;

      $key = $schedule_id ? (string) $schedule_id : '';
      if (!array_key_exists($key, $prices) && $schedule_label !== '') {
        $label_key = trim($schedule_label);
        if (array_key_exists($label_key, $prices)) {
          $key = $label_key;
        } else {
          $label_key = wp_strip_all_tags($label_key);
          if (array_key_exists($label_key, $prices)) {
            $key = $label_key;
          }
        }
      }
      if (!array_key_exists($key, $prices)) continue;

      $price = $prices[$key];
      if ($price === '' || $price === null) continue;

      $price = (float) $price;
      if ($price < 0) continue;

      if (!empty($cart_item['data']) && is_object($cart_item['data']) && method_exists($cart_item['data'], 'set_price')) {
        $cart_item['data']->set_price($price);
      }
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
    if (!$event_id) return '';

    $address = self::safe_geodir_meta($event_id, 'address');
    if ($address) return (string) $address;

    $city = self::safe_geodir_meta($event_id, 'city');
    $region = self::safe_geodir_meta($event_id, 'region');
    $zip = self::safe_geodir_meta($event_id, 'zip');
    $country = self::safe_geodir_meta($event_id, 'country');

    $parts = array_filter([$city, $region, $zip, $country]);
    return $parts ? implode(', ', $parts) : '';
  }

  public static function get_event_datetime(int $event_id): array {
    if (!$event_id) {
      return ['date' => '', 'time' => '', 'label' => ''];
    }

    $options = self::get_event_date_options($event_id);
    if (count($options) === 1) {
      return [
        'date' => $options[0]['date'] ?? '',
        'time' => $options[0]['time'] ?? '',
        'label' => $options[0]['label'] ?? '',
      ];
    }

    return ['date' => '', 'time' => '', 'label' => ''];
  }

  public static function get_event_date_options(int $event_id): array {
    if (!$event_id) return [];

    $dates = self::normalize_event_dates_meta($event_id);
    if (!empty($dates)) return $dates;

    $schedules = self::normalize_event_schedules($event_id);
    if (!empty($schedules)) return $schedules;

    $single = self::normalize_single_event_datetime($event_id);
    return $single ? [$single] : [];
  }

  public static function get_event_date_option(int $event_id, int $schedule_id): array {
    if (!$event_id || !$schedule_id) return [];

    $options = self::get_event_date_options($event_id);
    foreach ($options as $option) {
      if ((int) ($option['schedule_id'] ?? 0) === (int) $schedule_id) {
        return $option;
      }
    }

    if (class_exists('GeoDir_Event_Schedules')) {
      $schedule = \GeoDir_Event_Schedules::get_schedule($schedule_id);
      if ($schedule && !empty($schedule->start_date)) {
        $start_time = $schedule->start_time ?? '00:00:00';
        $end_date = !empty($schedule->end_date) && $schedule->end_date !== '0000-00-00' ? $schedule->end_date : $schedule->start_date;
        $end_time = $schedule->end_time ?? '';
        $start_ts = strtotime($schedule->start_date . ' ' . $start_time);
        $end_ts = $end_time ? strtotime($end_date . ' ' . $end_time) : 0;
        if ($start_ts) {
          $labels = self::format_event_datetime_labels($start_ts, $end_ts, !empty($schedule->all_day));
          return [
            'schedule_id' => absint($schedule->schedule_id ?? 0),
            'label' => $labels['label'],
            'date' => $labels['date'],
            'time' => $labels['time'],
            'start_ts' => $start_ts,
          ];
        }
      }
    }

    return [];
  }

  private static function normalize_event_dates_meta(int $event_id): array {
    $raw = get_post_meta($event_id, 'event_dates', true);
    if (empty($raw)) {
      $raw = self::safe_geodir_meta($event_id, 'event_dates');
    }
    if (empty($raw)) return [];

    if (is_string($raw)) {
      $maybe = maybe_unserialize($raw);
      if (is_array($maybe)) {
        $raw = $maybe;
      } else {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $raw = $decoded;
        }
      }
    }

    if (isset($raw['dates']) && is_array($raw['dates'])) {
      $raw = $raw['dates'];
    } elseif (isset($raw['event_dates']) && is_array($raw['event_dates'])) {
      $raw = $raw['event_dates'];
    }

    if (!is_array($raw)) return [];

    $out = [];
    foreach ($raw as $entry) {
      $normalized = self::normalize_event_date_entry($entry);
      if ($normalized) {
        $out[] = $normalized;
      }
    }

    usort($out, function ($a, $b) {
      return ($a['start_ts'] ?? 0) <=> ($b['start_ts'] ?? 0);
    });

    return $out;
  }

  private static function normalize_single_event_datetime(int $event_id): ?array {
    $start_dt = self::safe_geodir_meta($event_id, 'event_start_date_time');
    $end_dt = self::safe_geodir_meta($event_id, 'event_end_date_time');
    $start_date = self::safe_geodir_meta($event_id, 'event_start_date');
    $start_time = self::safe_geodir_meta($event_id, 'event_start_time');
    $end_date = self::safe_geodir_meta($event_id, 'event_end_date');
    $end_time = self::safe_geodir_meta($event_id, 'event_end_time');

    if (!$start_dt && $start_date) {
      $start_dt = trim($start_date . ' ' . $start_time);
    }
    if (!$end_dt && $end_date) {
      $end_dt = trim($end_date . ' ' . $end_time);
    }

    if (!$start_dt) return null;

    return self::normalize_event_date_entry([
      'start_date_time' => (string) $start_dt,
      'end_date_time' => (string) $end_dt,
    ]);
  }

  private static function normalize_event_schedules(int $event_id): array {
    if (!$event_id || !class_exists('GeoDir_Event_Schedules')) return [];

    $schedules = \GeoDir_Event_Schedules::get_schedules($event_id, '');
    if (empty($schedules) || !is_array($schedules)) return [];

    $out = [];
    foreach ($schedules as $schedule) {
      if (empty($schedule->start_date) || $schedule->start_date === '0000-00-00') continue;
      $start_time = $schedule->start_time ?? '00:00:00';
      $end_date = !empty($schedule->end_date) && $schedule->end_date !== '0000-00-00' ? $schedule->end_date : $schedule->start_date;
      $end_time = $schedule->end_time ?? '';
      $start_ts = strtotime($schedule->start_date . ' ' . $start_time);
      if (!$start_ts) continue;
      $end_ts = $end_time ? strtotime($end_date . ' ' . $end_time) : 0;
      $labels = self::format_event_datetime_labels($start_ts, $end_ts, !empty($schedule->all_day));
      $out[] = [
        'schedule_id' => absint($schedule->schedule_id ?? 0),
        'label' => $labels['label'],
        'date' => $labels['date'],
        'time' => $labels['time'],
        'start_ts' => $start_ts,
      ];
    }

    return $out;
  }

  private static function normalize_event_date_entry($entry): ?array {
    if (is_string($entry)) {
      $entry = ['start_date_time' => $entry];
    }
    if (!is_array($entry)) return null;

    $start_dt = self::first_entry_value($entry, ['start_date_time', 'start_datetime', 'event_start_date_time', 'start']);
    $end_dt = self::first_entry_value($entry, ['end_date_time', 'end_datetime', 'event_end_date_time', 'end']);

    $start_date = self::first_entry_value($entry, ['start_date', 'date']);
    $start_time = self::first_entry_value($entry, ['start_time', 'time']);
    if (!$start_dt && $start_date) {
      $start_dt = trim($start_date . ' ' . $start_time);
    }

    $end_date = self::first_entry_value($entry, ['end_date']);
    $end_time = self::first_entry_value($entry, ['end_time']);
    if (!$end_dt && $end_date && $end_time) {
      $end_dt = trim($end_date . ' ' . $end_time);
    }

    if (!$start_dt) return null;

    $start_ts = is_numeric($start_dt) ? (int) $start_dt : strtotime($start_dt);
    if (!$start_ts) return null;

    $end_ts = 0;
    if ($end_dt) {
      $end_ts = is_numeric($end_dt) ? (int) $end_dt : strtotime($end_dt);
    }
    if ($end_ts && $end_ts < $start_ts) {
      $end_ts = 0;
    }

    $all_day = !empty($entry['all_day']);

    $labels = self::format_event_datetime_labels($start_ts, $end_ts, $all_day);
    $schedule_id = 0;
    if (!empty($entry['schedule_id'])) {
      $schedule_id = absint($entry['schedule_id']);
    }
    if (!$schedule_id && !empty($entry['id'])) {
      $schedule_id = absint($entry['id']);
    }
    if (!$schedule_id) {
      $schedule_id = absint($start_ts);
    }
    if (!$schedule_id) {
      $schedule_id = absint(crc32((string) $start_dt));
    }

    return [
      'schedule_id' => $schedule_id,
      'label' => $labels['label'],
      'date' => $labels['date'],
      'time' => $labels['time'],
      'start_ts' => $start_ts,
    ];
  }

  private static function first_entry_value(array $entry, array $keys): string {
    foreach ($keys as $key) {
      if (isset($entry[$key]) && $entry[$key] !== '') {
        return (string) $entry[$key];
      }
    }
    return '';
  }

  private static function format_event_datetime_labels(int $start_ts, int $end_ts, bool $all_day): array {
    $date_format = function_exists('geodir_event_date_format') ? geodir_event_date_format() : 'Y-m-d';
    $time_format = function_exists('geodir_event_time_format') ? geodir_event_time_format() : 'H:i';

    $start_date = date_i18n($date_format, $start_ts);
    $end_date = $end_ts ? date_i18n($date_format, $end_ts) : '';
    $date_label = ($end_date && $end_date !== $start_date) ? ($start_date . ' - ' . $end_date) : $start_date;

    $time_label = '';
    if ($all_day) {
      $time_label = __('All day', 'koopo-tickets');
    } else {
      $time_label = date_i18n($time_format, $start_ts);
      if ($end_ts) {
        $time_label .= ' - ' . date_i18n($time_format, $end_ts);
      }
    }

    $label = trim($date_label . ($time_label ? ' ' . $time_label : ''));
    return [
      'date' => $date_label,
      'time' => $time_label,
      'label' => $label,
    ];
  }

  private static function safe_geodir_meta(int $event_id, string $key): string {
    if (!$event_id) return '';

    global $wpdb;
    try {
      if (function_exists('geodir_get_post_meta')) {
        $prev = $wpdb->suppress_errors(true);
        $value = geodir_get_post_meta($event_id, $key, true);
        $wpdb->suppress_errors($prev);
      } else {
        $value = get_post_meta($event_id, $key, true);
      }
    } finally {
      // no-op: suppression handled above
    }

    return is_scalar($value) ? (string) $value : '';
  }
}
