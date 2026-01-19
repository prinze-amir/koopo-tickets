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

  public static function vendor_can_manage_tickets(?int $user_id = null): bool {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return false;
    if (self::is_admin_bypass($user_id)) return true;

    $is_vendor = function_exists('dokan_is_user_seller') && dokan_is_user_seller($user_id);
    $allowed = $is_vendor;

    return (bool) apply_filters('koopo_tickets_vendor_access', $allowed, $user_id);
  }
}
