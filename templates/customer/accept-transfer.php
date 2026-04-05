<?php

defined('ABSPATH') || exit;

$transfer = $data['transfer'] ?? null;
$ticket = $data['ticket'] ?? null;
$ticket_item = $data['ticket_item'] ?? null;
$event_title = $data['event_title'] ?? '';
$event_url = $data['event_url'] ?? '';
$event_image = $data['event_image'] ?? '';
$schedule_label = $data['schedule_label'] ?? '';
$error = $data['error'] ?? '';
$accepted = !empty($data['accepted']);
$links = $data['links'] ?? [];
$login_required = !empty($data['login_required']);
$status = (string) ($transfer->status ?? '');

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo esc_html__('Accept Ticket Transfer', 'koopo-tickets'); ?></title>
  <?php wp_head(); ?>
</head>
<body class="koopo-ticket-transfer-page">
  <div class="koopo-ticket-dashboard koopo-ticket-dashboard--transfer" style="max-width:760px;margin:40px auto;padding:0 16px;">
    <div class="koopo-ticket-card">
      <div class="koopo-ticket-card__header">
        <?php if ($event_image) : ?>
          <div class="koopo-ticket-thumb" style="background-image:url('<?php echo esc_url($event_image); ?>')"></div>
        <?php endif; ?>
        <div class="koopo-ticket-card__info">
          <div class="koopo-ticket-event-title">
            <?php if ($event_url) : ?>
              <a class="koopo-ticket-event-link" href="<?php echo esc_url($event_url); ?>"><?php echo esc_html($event_title ?: __('Ticket Transfer', 'koopo-tickets')); ?></a>
            <?php else : ?>
              <?php echo esc_html($event_title ?: __('Ticket Transfer', 'koopo-tickets')); ?>
            <?php endif; ?>
          </div>
          <?php if ($ticket_item) : ?>
            <div class="koopo-ticket-meta"><?php echo esc_html($ticket_item->get_name()); ?></div>
          <?php endif; ?>
          <?php if ($schedule_label) : ?>
            <div class="koopo-ticket-meta"><?php echo esc_html($schedule_label); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($error) : ?>
        <div class="koopo-ticket-notice is-error" style="display:block;"><?php echo esc_html($error); ?></div>
      <?php endif; ?>

      <?php if ($accepted || $status === 'accepted') : ?>
        <div class="koopo-ticket-notice is-success" style="display:block;"><?php echo esc_html__('This ticket transfer has been accepted.', 'koopo-tickets'); ?></div>
        <div class="koopo-ticket-actions">
          <?php if (!empty($links['view'])) : ?>
            <a class="button" href="<?php echo esc_url($links['view']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View', 'koopo-tickets'); ?></a>
          <?php endif; ?>
          <?php if (!empty($links['print'])) : ?>
            <a class="button" href="<?php echo esc_url($links['print']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Print', 'koopo-tickets'); ?></a>
          <?php endif; ?>
          <?php if (!empty($links['download'])) : ?>
            <a class="button" href="<?php echo esc_url($links['download']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Download', 'koopo-tickets'); ?></a>
          <?php endif; ?>
        </div>
      <?php elseif ($status === 'cancelled') : ?>
        <div class="koopo-ticket-notice is-error" style="display:block;"><?php echo esc_html__('This transfer was cancelled by the original ticket owner.', 'koopo-tickets'); ?></div>
      <?php else : ?>
        <p class="koopo-ticket-meta"><?php echo esc_html__('You have been invited to accept this transferred ticket.', 'koopo-tickets'); ?></p>
        <?php if ($login_required && !is_user_logged_in()) : ?>
          <div class="koopo-ticket-notice" style="display:block;"><?php echo esc_html__('Please log in with the recipient account before accepting this ticket.', 'koopo-tickets'); ?></div>
          <div class="koopo-ticket-actions">
            <a class="button button-primary" href="<?php echo esc_url(wp_login_url(\Koopo_Tickets\Customer_Ticket_Transfers::build_accept_url($transfer))); ?>"><?php echo esc_html__('Log In to Accept', 'koopo-tickets'); ?></a>
          </div>
        <?php else : ?>
          <form method="post">
            <?php wp_nonce_field('koopo_ticket_transfer_' . (int) ($transfer->id ?? 0), 'koopo_ticket_transfer_nonce'); ?>
            <input type="hidden" name="koopo_ticket_transfer_action" value="accept">
            <div class="koopo-ticket-actions">
              <button type="submit" class="button button-primary"><?php echo esc_html__('Accept Ticket', 'koopo-tickets'); ?></button>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php wp_footer(); ?>
</body>
</html>
