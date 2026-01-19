<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Ticket_Cards {
  public static function init() {
    add_shortcode('koopo_ticket_cards', [__CLASS__, 'render_shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
  }

  public static function register_assets(): void {
    wp_register_style('koopo-ticket-cards', KOOPO_TICKETS_URL . 'assets/ticket-cards.css', [], KOOPO_TICKETS_VERSION);
    wp_register_script('koopo-ticket-cards', KOOPO_TICKETS_URL . 'assets/ticket-cards.js', ['jquery', 'wc-add-to-cart'], KOOPO_TICKETS_VERSION, true);
  }

  public static function render_shortcode($atts = []): string {
    if (!class_exists('WooCommerce')) return '';

    $atts = shortcode_atts([
      'event_id' => 0,
    ], $atts, 'koopo_ticket_cards');

    $event_id = absint($atts['event_id']);
    if (!$event_id) {
      $event_id = self::infer_event_id();
    }
    if (!$event_id) return '';

    $parent_id = (int) get_post_meta($event_id, WC_Ticket_Product::META_EVENT_PRODUCT_ID, true);
    if (!$parent_id) return '';

    $product = wc_get_product($parent_id);
    if (!$product || !($product instanceof \WC_Product_Variable)) return '';

    wp_enqueue_style('koopo-ticket-cards');
    wp_enqueue_script('koopo-ticket-cards');

    $cards = self::build_cards($product, $event_id);
    if (!$cards) return '';

    return '<div class="koopo-ticket-cards">' . implode('', $cards) . '</div>';
  }

  private static function build_cards(\WC_Product_Variable $product, int $event_id): array {
    $cards = [];
    $attribute_key = WC_Ticket_Product::variation_attribute_key();
    $attribute_name = WC_Ticket_Product::parent_attribute_key();
    $schedules = self::get_event_schedules($event_id);

    foreach ($product->get_children() as $variation_id) {
      $variation = wc_get_product($variation_id);
      if (!$variation || !($variation instanceof \WC_Product_Variation)) continue;

      $ticket_type_id = (int) get_post_meta($variation_id, WC_Ticket_Product::META_TICKET_TYPE_ID, true);
      if ($ticket_type_id) {
        $status = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_STATUS, true);
        $visibility = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_VISIBILITY, true);
        if ($status === 'inactive' || $visibility === 'private') continue;
      }

      $attributes = $variation->get_attributes();
      $ticket_name = $attributes[$attribute_name] ?? $variation->get_name();
      $price_html = $variation->get_price_html();
      $stock_qty = $variation->get_stock_quantity();
      $is_in_stock = $variation->is_in_stock();

      $classes = ['koopo-ticket-card'];
      if (!$is_in_stock) $classes[] = 'is-soldout';

      $modal_id = 'koopo-ticket-modal-' . $variation_id;

      $action = $is_in_stock
        ? '<a href="#" class="button koopo-ticket-open" data-target="#' . esc_attr($modal_id) . '">' . esc_html__('Add Ticket', 'koopo-tickets') . '</a>'
        : '<button class="button" disabled>' . esc_html__('Sold Out', 'koopo-tickets') . '</button>';

      $modal = $is_in_stock
        ? self::render_modal($modal_id, $product, $variation, $attribute_key, $ticket_name, $event_id, $ticket_type_id, $schedules, $is_in_stock)
        : '';

      $cards[] = sprintf(
        '<div class="%s">' .
          '<h4>%s</h4>' .
          '<p class="koopo-ticket-price">%s</p>' .
          '<p class="koopo-ticket-meta">%s</p>' .
          '%s' .
          '%s' .
        '</div>',
        esc_attr(implode(' ', $classes)),
        esc_html($ticket_name),
        $price_html ?: esc_html__('Free', 'koopo-tickets'),
        esc_html($stock_qty !== null ? sprintf(__('Remaining: %d', 'koopo-tickets'), (int) $stock_qty) : __('Availability varies', 'koopo-tickets')),
        $action,
        $modal
      );
    }

    return $cards;
  }

  private static function render_modal(string $modal_id, \WC_Product_Variable $parent, \WC_Product_Variation $variation, string $attribute_key, string $ticket_name, int $event_id, int $ticket_type_id, array $schedules, bool $in_stock): string {
    $schedule_required = count($schedules) > 1;
    $schedule_options = self::render_schedule_options($schedules);

    return sprintf(
      '<div class="koopo-ticket-modal" id="%s">' .
        '<div class="koopo-ticket-modal-content">' .
          '<div class="koopo-ticket-modal-header">' .
            '<h3>%s</h3>' .
            '<button type="button" class="koopo-ticket-modal-close">&times;</button>' .
          '</div>' .
          '<div class="koopo-ticket-modal-notice"></div>' .
          '<form>' .
            '<input type="hidden" name="add-to-cart" value="%d">' .
            '<input type="hidden" name="product_id" value="%d">' .
            '<input type="hidden" name="variation_id" value="%d">' .
            '<input type="hidden" name="%s" value="%s">' .
            '<input type="hidden" name="koopo_ticket_event_id" value="%d">' .
            '<input type="hidden" name="koopo_ticket_type_id" value="%d">' .
            '<input type="hidden" name="koopo_ticket_require_schedule" value="%d">' .
            '<label>%s</label>' .
            '<input type="text" name="koopo_ticket_contact_name" required>' .
            '<label>%s</label>' .
            '<input type="email" name="koopo_ticket_contact_email" required>' .
            '<label>%s</label>' .
            '<input type="tel" name="koopo_ticket_contact_phone">' .
            '<label>%s</label>' .
            '<input type="number" name="quantity" min="1" value="1">' .
            '%s' .
            '<div class="koopo-ticket-modal-footer">' .
              '<button type="submit" class="button" %s>%s</button>' .
              '<button type="button" class="button koopo-ticket-modal-close">%s</button>' .
            '</div>' .
          '</form>' .
        '</div>' .
      '</div>',
      esc_attr($modal_id),
      esc_html($ticket_name),
      (int) $parent->get_id(),
      (int) $parent->get_id(),
      (int) $variation->get_id(),
      esc_attr($attribute_key),
      esc_attr($ticket_name),
      (int) $event_id,
      (int) $ticket_type_id,
      $schedule_required ? 1 : 0,
      esc_html__('Contact Name', 'koopo-tickets'),
      esc_html__('Contact Email', 'koopo-tickets'),
      esc_html__('Contact Phone', 'koopo-tickets'),
      esc_html__('Quantity', 'koopo-tickets'),
      $schedule_options,
      $in_stock ? '' : 'disabled',
      $in_stock ? esc_html__('Add Ticket', 'koopo-tickets') : esc_html__('Sold Out', 'koopo-tickets'),
      esc_html__('Cancel', 'koopo-tickets')
    );
  }

  private static function infer_event_id(): int {
    $post_id = get_the_ID();
    if (!$post_id) return 0;

    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter($types);

    if (in_array(get_post_type($post_id), $types, true)) {
      return $post_id;
    }

    return 0;
  }

  private static function get_event_schedules(int $event_id): array {
    if (!$event_id || !class_exists('GeoDir_Event_Schedules')) return [];
    $schedules = \GeoDir_Event_Schedules::get_schedules($event_id, 'upcoming');
    if (empty($schedules)) return [];

    $out = [];
    foreach ($schedules as $schedule) {
      if (empty($schedule->start_date) || $schedule->start_date === '0000-00-00') continue;
      $out[] = $schedule;
    }

    return $out;
  }

  private static function render_schedule_options(array $schedules): string {
    if (empty($schedules)) return '';

    if (count($schedules) === 1) {
      $label = self::format_schedule_label($schedules[0]);
      return '<input type="hidden" name="koopo_ticket_schedule_id" value="' . esc_attr($schedules[0]->schedule_id) . '">' .
        '<input type="hidden" name="koopo_ticket_schedule_label" value="' . esc_attr($label) . '">' .
        '<p class="koopo-ticket-meta">' . esc_html($label) . '</p>';
    }

    $options = '';
    foreach ($schedules as $schedule) {
      $label = self::format_schedule_label($schedule);
      $options .= '<option value="' . esc_attr($schedule->schedule_id) . '" data-label="' . esc_attr($label) . '">' . esc_html($label) . '</option>';
    }

    return '<label>' . esc_html__('Event Date', 'koopo-tickets') . '</label>' .
      '<select name="koopo_ticket_schedule_id" class="koopo-ticket-schedule-select">' . $options . '</select>' .
      '<input type="hidden" name="koopo_ticket_schedule_label" value="' . esc_attr(self::format_schedule_label($schedules[0])) . '">';
  }

  private static function format_schedule_label($schedule): string {
    $start_date = $schedule->start_date ?? '';
    $end_date = $schedule->end_date ?? $start_date;
    $start_time = $schedule->start_time ?? '00:00:00';
    $end_time = $schedule->end_time ?? '00:00:00';
    $all_day = !empty($schedule->all_day);

    $date_format = function_exists('geodir_event_date_format') ? geodir_event_date_format() : 'Y-m-d';
    $time_format = function_exists('geodir_event_time_format') ? geodir_event_time_format() : 'H:i';

    $label = date_i18n($date_format, strtotime($start_date));
    if (!$all_day) {
      $label .= ' ' . date_i18n($time_format, strtotime($start_time));
      if ($end_date || $end_time) {
        $label .= ' - ' . date_i18n($time_format, strtotime($end_time));
      }
    }

    return $label;
  }
}
