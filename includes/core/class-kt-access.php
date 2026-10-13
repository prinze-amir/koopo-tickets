<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Access {
  public static function is_admin_bypass(?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return false;
    if (function_exists('is_super_admin') && is_super_admin($user_id)) return true;
    if (user_can($user_id, 'manage_options')) return true;

    return (bool) apply_filters('koopo_tickets_admin_bypass', false, $user_id);
  }

  public static function pack_features(int $pack_id): array {
    $features = $pack_id ? get_post_meta($pack_id, '_koopo_features', true) : [];
    if (is_string($features)) $features = json_decode($features, true);
    return is_array($features) ? $features : [];
  }

  public static function ticket_packs(): array {
    if (!function_exists('wc_get_products')) return [];
    $packs = array_filter(wc_get_products(['type' => 'product_pack', 'status' => 'publish', 'limit' => -1]), static function ($product) {
      return !empty(self::pack_features($product->get_id())['event_tickets']) && $product->is_purchasable();
    });
    usort($packs, static function ($a, $b) { return (float) $a->get_price() <=> (float) $b->get_price(); });
    return $packs;
  }

  public static function vendor_can_manage_tickets(?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return false;
    if (self::is_admin_bypass($user_id)) return true;

    $is_vendor = function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id);
    $allowed = false;
    if ($is_vendor) {
      $pack_id = (int) apply_filters('koopo_get_vendor_pack_id', (int) get_user_meta($user_id, 'product_package_id', true), $user_id);
      $features = self::pack_features($pack_id);
      $allowed = !empty($features['event_tickets']);
    }

    return (bool) apply_filters('koopo_tickets_vendor_access', $allowed, $user_id);
  }
}
