<?php

defined('ABSPATH') || exit;

$event_title = $print_data['event_title'] ?? '';
$event_image = $print_data['event_image'] ?? '';
$event_url = $print_data['event_url'] ?? '';
$schedule_label = $print_data['schedule_label'] ?? '';
$location = $print_data['location'] ?? '';
$codes = $print_data['codes'] ?? [];
$qr_svgs = $print_data['qr_svgs'] ?? [];
$logo = $print_data['logo'] ?? '';

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo esc_html__('Ticket Print', 'koopo-tickets'); ?></title>
  <?php wp_head(); ?>
</head>
<body class="koopo-ticket-print">
  <div class="koopo-ticket-print__wrap">
    <div class="koopo-ticket-print__header">
      <div class="koopo-ticket-print__brand">
        <?php if ($logo) : ?>
          <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr__('Site Logo', 'koopo-tickets'); ?>">
        <?php endif; ?>
        <div class="koopo-ticket-print__tag"><?php echo esc_html__('Events by Koopo', 'koopo-tickets'); ?></div>
      </div>
      <div>
        <h1><?php echo esc_html($event_title); ?></h1>
        <?php if ($event_url) : ?>
          <a href="<?php echo esc_url($event_url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('View Event', 'koopo-tickets'); ?></a>
        <?php endif; ?>
        <?php if ($schedule_label) : ?>
          <p><?php echo esc_html($schedule_label); ?></p>
        <?php endif; ?>
        <?php if ($location) : ?>
          <p><?php echo esc_html($location); ?></p>
        <?php endif; ?>
      </div>
      <div class="koopo-ticket-print__actions">
        <button class="button" onclick="window.print()"><?php echo esc_html__('Print', 'koopo-tickets'); ?></button>
        <button class="button" onclick="window.print()"><?php echo esc_html__('Download', 'koopo-tickets'); ?></button>
      </div>
    </div>

    <div class="koopo-ticket-print__grid">
      <?php foreach ($codes as $index => $entry) : ?>
        <div class="koopo-ticket-print__card">
          <h3><?php echo esc_html($entry['label']); ?></h3>
          <?php if (!empty($entry['email'])) : ?>
            <div class="koopo-ticket-print__meta"><?php echo esc_html($entry['email']); ?></div>
          <?php endif; ?>
          <?php if (!empty($entry['phone'])) : ?>
            <div class="koopo-ticket-print__meta"><?php echo esc_html($entry['phone']); ?></div>
          <?php endif; ?>
          <div class="koopo-ticket-print__seat"><?php echo esc_html__('Seat:', 'koopo-tickets'); ?> ____</div>
          <div class="koopo-ticket-print__extra"><?php echo esc_html__('Extra:', 'koopo-tickets'); ?> ____</div>
          <div class="koopo-ticket-print__qr"><?php echo $qr_svgs[$index] ?? ''; ?></div>
          <div class="koopo-ticket-print__code"><?php echo esc_html($entry['code']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php wp_footer(); ?>
  <?php if (!empty($_GET['download'])) : ?>
    <script>
      window.addEventListener('load', function () {
        window.print();
      });
    </script>
  <?php endif; ?>
</body>
</html>
