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

    $options = self::get_ticket_options($product);
    if (!$options) return '';

    wp_enqueue_style('koopo-ticket-cards');
    wp_enqueue_script('koopo-ticket-cards');

    $user = wp_get_current_user();
    wp_localize_script('koopo-ticket-cards', 'KOOPO_TICKETS_FRONTEND', [
      'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
      'checkout_url' => wc_get_checkout_url(),
      'max_tickets_per_order' => (int) Settings::get('max_tickets_per_order'),
      'user_name' => $user ? $user->display_name : '',
      'user_email' => $user ? $user->user_email : '',
      'user_phone' => get_user_meta($user->ID ?? 0, 'billing_phone', true),
    ]);

    $cards = self::render_cards($options);
    $modal = self::render_modal($event_id, $product, $options, $user);

    return '<div class="koopo-ticket-section">' .
      '<div class="koopo-ticket-grid">' . $cards . '</div>' .
      '<div class="koopo-ticket-cta"><button class="button koopo-ticket-open" data-target="#koopo-ticket-modal-' . esc_attr($event_id) . '">' . esc_html__('Buy Tickets', 'koopo-tickets') . '</button></div>' .
      $modal .
    '</div>';
  }

  private static function get_ticket_options(\WC_Product_Variable $product): array {
    $options = [];
    $attribute_name = WC_Ticket_Product::parent_attribute_key();

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
      $stock_qty = $variation->get_stock_quantity();

      $options[] = [
        'variation_id' => (int) $variation_id,
        'ticket_type_id' => $ticket_type_id,
        'name' => $ticket_name,
        'price' => (float) $variation->get_price(),
        'price_html' => $variation->get_price_html(),
        'stock_qty' => $stock_qty,
        'in_stock' => $variation->is_in_stock(),
        'max_per_order' => $ticket_type_id ? (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_MAX_PER_ORDER, true) : 0,
      ];
    }

    return $options;
  }

  private static function render_cards(array $options): string {
    $cards = [];

    foreach ($options as $option) {
      $classes = ['koopo-ticket-card'];
      if (!$option['in_stock']) $classes[] = 'is-soldout';
      $max_text = $option['max_per_order'] ? sprintf(__('Max %d per order', 'koopo-tickets'), $option['max_per_order']) : __('No limit', 'koopo-tickets');

      $cards[] = sprintf(
        '<div class="%s">' .
          '<h4>%s</h4>' .
          '<p class="koopo-ticket-price">%s</p>' .
          '<p class="koopo-ticket-meta">%s</p>' .
          '<p class="koopo-ticket-meta">%s</p>' .
        '</div>',
        esc_attr(implode(' ', $classes)),
        esc_html($option['name']),
        $option['price_html'] ?: esc_html__('Free', 'koopo-tickets'),
        esc_html($option['stock_qty'] !== null ? sprintf(__('Remaining: %d', 'koopo-tickets'), (int) $option['stock_qty']) : __('Availability varies', 'koopo-tickets')),
        esc_html($max_text)
      );
    }

    return implode('', $cards);
  }

  private static function render_modal(int $event_id, \WC_Product_Variable $product, array $options, $user): string {
    $schedules = self::get_event_schedules($event_id);
    $schedule_required = count($schedules) > 1;
    $schedule_select = self::render_schedule_options($schedules);
    $attribute_key = WC_Ticket_Product::variation_attribute_key();

    $ticket_items = '';
    foreach ($options as $option) {
      $disabled = $option['in_stock'] ? '' : 'disabled';
      $max_attr = $option['max_per_order'] ? 'data-max="' . esc_attr($option['max_per_order']) . '"' : '';
      $ticket_items .= sprintf(
        '<div class="koopo-ticket__item" data-variation-id="%d" data-ticket-type-id="%d" data-name="%s" data-price="%s" %s>' .
          '<div>' .
            '<h5>%s</h5>' .
            '<div class="koopo-ticket-meta">%s</div>' .
          '</div>' .
          '<div class="koopo-ticket__qty">' .
            '<input type="number" min="0" value="0" %s>' .
          '</div>' .
        '</div>',
        (int) $option['variation_id'],
        (int) $option['ticket_type_id'],
        esc_attr($option['name']),
        esc_attr($option['price']),
        $max_attr,
        esc_html($option['name']),
        $option['price_html'] ?: esc_html__('Free', 'koopo-tickets'),
        $disabled
      );
    }

    $user_name = $user ? $user->display_name : '';
    $user_email = $user ? $user->user_email : '';
    $user_phone = get_user_meta($user->ID ?? 0, 'billing_phone', true);

    return sprintf(
      '<div class="koopo-ticket__overlay" id="koopo-ticket-modal-%d" data-event-id="%d" data-product-id="%d" data-attribute-key="%s">' .
        '<div class="koopo-ticket__modal">' .
          '<button class="koopo-ticket__close" type="button">&times;</button>' .
          '<div class="koopo-ticket__header">' .
            '<h2 class="koopo-ticket__title">%s</h2>' .
            '<div class="koopo-ticket__steps">' .
              '<div class="koopo-ticket__step koopo-ticket__step--active" data-step="1">' .
                '<div class="koopo-ticket__step-num">1</div>' .
                '<div class="koopo-ticket__step-label">%s</div>' .
              '</div>' .
              '<div class="koopo-ticket__step" data-step="2">' .
                '<div class="koopo-ticket__step-num">2</div>' .
                '<div class="koopo-ticket__step-label">%s</div>' .
              '</div>' .
              '<div class="koopo-ticket__step" data-step="3">' .
                '<div class="koopo-ticket__step-num">3</div>' .
                '<div class="koopo-ticket__step-label">%s</div>' .
              '</div>' .
            '</div>' .
          '</div>' .
          '<div class="koopo-ticket__notice"></div>' .
          '<div class="koopo-ticket__panel is-active" data-panel="1">' .
            '<div class="koopo-ticket__list">%s</div>' .
            '%s' .
            '<div class="koopo-ticket__footer">' .
              '<button class="button koopo-ticket-next" type="button">%s</button>' .
            '</div>' .
          '</div>' .
          '<div class="koopo-ticket__panel" data-panel="2">' .
            '<div class="koopo-ticket__field">' .
              '<label>%s</label>' .
              '<input type="text" name="koopo_ticket_contact_name" value="%s" required>' .
            '</div>' .
            '<div class="koopo-ticket__field">' .
              '<label>%s</label>' .
              '<input type="email" name="koopo_ticket_contact_email" value="%s" required>' .
            '</div>' .
            '<div class="koopo-ticket__field">' .
              '<label>%s</label>' .
              '<input type="tel" name="koopo_ticket_contact_phone" value="%s">' .
            '</div>' .
            '<div class="koopo-ticket__guest-grid" data-guest-grid></div>' .
            '<div class="koopo-ticket__footer">' .
              '<button class="button koopo-ticket-back" type="button">%s</button>' .
              '<button class="button koopo-ticket-next" type="button">%s</button>' .
            '</div>' .
          '</div>' .
          '<div class="koopo-ticket__panel" data-panel="3">' .
            '<div class="koopo-ticket__summary" data-summary></div>' .
            '<div class="koopo-ticket__footer">' .
              '<button class="button koopo-ticket-back" type="button">%s</button>' .
              '<button class="button button-primary koopo-ticket-confirm" type="button">%s</button>' .
            '</div>' .
          '</div>' .
        '</div>' .
      '</div>',
      $event_id,
      $event_id,
      (int) $product->get_id(),
      esc_attr($attribute_key),
      esc_html__('Buy Tickets', 'koopo-tickets'),
      esc_html__('Tickets', 'koopo-tickets'),
      esc_html__('Contact', 'koopo-tickets'),
      esc_html__('Confirm', 'koopo-tickets'),
      $ticket_items,
      $schedule_required ? $schedule_select : '',
      esc_html__('Next', 'koopo-tickets'),
      esc_html__('Contact Name', 'koopo-tickets'),
      esc_attr($user_name),
      esc_html__('Contact Email', 'koopo-tickets'),
      esc_attr($user_email),
      esc_html__('Contact Phone', 'koopo-tickets'),
      esc_attr($user_phone),
      esc_html__('Back', 'koopo-tickets'),
      esc_html__('Next', 'koopo-tickets'),
      esc_html__('Back', 'koopo-tickets'),
      esc_html__('Proceed to Checkout', 'koopo-tickets')
    );
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

    return '<div class="koopo-ticket__field">' .
      '<label>' . esc_html__('Event Date', 'koopo-tickets') . '</label>' .
      '<select name="koopo_ticket_schedule_id" class="koopo-ticket-schedule-select">' . $options . '</select>' .
      '<input type="hidden" name="koopo_ticket_schedule_label" value="' . esc_attr(self::format_schedule_label($schedules[0])) . '">' .
    '</div>';
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
}
