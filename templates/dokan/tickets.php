<?php

defined('ABSPATH') || exit;

?>
<div class="dokan-dashboard-wrap">
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
            <label for="koopo-ticket-sales-start"><?php echo esc_html__('Sales Start', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-sales-start" type="date">
          </div>
          <div>
            <label for="koopo-ticket-sales-end"><?php echo esc_html__('Sales End', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-sales-end" type="date">
          </div>
          <div>
            <label for="koopo-ticket-sku"><?php echo esc_html__('SKU', 'koopo-tickets'); ?></label>
            <input id="koopo-ticket-sku" type="text">
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
      <table class="koopo-tickets-table">
        <thead>
          <tr>
            <th><?php echo esc_html__('Ticket', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Event', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Price', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Capacity', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Status', 'koopo-tickets'); ?></th>
            <th><?php echo esc_html__('Actions', 'koopo-tickets'); ?></th>
          </tr>
        </thead>
        <tbody id="koopo-ticket-types-body">
          <tr><td colspan="6"><?php echo esc_html__('Loading...', 'koopo-tickets'); ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
