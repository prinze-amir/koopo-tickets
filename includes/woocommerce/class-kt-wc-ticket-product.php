<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class WC_Ticket_Product {
  const META_TICKET_TYPE_ID = '_koopo_ticket_type_id';
  const META_TICKET_PRODUCT_ID = '_koopo_wc_product_id';
  const META_TICKET_VARIATION_ID = '_koopo_wc_variation_id';
  const META_EVENT_PRODUCT_ID = '_koopo_wc_event_ticket_product_id';
  const ATTRIBUTE_NAME = 'Ticket Type';

  public static function create_or_update_for_ticket_type(int $ticket_type_id): int {
    if (!class_exists('WC_Product_Variable')) return 0;

    $ticket = get_post($ticket_type_id);
    if (!$ticket || $ticket->post_type !== Ticket_Types_CPT::POST_TYPE) return 0;

    $event_id = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_EVENT_ID, true);
    if (!$event_id) return 0;

    $parent_id = (int) get_post_meta($event_id, self::META_EVENT_PRODUCT_ID, true);
    $parent = $parent_id ? wc_get_product($parent_id) : null;
    if (!$parent || !($parent instanceof \WC_Product_Variable)) {
      $parent = self::create_parent_product($event_id);
      $parent_id = $parent ? $parent->get_id() : 0;
      if ($parent_id) {
        update_post_meta($event_id, self::META_EVENT_PRODUCT_ID, $parent_id);
      }
    }
    if (!$parent_id) return 0;

    $variation_id = (int) get_post_meta($ticket_type_id, self::META_TICKET_VARIATION_ID, true);
    $variation = $variation_id ? new \WC_Product_Variation($variation_id) : null;
    if (!$variation || !$variation->get_id()) {
      $variation = new \WC_Product_Variation();
    }

    $price = (float) get_post_meta($ticket_type_id, Ticket_Types_API::META_PRICE, true);
    $capacity = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_CAPACITY, true);
    $status = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_STATUS, true);
    $sku = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_SKU, true);

    self::ensure_parent_attribute($parent_id, $ticket->post_title);

    $variation->set_parent_id($parent_id);
    $variation->set_regular_price($price);
    $variation->set_virtual(true);
    $variation->set_menu_order(0);
    $variation->set_attributes(self::build_variation_attributes($ticket->post_title));
    if ($sku) $variation->set_sku($sku);

    if ($status === 'inactive') {
      $variation->set_manage_stock(false);
      $variation->set_stock_status('outofstock');
    } elseif ($capacity > 0) {
      $variation->set_manage_stock(true);
      $variation->set_stock_quantity($capacity);
      $variation->set_stock_status('instock');
    } else {
      $variation->set_manage_stock(false);
      $variation->set_stock_status('outofstock');
    }

    $new_variation_id = $variation->save();

    if ($new_variation_id) {
      update_post_meta($new_variation_id, self::META_TICKET_TYPE_ID, $ticket_type_id);
      update_post_meta($ticket_type_id, self::META_TICKET_PRODUCT_ID, $parent_id);
      update_post_meta($ticket_type_id, self::META_TICKET_VARIATION_ID, $new_variation_id);
    }

    return (int) $new_variation_id;
  }

  public static function maybe_trash_for_ticket_type(int $ticket_type_id): void {
    $variation_id = (int) get_post_meta($ticket_type_id, self::META_TICKET_VARIATION_ID, true);
    if ($variation_id) {
      $linked = (int) get_post_meta($variation_id, self::META_TICKET_TYPE_ID, true);
      if ($linked === $ticket_type_id) {
        wp_trash_post($variation_id);
      }
    }

    $parent_id = (int) get_post_meta($ticket_type_id, self::META_TICKET_PRODUCT_ID, true);
    if (!$parent_id) return;

    $parent = wc_get_product($parent_id);
    if (!$parent || !($parent instanceof \WC_Product_Variable)) return;

    $remaining = array_filter($parent->get_children(), function ($child_id) use ($variation_id) {
      if ($child_id === $variation_id) return false;
      $child = wc_get_product($child_id);
      return $child && $child->get_status() !== 'trash';
    });

    if (empty($remaining)) {
      wp_trash_post($parent_id);
      $event_id = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_EVENT_ID, true);
      if ($event_id) {
        delete_post_meta($event_id, self::META_EVENT_PRODUCT_ID);
      }
    }
  }

  private static function create_parent_product(int $event_id): ?\WC_Product_Variable {
    if (!class_exists('WC_Product_Variable')) return null;
    $event = get_post($event_id);
    if (!$event) return null;

    $parent = new \WC_Product_Variable();
    $parent->set_status('publish');
    $parent->set_name($event->post_title . ' Tickets');
    $parent->set_catalog_visibility('hidden');
    $parent->set_virtual(true);
    $parent->set_manage_stock(false);

    if ($event->post_author) {
      $parent->set_props(['post_author' => (int) $event->post_author]);
    }

    $parent_id = $parent->save();
    if ($parent_id) {
      $parent->set_id($parent_id);
      $parent->save();
      return $parent;
    }

    return null;
  }

  private static function ensure_parent_attribute(int $parent_id, string $ticket_name): void {
    $parent = wc_get_product($parent_id);
    if (!$parent || !($parent instanceof \WC_Product_Variable)) return;

    $attributes = $parent->get_attributes();
    $attr_key = self::parent_attribute_key();
    $attr = $attributes[$attr_key] ?? null;

    if (!$attr || !($attr instanceof \WC_Product_Attribute)) {
      $attr = new \WC_Product_Attribute();
      $attr->set_name(self::ATTRIBUTE_NAME);
      $attr->set_visible(true);
      $attr->set_variation(true);
      $attr->set_options([$ticket_name]);
      $attributes[$attr_key] = $attr;
    } else {
      $options = $attr->get_options();
      if (!in_array($ticket_name, $options, true)) {
        $options[] = $ticket_name;
        $attr->set_options($options);
        $attributes[$attr_key] = $attr;
      }
    }

    $parent->set_attributes($attributes);
    $parent->save();
  }

  private static function build_variation_attributes(string $ticket_name): array {
    return [self::variation_attribute_key() => $ticket_name];
  }

  public static function parent_attribute_key(): string {
    return sanitize_title(self::ATTRIBUTE_NAME);
  }

  public static function variation_attribute_key(): string {
    return 'attribute_' . self::parent_attribute_key();
  }
}
