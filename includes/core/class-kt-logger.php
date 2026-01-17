<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Logger {
  public static function log($message, array $context = []) {
    if (!Settings::get('debug')) return;

    $prefix = '[Koopo Tickets] ';
    $line = is_scalar($message) ? (string) $message : wp_json_encode($message);

    if (!empty($context)) {
      $line .= ' ' . wp_json_encode($context);
    }

    error_log($prefix . $line);
  }
}
