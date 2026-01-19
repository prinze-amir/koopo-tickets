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

    $cards = self::build_cards($product);
    if (!$cards) return '';

    return '<div class="koopo-ticket-cards">' . implode('', $cards) . '</div>';
  }

  private static function build_cards(\WC_Product_Variable $product): array {
    $cards = [];
    $attribute_key = WC_Ticket_Product::variation_attribute_key();
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
      $price_html = $variation->get_price_html();
      $stock_qty = $variation->get_stock_quantity();
      $is_in_stock = $variation->is_in_stock();

      $classes = ['koopo-ticket-card'];
      if (!$is_in_stock) $classes[] = 'is-soldout';

      $cards[] = sprintf(
        '<div class="%s">' .
          '<h4>%s</h4>' .
          '<p class="koopo-ticket-price">%s</p>' .
          '<p class="koopo-ticket-meta">%s</p>' .
          '%s' .
        '</div>',
        esc_attr(implode(' ', $classes)),
        esc_html($ticket_name),
        $price_html ?: esc_html__('Free', 'koopo-tickets'),
        esc_html($stock_qty !== null ? sprintf(__('Remaining: %d', 'koopo-tickets'), (int) $stock_qty) : __('Availability varies', 'koopo-tickets')),
        self::render_add_to_cart($product, $variation, $attribute_key, $ticket_name, $is_in_stock)
      );
    }

    return $cards;
  }

  private static function render_add_to_cart(\WC_Product_Variable $parent, \WC_Product_Variation $variation, string $attribute_key, string $ticket_name, bool $in_stock): string {
    if (!$in_stock) {
      return '<button class="button" disabled>' . esc_html__('Sold Out', 'koopo-tickets') . '</button>';
    }

    $key = $attribute_key;
    $value = $ticket_name;

    return sprintf(
      '<form class="cart" method="post" action="%s">' .
        '<input type="hidden" name="add-to-cart" value="%d">' .
        '<input type="hidden" name="product_id" value="%d">' .
        '<input type="hidden" name="variation_id" value="%d">' .
        '<input type="hidden" name="%s" value="%s">' .
        '<button type="submit" class="button">%s</button>' .
      '</form>',
      esc_url(wc_get_cart_url()),
      (int) $parent->get_id(),
      (int) $parent->get_id(),
      (int) $variation->get_id(),
      esc_attr($key),
      esc_attr($value),
      esc_html__('Add Ticket', 'koopo-tickets')
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
}
