<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class WC_Order_Tickets {
  public static function init(): void {
    add_action('woocommerce_order_details_before_order_table', [__CLASS__, 'render_order_buttons']);
    add_action('woocommerce_thankyou', [__CLASS__, 'render_thankyou_buttons'], 20);
    add_action('woocommerce_email_after_order_table', [__CLASS__, 'render_email_buttons'], 10, 4);
  }

  public static function render_thankyou_buttons($order_id): void {
    if (did_action('woocommerce_order_details_after_order_table')) return;
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order) return;
    self::render_order_buttons($order);
  }

  public static function render_order_buttons($order): void {
    if (!$order instanceof \WC_Order) return;

    $items = self::get_ticket_items($order);
    if (empty($items)) return;

    echo '<section class="koopo-order-tickets" style="border: 1px solid #ddd; padding: 15px; margin: 20px 0;border-radius: 8px;">';
    echo '<h2 class="flex align-middle align-center">' . esc_html__('Tickets', 'koopo-tickets') . '</h2>';
    echo '<div class="koopo-order-tickets__list">';
    foreach ($items as $item) {
      $links = self::build_links((int) $item->get_id());
      echo '<div class="flex flex-column align-middle gap-1" style="margin-bottom: 10px;">';
      echo '<strong>' . esc_html($item->get_name()) . '</strong> ';
      echo '<div class="flex flex-wrap align-middle gap-1" style="margin-bottom: 10px;">';

      echo self::link_html($links['view'], __('View', 'koopo-tickets'));
      echo ' ';
      echo self::link_html($links['print'], __('Print', 'koopo-tickets'));
      echo ' ';
      echo self::link_html($links['download'], __('Download', 'koopo-tickets'));
      echo '</div>';
      echo '</div>';
    }
    echo '</div>';
    echo '</section>';
  }

  public static function render_email_buttons($order, $sent_to_admin, $plain_text, $email): void {
    if ($sent_to_admin) return;
    if (!$order instanceof \WC_Order) return;

    $items = self::get_ticket_items($order);
    if (empty($items)) return;

    if ($plain_text) {
      echo "\n" . __('Tickets', 'koopo-tickets') . "\n";
      foreach ($items as $item) {
        $links = self::build_links((int) $item->get_id());
        echo $item->get_name() . "\n";
        echo __('View', 'koopo-tickets') . ': ' . $links['view'] . "\n";
        echo __('Print', 'koopo-tickets') . ': ' . $links['print'] . "\n";
        echo __('Download', 'koopo-tickets') . ': ' . $links['download'] . "\n";
        echo "\n";
      }
      return;
    }

    echo '<h2>' . esc_html__('Tickets', 'koopo-tickets') . '</h2>';
    foreach ($items as $item) {
      $links = self::build_links((int) $item->get_id());
      echo '<p>';
      echo '<strong>' . esc_html($item->get_name()) . '</strong><br>';
      echo self::email_link_html($links['view'], __('View', 'koopo-tickets')) . ' | ';
      echo self::email_link_html($links['print'], __('Print', 'koopo-tickets')) . ' | ';
      echo self::email_link_html($links['download'], __('Download', 'koopo-tickets'));
      echo '</p>';
    }
  }

  private static function get_ticket_items(\WC_Order $order): array {
    $items = $order->get_items('line_item');
    $tickets = [];
    foreach ($items as $item) {
      if ($item->get_meta('_koopo_ticket_type_id')) {
        $tickets[] = $item;
      }
    }
    return $tickets;
  }

  private static function build_links(int $item_id): array {
    $base = add_query_arg(['koopo_ticket_print' => $item_id], home_url('/'));
    return [
      'view' => $base,
      'print' => add_query_arg(['kt_action' => 'print'], $base),
      'download' => add_query_arg(['kt_action' => 'download'], $base),
    ];
  }

  private static function link_html(string $url, string $label): string {
    return '<a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
  }

  private static function email_link_html(string $url, string $label): string {
    return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
  }
}
