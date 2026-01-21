<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class DB {
  public static function create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'koopo_tickets';

    $sql = \"CREATE TABLE {$table} (\n\" .
      \"id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n\" .
      \"order_id BIGINT UNSIGNED NOT NULL,\n\" .
      \"order_item_id BIGINT UNSIGNED NOT NULL,\n\" .
      \"event_id BIGINT UNSIGNED NOT NULL,\n\" .
      \"ticket_type_id BIGINT UNSIGNED NOT NULL,\n\" .
      \"variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n\" .
      \"code VARCHAR(64) NOT NULL,\n\" .
      \"status VARCHAR(20) NOT NULL DEFAULT 'issued',\n\" .
      \"attendee_name VARCHAR(200) NOT NULL DEFAULT '',\n\" .
      \"attendee_email VARCHAR(200) NOT NULL DEFAULT '',\n\" .
      \"attendee_phone VARCHAR(50) NOT NULL DEFAULT '',\n\" .
      \"attendee_index SMALLINT UNSIGNED NOT NULL DEFAULT 1,\n\" .
      \"schedule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n\" .
      \"schedule_label VARCHAR(200) NOT NULL DEFAULT '',\n\" .
      \"created_at DATETIME NOT NULL,\n\" .
      \"updated_at DATETIME NULL,\n\" .
      \"PRIMARY KEY  (id),\n\" .
      \"KEY order_id (order_id),\n\" .
      \"KEY event_id (event_id),\n\" .
      \"KEY ticket_type_id (ticket_type_id),\n\" .
      \"KEY code (code)\n\" .
      \") {$charset};\";

    dbDelta($sql);
  }

  public static function maybe_upgrade() {
    self::create_tables();
  }
}
