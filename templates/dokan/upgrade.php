<?php
/** Subscription discovery; purchases use the existing Dokan/WooCommerce flow. */
defined('ABSPATH') || exit;
$packs = \Koopo_Tickets\Access::ticket_packs();
$subscription_url = dokan_get_navigation_url('subscription');
?>
<div class="dokan-dashboard-wrap" id="koopo-ticket-upgrade">
  <?php do_action('dokan_dashboard_content_before'); ?>
  <div class="dokan-dashboard-content">
    <section class="kt-upgrade" aria-labelledby="kt-upgrade-title">
      <div class="kt-upgrade-intro">
        <span class="kt-upgrade-status"><?php esc_html_e('EVENT TICKETS · UPGRADE REQUIRED', 'koopo-tickets'); ?></span>
        <h2 id="kt-upgrade-title"><?php esc_html_e('Your next event starts here.', 'koopo-tickets'); ?></h2>
        <p><?php esc_html_e('Turn your gathering into a ticketed experience. Choose a seller membership to unlock ticket sales on Koopo.', 'koopo-tickets'); ?></p>
        <div class="kt-ticket-preview" aria-hidden="true">
          <div><span>EVENTS BY KOOPO</span><strong>Bring people together.</strong><small>Workshops · Live shows · Community events</small></div>
          <svg viewBox="0 0 64 64" width="64" height="64" fill="none"><path d="M9 21h46v9a7 7 0 0 0 0 14v9H9v-9a7 7 0 0 0 0-14z" stroke="currentColor" stroke-width="3"/><path d="M42 22v6m0 5v6m0 5v7" stroke="currentColor" stroke-width="3"/></svg>
        </div>
      </div>
      <div class="kt-upgrade-plans">
        <h3><?php esc_html_e('Choose your seller membership', 'koopo-tickets'); ?></h3>
        <p class="kt-upgrade-muted"><?php esc_html_e('Every plan below includes event ticket sales. Compare the extras to find your fit.', 'koopo-tickets'); ?></p>
        <?php foreach ($packs as $index => $pack):
          $features = \Koopo_Tickets\Access::pack_features($pack->get_id());
          $interval = max(1, (int) $pack->get_meta('_dokan_subscription_period_interval'));
          $periods = ['day' => __('day', 'koopo-tickets'), 'week' => __('week', 'koopo-tickets'), 'month' => __('month', 'koopo-tickets'), 'year' => __('year', 'koopo-tickets')];
          $period = $periods[$pack->get_meta('_dokan_subscription_period')] ?? '';
        ?>
          <details class="kt-plan" <?php if (!$index) echo 'open'; ?>>
            <summary>
              <span><strong><?php echo esc_html($pack->get_name()); ?></strong><span class="kt-plan-price"><?php echo wp_kses_post($pack->get_price_html()); ?><?php if ('yes' === $pack->get_meta('_enable_recurring_payment') && $period): ?> <small><?php echo esc_html($interval === 1 ? sprintf(__('per %s', 'koopo-tickets'), $period) : sprintf(__('every %1$d %2$s periods', 'koopo-tickets'), $interval, $period)); ?></small><?php endif; ?></span></span>
              <span class="kt-plan-tag"><?php echo esc_html(!$index ? __('Lowest price', 'koopo-tickets') : __('Explore plan', 'koopo-tickets')); ?></span>
            </summary>
            <div class="kt-plan-body">
              <ul>
                <li><?php esc_html_e('Event ticket sales, ticket types and QR check-in', 'koopo-tickets'); ?></li>
                <?php if (!empty($features['appointments'])): ?><li><?php esc_html_e('Appointment booking tools', 'koopo-tickets'); ?></li><?php endif; ?>
                <?php $count = $pack->get_meta('_no_of_product'); if (is_numeric($count)): ?><li><?php echo esc_html((int) $count < 0 ? __('Unlimited product listings', 'koopo-tickets') : sprintf(__('%d product listings', 'koopo-tickets'), (int) $count)); ?></li><?php endif; ?>
              </ul>
              <?php if ($pack->get_short_description()): ?><div class="kt-plan-description"><?php echo wp_kses_post(wpautop($pack->get_short_description())); ?></div><?php endif; ?>
              <a class="kt-upgrade-button" href="<?php echo esc_url($pack->get_permalink()); ?>"><?php echo esc_html(sprintf(__('Choose %s', 'koopo-tickets'), $pack->get_name())); ?><span aria-hidden="true">→</span></a>
              <p class="kt-upgrade-muted kt-upgrade-footnote"><?php esc_html_e('Review this plan and continue to secure checkout. Access begins when your membership is active.', 'koopo-tickets'); ?></p>
            </div>
          </details>
        <?php endforeach; ?>
        <?php if (!$packs): ?><p><?php esc_html_e('Ticket memberships are currently unavailable. Check the subscription page for available plans.', 'koopo-tickets'); ?></p><?php endif; ?>
        <a class="kt-all-plans" href="<?php echo esc_url($subscription_url); ?>"><?php esc_html_e('Manage membership & compare all seller plans', 'koopo-tickets'); ?> <span aria-hidden="true">→</span></a>
      </div>
      <footer class="kt-upgrade-unlocks"><strong><?php esc_html_e('Unlock with membership', 'koopo-tickets'); ?></strong><ul><li><?php esc_html_e('Ticket sales', 'koopo-tickets'); ?></li><li><?php esc_html_e('Sales analytics', 'koopo-tickets'); ?></li><li><?php esc_html_e('QR check-in', 'koopo-tickets'); ?></li></ul></footer>
    </section>
  </div>
  <?php do_action('dokan_dashboard_content_after'); ?>
</div>
