<?php

defined('ABSPATH') || exit;

use Koopo_Tickets\Ticket_Types_CPT;
use Koopo_Tickets\Ticket_Types_API;

$user_id = get_current_user_id();

$query = new WP_Query([
  'post_type' => Ticket_Types_CPT::POST_TYPE,
  'post_status' => 'publish',
  'author' => $user_id,
  'posts_per_page' => 200,
  'orderby' => 'title',
  'order' => 'ASC',
  'fields' => 'ids',
  'no_found_rows' => true,
]);

?>
<div class="dokan-dashboard-wrap">
  <div class="dokan-dashboard-content">
    <h2><?php echo esc_html__('Ticket Types', 'koopo-tickets'); ?></h2>
    <p><?php echo esc_html__('Manage your event ticket types. UI wiring will be added in the next milestone.', 'koopo-tickets'); ?></p>

    <?php if (empty($query->posts)) : ?>
      <p><?php echo esc_html__('No ticket types found yet.', 'koopo-tickets'); ?></p>
    <?php else : ?>
      <table class="dokan-table">
        <thead>
          <tr>
            <th><?php echo esc_html__('Ticket', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Event', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Price', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Capacity', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Status', 'koopo-tickets'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($query->posts as $ticket_type_id) : ?>
            <?php
            $event_id = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_EVENT_ID, true);
            $event_title = $event_id ? get_the_title($event_id) : '';
            $price = (float) get_post_meta($ticket_type_id, Ticket_Types_API::META_PRICE, true);
            $capacity = (int) get_post_meta($ticket_type_id, Ticket_Types_API::META_CAPACITY, true);
            $status = (string) get_post_meta($ticket_type_id, Ticket_Types_API::META_STATUS, true);
            ?>
            <tr>
              <td><?php echo esc_html(get_the_title($ticket_type_id)); ?></td>
              <td><?php echo esc_html($event_title ?: '—'); ?></td>
              <td><?php echo esc_html($price ? wc_price($price) : '—'); ?></td>
              <td><?php echo esc_html($capacity ?: '—'); ?></td>
              <td><?php echo esc_html($status ?: 'active'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
