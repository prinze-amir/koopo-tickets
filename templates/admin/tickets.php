<?php

defined('ABSPATH') || exit;

$event_cpt = \Koopo_Tickets\Settings::get('event_cpt');
if (is_array($event_cpt)) {
  $event_cpt = reset($event_cpt);
}
$event_cpt = sanitize_key((string) $event_cpt);
if (!$event_cpt) {
  $event_cpt = 'gd_event';
}

$add_event_url = '';
if (current_user_can('edit_posts')) {
  $add_event_url = admin_url('post-new.php?post_type=' . $event_cpt);
}

?>
<div id="koopo-ticket-dashboard" class="koopo-admin-ticket-dashboard">
    <div class="koopo-tickets-page-head">
      <div>
        <h1><?php echo esc_html__('Event Tickets', 'koopo-tickets'); ?></h1>
        <p class="koopo-tickets-note"><?php echo esc_html__('Select an event, then manage its ticket types and WooCommerce ticket product links.', 'koopo-tickets'); ?></p>
      </div>
      <?php if (!empty($add_event_url)): ?>
      <div class="koopo-tickets-page-actions">
        <a class="button button-primary" href="<?php echo esc_url($add_event_url); ?>"><?php echo esc_html__('Add New Event', 'koopo-tickets'); ?></a>
      </div>
      <?php endif; ?>
    </div>

    <div class="koopo-tickets-card" id="koopo-event-selector-card">
      <h3><?php echo esc_html__('Select Event', 'koopo-tickets'); ?></h3>
      <div class="koopo-tickets-filters">
        <div>
          <label for="koopo-event-filter-search"><?php echo esc_html__('Search Events', 'koopo-tickets'); ?></label>
          <input id="koopo-event-filter-search" type="text" placeholder="<?php echo esc_attr__('Event title...', 'koopo-tickets'); ?>">
        </div>
      </div>
      <div id="koopo-event-grid" class="koopo-event-grid"></div>
      <div id="koopo-event-pagination" class="koopo-tickets-pagination" style="display:none;"></div>
      <p id="koopo-event-empty" class="koopo-tickets-note" style="display:none;"><?php echo esc_html__('No events found.', 'koopo-tickets'); ?></p>
    </div>

    <div id="koopo-ticket-workspace" style="display:none;">
      <div id="koopo-ticket-notice" class="koopo-tickets-notice" style="display:none;"></div>

      <div class="koopo-tickets-card">
        <div class="koopo-ticket-workspace-head">
          <div>
            <button type="button" class="button" id="koopo-event-back-mobile"><?php echo esc_html__('Back to Events', 'koopo-tickets'); ?></button>
            <h3 id="koopo-selected-event-title"><?php echo esc_html__('Selected Event', 'koopo-tickets'); ?></h3>
            <p id="koopo-selected-event-meta" class="koopo-tickets-note"></p>
          </div>
          <div class="koopo-tickets-actions">
            <button type="button" class="button button-primary" id="koopo-ticket-open-create"><?php echo esc_html__('New Ticket Type', 'koopo-tickets'); ?></button>
          </div>
        </div>
      </div>

      <div class="koopo-tickets-card">
      <h3><?php echo esc_html__('Existing Ticket Types', 'koopo-tickets'); ?></h3>
      <div class="koopo-tickets-filters">
        <div>
          <label for="koopo-ticket-filter-search"><?php echo esc_html__('Search', 'koopo-tickets'); ?></label>
          <input id="koopo-ticket-filter-search" type="text" placeholder="<?php echo esc_attr__('Ticket name...', 'koopo-tickets'); ?>">
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
      <div class="koopo-tickets-table-wrap">
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
            <tr class="koopo-tickets-table__message-row"><td class="koopo-tickets-table__message" colspan="12"><?php echo esc_html__('Loading...', 'koopo-tickets'); ?></td></tr>
          </tbody>
        </table>
      </div>
      <div id="koopo-ticket-types-pagination" class="koopo-tickets-pagination" style="display:none;"></div>
      </div>

      <div class="koopo-tickets-card" style="display:none;">
        <h3><?php echo esc_html__('Ticket Check-In', 'koopo-tickets'); ?></h3>
        <p class="koopo-tickets-note"><?php echo esc_html__('Verify and redeem tickets by code.', 'koopo-tickets'); ?></p>
        <form id="koopo-ticket-checkin">
          <div class="koopo-tickets-grid">
            <div>
              <label for="koopo-ticket-code"><?php echo esc_html__('Ticket Code', 'koopo-tickets'); ?></label>
              <input id="koopo-ticket-code" type="text" required>
            </div>
          </div>
          <div class="koopo-tickets-actions">
            <button type="submit" class="button button-primary"><?php echo esc_html__('Verify', 'koopo-tickets'); ?></button>
            <button type="button" class="button" id="koopo-ticket-redeem"><?php echo esc_html__('Redeem', 'koopo-tickets'); ?></button>
            <button type="button" class="button" id="koopo-ticket-scan-open"><?php echo esc_html__('Scan QR with Camera', 'koopo-tickets'); ?></button>
          </div>
        </form>
        <div id="koopo-ticket-scan-panel" class="koopo-ticket-scan-panel" style="display:none;">
          <video id="koopo-ticket-scan-video" playsinline></video>
          <div class="koopo-tickets-actions">
            <select id="koopo-ticket-camera-select"></select>
            <button type="button" class="button" id="koopo-ticket-scan-start"><?php echo esc_html__('Start Camera', 'koopo-tickets'); ?></button>
            <button type="button" class="button" id="koopo-ticket-scan-stop"><?php echo esc_html__('Stop Camera', 'koopo-tickets'); ?></button>
          </div>
          <p id="koopo-ticket-scan-status" class="koopo-tickets-note"></p>
        </div>
        <div id="koopo-ticket-checkin-result" class="koopo-tickets-note" style="margin-top:10px;"></div>
      </div>
    </div>

    <div id="koopo-ticket-form-modal" class="koopo-ticket-form-modal" style="display:none;">
      <div class="koopo-ticket-form-dialog">
        <button type="button" class="koopo-ticket-modal-close" id="koopo-ticket-modal-close">&times;</button>
        <h3 id="koopo-ticket-modal-title"><?php echo esc_html__('Create Ticket Type', 'koopo-tickets'); ?></h3>
        <div class="koopo-ticket-modal-steps">
          <span class="is-active" data-step-indicator="1"><?php echo esc_html__('Basics', 'koopo-tickets'); ?></span>
          <span data-step-indicator="2"><?php echo esc_html__('Availability', 'koopo-tickets'); ?></span>
          <span data-step-indicator="3"><?php echo esc_html__('Date Pricing', 'koopo-tickets'); ?></span>
        </div>
        <form id="koopo-ticket-create">
          <input type="hidden" id="koopo-ticket-id" value="">
          <input type="hidden" id="koopo-ticket-event" value="">

          <div class="koopo-ticket-step is-active" data-step-panel="1">
            <div class="koopo-tickets-grid">
              <div>
                <label for="koopo-ticket-title"><?php echo esc_html__('Ticket Name', 'koopo-tickets'); ?></label>
                <input id="koopo-ticket-title" type="text" required>
              </div>
              <div>
                <label for="koopo-ticket-price"><?php echo esc_html__('Price', 'koopo-tickets'); ?></label>
                <input id="koopo-ticket-price" type="number" min="0" step="0.01">
              </div>
              <div>
                <label for="koopo-ticket-capacity"><?php echo esc_html__('Capacity', 'koopo-tickets'); ?></label>
                <input id="koopo-ticket-capacity" type="number" min="0" step="1" value="100">
              </div>
              <div>
                <label for="koopo-ticket-unlimited"><?php echo esc_html__('Unlimited capacity', 'koopo-tickets'); ?></label>
                <div>
                  <label><input id="koopo-ticket-unlimited" type="checkbox"> <?php echo esc_html__('No capacity limit', 'koopo-tickets'); ?></label>
                </div>
              </div>
              <div>
                <label for="koopo-ticket-max"><?php echo esc_html__('Max per order', 'koopo-tickets'); ?></label>
                <input id="koopo-ticket-max" type="number" min="0" step="1" placeholder="<?php echo esc_attr__('0 = unlimited', 'koopo-tickets'); ?>">
              </div>
              <div>
                <label for="koopo-ticket-sku"><?php echo esc_html__('SKU', 'koopo-tickets'); ?></label>
                <input id="koopo-ticket-sku" type="text">
              </div>
              <div>
                <label for="koopo-ticket-image-id"><?php echo esc_html__('Ticket Image Override', 'koopo-tickets'); ?></label>
                <input id="koopo-ticket-image-id" type="hidden" value="">
                <div id="koopo-ticket-image-preview" class="koopo-ticket-image-preview"></div>
                <div class="koopo-tickets-actions">
                  <button type="button" class="button" id="koopo-ticket-image-select"><?php echo esc_html__('Select Image', 'koopo-tickets'); ?></button>
                  <button type="button" class="button" id="koopo-ticket-image-remove"><?php echo esc_html__('Remove', 'koopo-tickets'); ?></button>
                </div>
              </div>
            </div>
          </div>

          <div class="koopo-ticket-step" data-step-panel="2">
            <div class="koopo-tickets-grid">
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
            </div>
          </div>

          <div class="koopo-ticket-step" data-step-panel="3">
            <div id="koopo-ticket-date-prices" class="koopo-ticket-date-prices">
              <label><?php echo esc_html__('Prices by Date/Time (optional)', 'koopo-tickets'); ?></label>
              <div class="koopo-ticket-date-prices__controls">
                <div class="koopo-ticket-date-picker" data-vendor-date-picker></div>
                <div class="koopo-ticket-date-times" data-vendor-date-times></div>
                <input type="hidden" id="koopo-ticket-date-select" value="">
                <div class="koopo-ticket-date-price-input">
                  <input id="koopo-ticket-date-price" type="number" min="0" step="0.01" placeholder="<?php echo esc_attr__('Enter price', 'koopo-tickets'); ?>">
                  <button type="button" class="button" id="koopo-ticket-date-apply"><?php echo esc_html__('Apply', 'koopo-tickets'); ?></button>
                </div>
              </div>
              <div class="koopo-ticket-date-prices__list"></div>
              <p class="koopo-tickets-note"><?php echo esc_html__('Choose a date, set a price, and apply. Only changed dates appear in the list.', 'koopo-tickets'); ?></p>
            </div>
          </div>

          <div class="koopo-tickets-actions">
            <button type="button" class="button" id="koopo-ticket-step-back" style="display:none;"><?php echo esc_html__('Back', 'koopo-tickets'); ?></button>
            <button type="button" class="button" id="koopo-ticket-step-next"><?php echo esc_html__('Next', 'koopo-tickets'); ?></button>
            <button type="submit" class="button button-primary" id="koopo-ticket-submit" style="display:none;"><?php echo esc_html__('Create Ticket Type', 'koopo-tickets'); ?></button>
            <button type="button" class="button" id="koopo-ticket-cancel"><?php echo esc_html__('Cancel', 'koopo-tickets'); ?></button>
          </div>
        </form>
      </div>
    </div>
</div>
