<?php

defined('ABSPATH') || exit;

?>
<div class="dokan-dashboard-wrap">
  <?php
            do_action( 'dokan_dashboard_content_before' );
    ?>
  <div class="dokan-dashboard-content">
    <h2><?php echo esc_html__('Ticket Types', 'koopo-tickets'); ?></h2>
    <p class="koopo-tickets-note"><?php echo esc_html__('Create ticket types for your events. These are stored as private records and later linked to WooCommerce products.', 'koopo-tickets'); ?></p>

    <div class="koopo-tickets-card">
      <h3><?php echo esc_html__('Create Ticket Type', 'koopo-tickets'); ?></h3>
      <div id="koopo-ticket-notice" class="koopo-tickets-notice" style="display:none;"></div>
      <form id="koopo-ticket-create">
        <input type="hidden" id="koopo-ticket-id" value="">
        <div class="koopo-tickets-grid">
          <div>
            <label for="koopo-ticket-title"><?php echo esc_html__('Ticket Name', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-title" type="text" required>
          </div>
          <div>
            <label for="koopo-ticket-event"><?php echo esc_html__('Event', 'koopo-tickets'); ?></label>
            <select id="koopo-ticket-event" required></select>
          </div>
          <div>
            <label for="koopo-ticket-price"><?php echo esc_html__('Price', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-price" type="number" min="0" step="0.01">
          </div>
          <div>
            <label for="koopo-ticket-capacity"><?php echo esc_html__('Capacity', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-capacity" type="number" min="0" step="1">
          </div>
          <div>
            <label for="koopo-ticket-status"><?php echo esc_html__('Status', 'koopo-tickets'); ?></label>
            <select id="koopo-ticket-status">
              <option value="active"><?php echo esc_html__('Active', 'koopo-tickets'); ?></option>
              <option value="inactive"><?php echo esc_html__('Inactive', 'koopo-tickets'); ?></option>
            </select>
          </div>
          <div>
            <label for="koopo-ticket-visibility"><?php echo esc_html__('Visibility', 'koopo-tickets'); ?></label>
            <select id="koopo-ticket-visibility">
              <option value="public"><?php echo esc_html__('Public', 'koopo-tickets'); ?></option>
              <option value="private"><?php echo esc_html__('Private', 'koopo-tickets'); ?></option>
            </select>
          </div>
          <div>
            <label for="koopo-ticket-sales-mode"><?php echo esc_html__('Sales Rule', 'koopo-tickets'); ?></label>
            <select id="koopo-ticket-sales-mode">
              <option value="event_start"><?php echo esc_html__('Sell until event starts', 'koopo-tickets'); ?></option>
              <option value="event_end"><?php echo esc_html__('Sell until event ends', 'koopo-tickets'); ?></option>
              <option value="custom"><?php echo esc_html__('Set start and end dates', 'koopo-tickets'); ?></option>
            </select>
          </div>
          <div class="koopo-ticket-custom-dates" style="display:none;">
            <label for="koopo-ticket-sales-start"><?php echo esc_html__('Sales Start', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-sales-start" type="date">
          </div>
          <div class="koopo-ticket-custom-dates" style="display:none;">
            <label for="koopo-ticket-sales-end"><?php echo esc_html__('Sales End', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-sales-end" type="date">
          </div>
          <div>
            <label for="koopo-ticket-sku"><?php echo esc_html__('SKU', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-sku" type="text">
          </div>
          <div>
            <label for="koopo-ticket-max"><?php echo esc_html__('Max per order', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-max" type="number" min="0" step="1" placeholder="<?php echo esc_attr__('0 = unlimited', 'koopo-tickets'); ?>">
          </div>
        </div>
        <div class="koopo-tickets-actions">
          <button type="submit" class="button button-primary" id="koopo-ticket-submit"><?php echo esc_html__('Create Ticket Type', 'koopo-tickets'); ?></button>
          <button type="button" class="button" id="koopo-ticket-cancel" style="display:none;"><?php echo esc_html__('Cancel', 'koopo-tickets'); ?></button>
        </div>
      </form>
    </div>

    <div class="koopo-tickets-card">
      <h3><?php echo esc_html__('Existing Ticket Types', 'koopo-tickets'); ?></h3>
      <div class="koopo-tickets-filters">
        <div>
          <label for="koopo-ticket-filter-search"><?php echo esc_html__('Search', 'koopo-tickets'); ?></label>
          <input id="koopo-ticket-filter-search" type="text" placeholder="<?php echo esc_attr__('Ticket name...', 'koopo-tickets'); ?>">
        </div>
        <div>
          <label for="koopo-ticket-filter-event"><?php echo esc_html__('Event', 'koopo-tickets'); ?></label>
          <select id="koopo-ticket-filter-event"></select>
        </div>
        <div>
          <label for="koopo-ticket-filter-status"><?php echo esc_html__('Status', 'koopo-tickets'); ?></label>
          <select id="koopo-ticket-filter-status">
            <option value=""><?php echo esc_html__('All', 'koopo-tickets'); ?></option>
            <option value="active"><?php echo esc_html__('Active', 'koopo-tickets'); ?></option>
            <option value="inactive"><?php echo esc_html__('Inactive', 'koopo-tickets'); ?></option>
          </select>
        </div>
        <div>
          <label for="koopo-ticket-filter-visibility"><?php echo esc_html__('Visibility', 'koopo-tickets'); ?></label>
          <select id="koopo-ticket-filter-visibility">
            <option value=""><?php echo esc_html__('All', 'koopo-tickets'); ?></option>
            <option value="public"><?php echo esc_html__('Public', 'koopo-tickets'); ?></option>
            <option value="private"><?php echo esc_html__('Private', 'koopo-tickets'); ?></option>
          </select>
        </div>
      </div>
      <table class="koopo-tickets-table">
        <thead>
          <tr>
            <th><?php echo esc_html__('Ticket', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Event', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Price', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Capacity', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Status', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Visibility', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Sales Rule', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('SKU', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Product', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Variation', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Max/Order', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Actions', 'koopo-tickets'); ?></th>
          </tr>
        </thead>
        <tbody id="koopo-ticket-types-body">
          <tr><td colspan="12"><?php echo esc_html__('Loading...', 'koopo-tickets'); ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
