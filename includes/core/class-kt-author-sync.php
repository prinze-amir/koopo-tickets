<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Author_Sync {
  const META_LAST_SYNCED_AUTHOR = '_koopo_ticket_author_synced_to';
  const NOTICE_TRANSIENT_PREFIX = 'koopo_tickets_author_notice_';

  private static $syncing = false;

  public static function init(): void {
    add_filter('wp_insert_post_data', [__CLASS__, 'validate_event_author_before_save'], 20, 2);
    add_action('save_post', [__CLASS__, 'sync_event_author_on_save'], 30, 3);
    add_action('add_meta_boxes', [__CLASS__, 'register_meta_boxes']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    add_action('wp_ajax_koopo_tickets_search_event_authors', [__CLASS__, 'ajax_search_event_authors']);
    add_action('admin_post_koopo_tickets_resync_event_author', [__CLASS__, 'handle_resync_request']);
    add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
  }

  public static function validate_event_author_before_save(array $data, array $postarr): array {
    if (self::$syncing || empty($postarr['ID'])) {
      return $data;
    }

    $event_id = absint($postarr['ID']);
    if (!$event_id || !self::is_event_post_type($data['post_type'] ?? '')) {
      return $data;
    }

    $old_author = (int) get_post_field('post_author', $event_id);
    $new_author = isset($data['post_author']) ? absint($data['post_author']) : $old_author;

    if (isset($postarr['koopo_ticket_event_author_id'])) {
      $nonce = isset($postarr['koopo_ticket_event_author_nonce']) ? (string) $postarr['koopo_ticket_event_author_nonce'] : '';
      if (wp_verify_nonce($nonce, 'koopo_tickets_event_author_' . $event_id)) {
        $field_author = absint($postarr['koopo_ticket_event_author_id']);
        if ($field_author > 0 && $field_author !== $old_author) {
          $new_author = $field_author;
          $data['post_author'] = $field_author;
        }
      }
    }

    if (!$new_author || $new_author === $old_author) {
      return $data;
    }

    $validation = self::validate_event_author_change($event_id, $new_author);
    if (is_wp_error($validation)) {
      $data['post_author'] = $old_author;
      self::queue_admin_notice($validation->get_error_message(), 'error');
    }

    return $data;
  }

  public static function sync_event_author_on_save(int $post_id, \WP_Post $post, bool $update): void {
    if (self::$syncing || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
      return;
    }

    if (!$post || !self::is_event_post_type($post->post_type)) {
      return;
    }

    $author_id = (int) $post->post_author;
    if ($author_id < 1) {
      return;
    }

    $last_synced = (int) get_post_meta($post_id, self::META_LAST_SYNCED_AUTHOR, true);
    if ($last_synced === $author_id) {
      return;
    }

    $validation = self::validate_event_author_change($post_id, $author_id);
    if (is_wp_error($validation)) {
      self::queue_admin_notice($validation->get_error_message(), 'error');
      return;
    }

    $result = self::sync_event_author($post_id, $author_id);
    if (is_wp_error($result)) {
      self::queue_admin_notice($result->get_error_message(), 'error');
    }
  }

  public static function sync_event_author(int $event_id, ?int $author_id = null) {
    $event_id = absint($event_id);
    $event = get_post($event_id);
    if (!$event || !self::is_event_post_type($event->post_type)) {
      return new \WP_Error('koopo_tickets_invalid_event', __('Invalid event for ticket author sync.', 'koopo-tickets'));
    }

    $author_id = $author_id ? absint($author_id) : (int) $event->post_author;
    if ($author_id < 1) {
      return new \WP_Error('koopo_tickets_invalid_author', __('Invalid event author for ticket author sync.', 'koopo-tickets'));
    }

    self::$syncing = true;

    $ticket_type_ids = self::get_event_ticket_type_ids($event_id);
    foreach ($ticket_type_ids as $ticket_type_id) {
      self::update_post_author($ticket_type_id, $author_id);
    }

    $product_ids = self::get_event_ticket_product_ids($event_id, $ticket_type_ids);
    foreach ($product_ids as $product_id) {
      self::update_post_author($product_id, $author_id);
    }

    update_post_meta($event_id, self::META_LAST_SYNCED_AUTHOR, $author_id);
    self::$syncing = false;

    return [
      'event_id' => $event_id,
      'author_id' => $author_id,
      'ticket_type_ids' => $ticket_type_ids,
      'product_ids' => $product_ids,
    ];
  }

  public static function validate_event_author_change(int $event_id, int $vendor_id) {
    $event_id = absint($event_id);
    $vendor_id = absint($vendor_id);

    if (!$event_id || !$vendor_id) {
      return new \WP_Error('koopo_tickets_invalid_author_change', __('Invalid event or vendor.', 'koopo-tickets'));
    }

    $user = get_user_by('id', $vendor_id);
    if (!$user) {
      return new \WP_Error('koopo_tickets_vendor_not_found', __('Selected vendor account was not found.', 'koopo-tickets'));
    }

    if (function_exists('dokan_is_user_seller') && !dokan_is_user_seller($vendor_id, true)) {
      return new \WP_Error('koopo_tickets_not_vendor', __('Selected user is not a vendor.', 'koopo-tickets'));
    }

    if (function_exists('dokan_is_seller_enabled') && !dokan_is_seller_enabled($vendor_id)) {
      return new \WP_Error('koopo_tickets_vendor_disabled', __('Selected vendor is not enabled for selling.', 'koopo-tickets'));
    }

    if (!Access::vendor_can_manage_tickets($vendor_id)) {
      return new \WP_Error('koopo_tickets_vendor_cannot_manage_tickets', __('Selected vendor cannot manage event tickets.', 'koopo-tickets'));
    }

    if (!self::vendor_can_create_event($vendor_id, $event_id)) {
      return new \WP_Error('koopo_tickets_vendor_cannot_create_events', __('Selected vendor cannot create events.', 'koopo-tickets'));
    }

    $needed_slots = self::get_required_product_slots_for_author_change($event_id, $vendor_id);
    if ($needed_slots > 0 && !self::vendor_has_product_capacity($vendor_id, $needed_slots)) {
      return new \WP_Error('koopo_tickets_vendor_product_limit', __('Selected vendor has reached their product limit.', 'koopo-tickets'));
    }

    return true;
  }

  public static function validate_event_ticket_product_capacity(int $event_id) {
    $event_id = absint($event_id);
    $event = get_post($event_id);
    if (!$event || !self::is_event_post_type($event->post_type)) {
      return new \WP_Error('koopo_tickets_invalid_event', __('Invalid event.', 'koopo-tickets'));
    }

    $parent_id = (int) get_post_meta($event_id, WC_Ticket_Product::META_EVENT_PRODUCT_ID, true);
    if ($parent_id) {
      return true;
    }

    $vendor_id = (int) $event->post_author;
    if ($vendor_id < 1) {
      return new \WP_Error('koopo_tickets_invalid_author', __('Event does not have a valid author.', 'koopo-tickets'));
    }

    if (!self::vendor_has_product_capacity($vendor_id, 1)) {
      return new \WP_Error('koopo_tickets_vendor_product_limit', __('Event author has reached their product limit.', 'koopo-tickets'));
    }

    return true;
  }

  public static function enqueue_admin_assets(string $hook): void {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
      return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || empty($screen->post_type) || !self::is_event_post_type((string) $screen->post_type)) {
      return;
    }

    wp_enqueue_style(
      'koopo-tickets-admin-event-author',
      KOOPO_TICKETS_URL . 'assets/admin-event-author.css',
      [],
      KOOPO_TICKETS_VERSION
    );
    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_script(
      'koopo-tickets-admin-event-author',
      KOOPO_TICKETS_URL . 'assets/admin-event-author.js',
      ['jquery', 'jquery-ui-autocomplete'],
      KOOPO_TICKETS_VERSION,
      true
    );

    wp_localize_script('koopo-tickets-admin-event-author', 'KOOPO_TICKETS_AUTHOR', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('koopo_tickets_search_event_authors'),
      'minChars' => 2,
      'messages' => [
        'searching' => __('Searching vendors...', 'koopo-tickets'),
        'noResults' => __('No eligible vendors found.', 'koopo-tickets'),
      ],
    ]);
  }

  public static function ajax_search_event_authors(): void {
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(['message' => __('Permission denied.', 'koopo-tickets')], 403);
    }

    check_ajax_referer('koopo_tickets_search_event_authors', 'nonce');

    $event_id = absint($_GET['event_id'] ?? 0);
    if (!$event_id || !current_user_can('edit_post', $event_id)) {
      wp_send_json_error(['message' => __('Invalid event.', 'koopo-tickets')], 400);
    }

    $event = get_post($event_id);
    if (!$event || !self::is_event_post_type($event->post_type)) {
      wp_send_json_error(['message' => __('Invalid event type.', 'koopo-tickets')], 400);
    }

    $term = sanitize_text_field(wp_unslash($_GET['term'] ?? ''));
    if (strlen($term) < 2) {
      wp_send_json([]);
    }

    $users = [];
    $query = new \WP_User_Query([
      'number' => 30,
      'search' => '*' . $term . '*',
      'search_columns' => ['user_login', 'user_email', 'display_name', 'user_nicename'],
      'orderby' => 'display_name',
      'order' => 'ASC',
      'fields' => 'all',
    ]);
    foreach ($query->get_results() as $user) {
      if ($user instanceof \WP_User) {
        $users[(int) $user->ID] = $user;
      }
    }

    $store_query = new \WP_User_Query([
      'number' => 30,
      'meta_query' => [[
        'key' => 'dokan_profile_settings',
        'value' => $term,
        'compare' => 'LIKE',
      ]],
      'orderby' => 'display_name',
      'order' => 'ASC',
      'fields' => 'all',
    ]);
    foreach ($store_query->get_results() as $user) {
      if ($user instanceof \WP_User) {
        $users[(int) $user->ID] = $user;
      }
    }

    $items = [];
    foreach ($users as $user) {
      if (!$user instanceof \WP_User) {
        continue;
      }

      if (function_exists('dokan_is_user_seller') && !dokan_is_user_seller((int) $user->ID, true)) {
        continue;
      }

      $items[] = self::format_vendor_search_result((int) $user->ID, $event_id);
    }

    wp_send_json($items);
  }

  public static function is_event_post_type(string $post_type): bool {
    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter(array_map('sanitize_key', $types));
    if (!$types) {
      $types = ['gd_event'];
    }

    return in_array(sanitize_key($post_type), $types, true);
  }

  public static function get_event_ticket_type_ids(int $event_id): array {
    $ids = get_posts([
      'post_type' => Ticket_Types_CPT::POST_TYPE,
      'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
      'fields' => 'ids',
      'posts_per_page' => -1,
      'no_found_rows' => true,
      'meta_query' => [[
        'key' => Ticket_Types_API::META_EVENT_ID,
        'value' => absint($event_id),
        'compare' => '=',
      ]],
    ]);

    return array_values(array_map('absint', $ids));
  }

  public static function get_event_ticket_product_ids(int $event_id, array $ticket_type_ids = []): array {
    $ids = [];
    $parent_id = (int) get_post_meta($event_id, WC_Ticket_Product::META_EVENT_PRODUCT_ID, true);
    if ($parent_id) {
      $ids[] = $parent_id;
      $product = wc_get_product($parent_id);
      if ($product && method_exists($product, 'get_children')) {
        $ids = array_merge($ids, array_map('absint', $product->get_children()));
      }
    }

    foreach ($ticket_type_ids as $ticket_type_id) {
      $product_id = (int) get_post_meta($ticket_type_id, WC_Ticket_Product::META_TICKET_PRODUCT_ID, true);
      $variation_id = (int) get_post_meta($ticket_type_id, WC_Ticket_Product::META_TICKET_VARIATION_ID, true);
      if ($product_id) $ids[] = $product_id;
      if ($variation_id) $ids[] = $variation_id;
    }

    return array_values(array_unique(array_filter(array_map('absint', $ids))));
  }

  private static function update_post_author(int $post_id, int $author_id): void {
    if (!$post_id || (int) get_post_field('post_author', $post_id) === $author_id) {
      return;
    }

    wp_update_post([
      'ID' => $post_id,
      'post_author' => $author_id,
    ]);
  }

  private static function format_vendor_search_result(int $vendor_id, int $event_id): array {
    $user = get_user_by('id', $vendor_id);
    $store_name = '';
    if (function_exists('dokan_get_store_info')) {
      $store_info = dokan_get_store_info($vendor_id);
      if (is_array($store_info) && !empty($store_info['store_name'])) {
        $store_name = (string) $store_info['store_name'];
      }
    }

    $primary = $store_name ?: ($user ? $user->display_name : __('Vendor', 'koopo-tickets'));
    $email = $user ? (string) $user->user_email : '';
    $capacity = self::get_vendor_product_capacity_label($vendor_id);
    $validation = self::validate_event_author_change($event_id, $vendor_id);
    $disabled = is_wp_error($validation);
    $reason = $disabled ? $validation->get_error_message() : '';

    return [
      'id' => $vendor_id,
      'value' => $primary,
      'label' => trim($primary . ($email ? ' <' . $email . '>' : '')),
      'meta' => $capacity,
      'disabled' => $disabled,
      'reason' => $reason,
    ];
  }

  private static function get_vendor_product_capacity_label(int $vendor_id): string {
    $remaining = true;
    if (class_exists('\DokanPro\Modules\Subscription\Helper')) {
      $remaining = \DokanPro\Modules\Subscription\Helper::get_vendor_remaining_products($vendor_id);
    } elseif ('0' === (string) get_user_meta($vendor_id, 'can_post_product', true)) {
      $remaining = 0;
    }

    if ($remaining === true) {
      return __('Product slots: unlimited', 'koopo-tickets');
    }

    $remaining = max(0, (int) $remaining);
    if ($remaining === 1) {
      return __('Product slots: 1 remaining', 'koopo-tickets');
    }

    return sprintf(__('Product slots: %d remaining', 'koopo-tickets'), $remaining);
  }

  private static function vendor_can_create_event(int $vendor_id, int $event_id): bool {
    $event_type = get_post_type($event_id);
    $type_obj = $event_type ? get_post_type_object($event_type) : null;
    $can_create = true;

    if ($type_obj && !empty($type_obj->cap->create_posts)) {
      $can_create = user_can($vendor_id, $type_obj->cap->create_posts);
    } elseif ($type_obj && !empty($type_obj->cap->edit_posts)) {
      $can_create = user_can($vendor_id, $type_obj->cap->edit_posts);
    }

    return (bool) apply_filters('koopo_tickets_vendor_can_create_event', $can_create, $vendor_id, $event_id, $event_type);
  }

  private static function get_required_product_slots_for_author_change(int $event_id, int $vendor_id): int {
    $parent_id = (int) get_post_meta($event_id, WC_Ticket_Product::META_EVENT_PRODUCT_ID, true);
    if (!$parent_id) {
      return 0;
    }

    $current_author = (int) get_post_field('post_author', $parent_id);
    $required = $current_author === $vendor_id ? 0 : 1;

    return (int) apply_filters('koopo_tickets_author_change_required_product_slots', $required, $event_id, $vendor_id, $parent_id);
  }

  private static function vendor_has_product_capacity(int $vendor_id, int $needed_slots): bool {
    if ($needed_slots < 1) {
      return true;
    }

    $remaining = true;
    if (class_exists('\DokanPro\Modules\Subscription\Helper')) {
      $remaining = \DokanPro\Modules\Subscription\Helper::get_vendor_remaining_products($vendor_id);
    } elseif ('0' === (string) get_user_meta($vendor_id, 'can_post_product', true)) {
      $remaining = 0;
    }

    if ($remaining === true) {
      return (bool) apply_filters('koopo_tickets_vendor_has_product_capacity', true, $vendor_id, $needed_slots, $remaining);
    }

    $has_capacity = (int) $remaining >= $needed_slots;
    return (bool) apply_filters('koopo_tickets_vendor_has_product_capacity', $has_capacity, $vendor_id, $needed_slots, $remaining);
  }

  private static function queue_admin_notice(string $message, string $type = 'error'): void {
    if (!is_admin() || !get_current_user_id()) {
      return;
    }

    set_transient(self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), [
      'message' => $message,
      'type' => $type,
    ], MINUTE_IN_SECONDS);
  }

  public static function render_admin_notices(): void {
    if (!get_current_user_id()) {
      return;
    }

    $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
    $notice = get_transient($key);
    if (!$notice || empty($notice['message'])) {
      return;
    }

    delete_transient($key);
    $type = !empty($notice['type']) && $notice['type'] === 'success' ? 'success' : 'error';
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
  }

  public static function register_meta_boxes(): void {
    foreach (self::get_event_post_types() as $post_type) {
      add_meta_box(
        'koopo-ticket-author-sync',
        __('Koopo Ticket Ownership', 'koopo-tickets'),
        [__CLASS__, 'render_event_meta_box'],
        $post_type,
        'side',
        'default'
      );
    }
  }

  public static function render_event_meta_box(\WP_Post $post): void {
    if (!current_user_can('edit_post', $post->ID)) {
      return;
    }

    $ticket_type_ids = self::get_event_ticket_type_ids((int) $post->ID);
    $product_ids = self::get_event_ticket_product_ids((int) $post->ID, $ticket_type_ids);
    $last_synced = (int) get_post_meta($post->ID, self::META_LAST_SYNCED_AUTHOR, true);
    $author_id = (int) $post->post_author;
    $in_sync = $last_synced === $author_id;
    $current_vendor = self::format_vendor_search_result($author_id, (int) $post->ID);

    echo '<p><label for="koopo-ticket-event-author-search"><strong>' . esc_html__('Event Author', 'koopo-tickets') . '</strong></label></p>';
    echo '<input type="text" class="widefat" id="koopo-ticket-event-author-search" value="' . esc_attr($current_vendor['label']) . '" autocomplete="off" data-event-id="' . esc_attr((string) $post->ID) . '">';
    echo '<input type="hidden" id="koopo-ticket-event-author-id" name="koopo_ticket_event_author_id" value="' . esc_attr((string) $author_id) . '">';
    wp_nonce_field('koopo_tickets_event_author_' . $post->ID, 'koopo_ticket_event_author_nonce');
    echo '<p class="description" id="koopo-ticket-event-author-status">' . esc_html($current_vendor['meta']) . '</p>';
    echo '<hr>';
    echo '<p>' . esc_html__('Ticket types and ticket products should match the event author.', 'koopo-tickets') . '</p>';
    echo '<p><strong>' . esc_html__('Ticket types:', 'koopo-tickets') . '</strong> ' . esc_html((string) count($ticket_type_ids)) . '<br>';
    echo '<strong>' . esc_html__('Ticket products:', 'koopo-tickets') . '</strong> ' . esc_html((string) count($product_ids)) . '<br>';
    echo '<strong>' . esc_html__('Sync status:', 'koopo-tickets') . '</strong> ' . esc_html($in_sync ? __('Synced', 'koopo-tickets') : __('Needs sync', 'koopo-tickets')) . '</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('koopo_tickets_resync_event_author_' . $post->ID);
    echo '<input type="hidden" name="action" value="koopo_tickets_resync_event_author">';
    echo '<input type="hidden" name="event_id" value="' . esc_attr((string) $post->ID) . '">';
    submit_button(__('Resync Ticket Ownership', 'koopo-tickets'), 'secondary', 'submit', false);
    echo '</form>';
  }

  public static function handle_resync_request(): void {
    $event_id = absint($_POST['event_id'] ?? 0);
    if (!$event_id || !current_user_can('edit_post', $event_id)) {
      wp_die(esc_html__('You are not allowed to resync this event.', 'koopo-tickets'));
    }

    check_admin_referer('koopo_tickets_resync_event_author_' . $event_id);

    $event = get_post($event_id);
    if (!$event || !self::is_event_post_type($event->post_type)) {
      wp_die(esc_html__('Invalid event.', 'koopo-tickets'));
    }

    $validation = self::validate_event_author_change($event_id, (int) $event->post_author);
    if (is_wp_error($validation)) {
      self::queue_admin_notice($validation->get_error_message(), 'error');
    } else {
      $result = self::sync_event_author($event_id, (int) $event->post_author);
      if (is_wp_error($result)) {
        self::queue_admin_notice($result->get_error_message(), 'error');
      } else {
        self::queue_admin_notice(__('Ticket ownership synced to the event author.', 'koopo-tickets'), 'success');
      }
    }

    $redirect = get_edit_post_link($event_id, '');
    wp_safe_redirect($redirect ?: admin_url('edit.php'));
    exit;
  }

  private static function get_event_post_types(): array {
    $types = Settings::get('event_cpt');
    $types = is_array($types) ? $types : [$types];
    $types = array_filter(array_map('sanitize_key', $types));
    return $types ?: ['gd_event'];
  }
}
