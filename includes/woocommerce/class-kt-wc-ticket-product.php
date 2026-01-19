<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class WC_Ticket_Product {
  const META_TICKET_TYPE_ID = '_koopo_ticket_type_id';
  const META_TICKET_PRODUCT_ID = '_koopo_wc_product_id';

  public static function create_or_update_for_ticket_type(int $ticket_type_id): int {
    if (!class_exists('WC_Product_Simple')) return 0;

    $ticket = get_post($ticket_type_id);
    if (!$ticket || $ticket->post_type !== Ticket_Types_CPT::POST_TYPE) return 0;

    $product_id = (int) get_post_meta($ticket_type_id, self::META_TICKET_PRODUCT_ID, true);
    $product = $product_id ? wc_get_product($product_id) : null;

    if (!$product || $product->get_id() !== $product_id) {
      $product = new \WC_Product_Simple();
      $product->set_status('publish');
    }

    $price = (float) get_post_meta($ticket_type_id, Ticket_Types_API::META_PRICE, true);
    $capacity = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_CAPACITY, true);

    $product->set_name($ticket->post_title);
    $product->set_regular_price($price);
    $product->set_virtual(true);
    $product->set_catalog_visibility('hidden');
    $product->set_sold_individually(false);

    if ($capacity > 0) {
      $product->set_manage_stock(true);
      $product->set_stock_quantity($capacity);
      $product->set_stock_status('instock');
    } else {
      $product->set_manage_stock(false);
      $product->set_stock_status('outofstock');
    }

    if ($ticket->post_author) {
      $product->set_props(['post_author' => (int) $ticket->post_author]);
    }

    $new_id = $product->save();

    if ($new_id) {
      update_post_meta($new_id, self::META_TICKET_TYPE_ID, $ticket_type_id);
      update_post_meta($ticket_type_id, self::META_TICKET_PRODUCT_ID, $new_id);
    }

    return (int) $new_id;
  }

  public static function maybe_trash_for_ticket_type(int $ticket_type_id): void {
    $product_id = (int) get_post_meta($ticket_type_id, self::META_TICKET_PRODUCT_ID, true);
    if (!$product_id) return;

    $linked = (int) get_post_meta($product_id, self::META_TICKET_TYPE_ID, true);
    if ($linked !== $ticket_type_id) return;

    wp_trash_post($product_id);
  }
}
