<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class DB {
  const SCHEMA_VERSION = '2026.04.05.1';
  const SCHEMA_OPTION = 'koopo_tickets_db_version';

  public static function create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'koopo_tickets';
    $transfers_table = $wpdb->prefix . 'koopo_ticket_transfers';

    $sql = "CREATE TABLE {$table} (\n" .
      "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
      "order_id BIGINT UNSIGNED NOT NULL,\n" .
      "order_item_id BIGINT UNSIGNED NOT NULL,\n" .
      "event_id BIGINT UNSIGNED NOT NULL,\n" .
      "ticket_type_id BIGINT UNSIGNED NOT NULL,\n" .
      "variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n" .
      "code VARCHAR(64) NOT NULL,\n" .
      "status VARCHAR(20) NOT NULL DEFAULT 'issued',\n" .
      "attendee_name VARCHAR(200) NOT NULL DEFAULT '',\n" .
      "attendee_email VARCHAR(200) NOT NULL DEFAULT '',\n" .
      "attendee_phone VARCHAR(50) NOT NULL DEFAULT '',\n" .
      "attendee_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n" .
      "attendee_index SMALLINT UNSIGNED NOT NULL DEFAULT 1,\n" .
      "schedule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n" .
      "schedule_label VARCHAR(200) NOT NULL DEFAULT '',\n" .
      "created_at DATETIME NOT NULL,\n" .
      "updated_at DATETIME NULL,\n" .
      "PRIMARY KEY  (id),\n" .
      "UNIQUE KEY uniq_code (code),\n" .
      "UNIQUE KEY uniq_order_attendee (order_item_id, attendee_index),\n" .
      "KEY order_id (order_id),\n" .
      "KEY order_item_id (order_item_id),\n" .
      "KEY attendee_lookup (order_item_id, attendee_index),\n" .
      "KEY event_id (event_id),\n" .
      "KEY ticket_type_id (ticket_type_id),\n" .
      "KEY attendee_user_id (attendee_user_id),\n" .
      "KEY code (code)\n" .
      ") {$charset};";

    $transfers_sql = "CREATE TABLE {$transfers_table} (\n" .
      "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
      "ticket_id BIGINT UNSIGNED NOT NULL,\n" .
      "order_item_id BIGINT UNSIGNED NOT NULL,\n" .
      "sender_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n" .
      "recipient_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\n" .
      "recipient_name VARCHAR(200) NOT NULL DEFAULT '',\n" .
      "recipient_email VARCHAR(200) NOT NULL DEFAULT '',\n" .
      "recipient_phone VARCHAR(50) NOT NULL DEFAULT '',\n" .
      "token VARCHAR(64) NOT NULL,\n" .
      "status VARCHAR(20) NOT NULL DEFAULT 'pending',\n" .
      "created_at DATETIME NOT NULL,\n" .
      "updated_at DATETIME NULL,\n" .
      "accepted_at DATETIME NULL,\n" .
      "cancelled_at DATETIME NULL,\n" .
      "PRIMARY KEY  (id),\n" .
      "UNIQUE KEY uniq_ticket_id (ticket_id),\n" .
      "UNIQUE KEY uniq_token (token),\n" .
      "KEY order_item_id (order_item_id),\n" .
      "KEY recipient_user_id (recipient_user_id),\n" .
      "KEY recipient_email (recipient_email),\n" .
      "KEY status (status)\n" .
      ") {$charset};";

    dbDelta($sql);
    dbDelta($transfers_sql);
    update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
  }

  public static function maybe_upgrade() {
    $installed = (string) get_option(self::SCHEMA_OPTION, '');
    if ($installed === self::SCHEMA_VERSION) {
      return;
    }

    self::dedupe_ticket_rows();
    self::create_tables();
  }

  private static function dedupe_ticket_rows(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'koopo_tickets';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($table_exists !== $table) {
      return;
    }

    $safe_table = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    if (!$safe_table) {
      return;
    }

    // Keep the earliest ticket row per order item/attendee slot to prevent duplicate issuance records.
    $wpdb->query(
      "DELETE t1 FROM `{$safe_table}` t1
       INNER JOIN `{$safe_table}` t2
         ON t1.order_item_id = t2.order_item_id
        AND t1.attendee_index = t2.attendee_index
        AND t1.id > t2.id"
    );

    $duplicate_codes = $wpdb->get_results(
      "SELECT code, GROUP_CONCAT(id ORDER BY id ASC) AS ids
       FROM `{$safe_table}`
       WHERE code IS NOT NULL AND code <> ''
       GROUP BY code
       HAVING COUNT(*) > 1",
      ARRAY_A
    );

    foreach ((array) $duplicate_codes as $row) {
      $ids = array_filter(array_map('absint', explode(',', (string) ($row['ids'] ?? ''))));
      if (count($ids) <= 1) {
        continue;
      }

      array_shift($ids);
      foreach ($ids as $id) {
        $wpdb->update(
          $table,
          ['code' => self::generate_unique_code($id)],
          ['id' => $id],
          ['%s'],
          ['%d']
        );
      }
    }

    $empty_code_ids = $wpdb->get_col("SELECT id FROM `{$safe_table}` WHERE code IS NULL OR code = ''");
    foreach ((array) $empty_code_ids as $id) {
      $id = absint($id);
      if (!$id) {
        continue;
      }
      $wpdb->update(
        $table,
        ['code' => self::generate_unique_code($id)],
        ['id' => $id],
        ['%s'],
        ['%d']
      );
    }
  }

  private static function generate_unique_code(int $id): string {
    $suffix = strtoupper(wp_generate_password(6, false, false));
    return 'KT-DB-' . $id . '-' . $suffix;
  }
}
