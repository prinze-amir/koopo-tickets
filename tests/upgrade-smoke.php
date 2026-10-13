<?php
/** Run: php tests/upgrade-smoke.php [preview-output.html] */
define('ABSPATH', __DIR__);
$fixture_features = [10 => ['event_tickets' => true], 20 => json_encode(['event_tickets' => true, 'appointments' => true]), 30 => ['event_tickets' => false]];
$fixture_pack = 30;
$fixture_admin = false;
$fixture_seller = true;
$fixture_override = null;
function get_current_user_id() { return 1; }
function user_can($id, $cap) { return $GLOBALS['fixture_admin']; }
function dokan_is_user_seller($id) { return $GLOBALS['fixture_seller']; }
function get_user_meta($id, $key, $single) { return $GLOBALS['fixture_pack']; }
function get_post_meta($id, $key, $single) { return $GLOBALS['fixture_features'][$id] ?? []; }
function apply_filters($key, $value, ...$args) { return $key === 'koopo_tickets_vendor_access' && $GLOBALS['fixture_override'] !== null ? $GLOBALS['fixture_override'] : $value; }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html_e($text, $domain) { echo esc_html($text); }
function esc_url($url) { return esc_html($url); }
function wp_kses_post($html) { return $html; }
function wpautop($html) { return '<p>' . $html . '</p>'; }
function do_action($hook) {}
function dokan_get_navigation_url($slug) { return '/seller-dashboard/' . $slug . '/'; }
class Fixture_Pack {
  private $id;
  function __construct($id) { $this->id = $id; }
  function get_id() { return $this->id; }
  function get_price() { return $this->id === 10 ? 24.99 : 49.99; }
  function is_purchasable() { return true; }
  function get_name() { return $this->id === 10 ? 'Entrepreneur' : 'Business'; }
  function get_price_html() { return '$' . $this->get_price(); }
  function get_meta($key) { return ['_enable_recurring_payment'=>'yes', '_dokan_subscription_period'=>'month', '_dokan_subscription_period_interval'=>1, '_no_of_product'=>($this->id === 10 ? 25 : -1)][$key] ?? ''; }
  function get_short_description() { return ''; }
  function get_permalink() { return '/product/plan-' . $this->id . '/'; }
}
function wc_get_products($args) { return [new Fixture_Pack(20), new Fixture_Pack(30), new Fixture_Pack(10)]; }
define('KOOPO_TICKETS_PATH', dirname(__DIR__) . '/');
define('KOOPO_TICKETS_URL', '/tickets/');
function dokan_is_seller_dashboard() { return true; }
function wp_enqueue_style(...$args) { $GLOBALS['enqueued_styles'][] = $args[0]; }
function wp_enqueue_script(...$args) { throw new RuntimeException('Locked dashboard must not load management scripts'); }
require __DIR__ . '/../includes/core/class-kt-access.php';
require __DIR__ . '/../includes/dokan/class-kt-dokan-dashboard.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
check(!\Koopo_Tickets\Access::vendor_can_manage_tickets(), 'Non-ticket pack must be denied');
$fixture_pack = 0;
check(!\Koopo_Tickets\Access::vendor_can_manage_tickets(), 'No pack must be denied');
$fixture_pack = 10;
check(\Koopo_Tickets\Access::vendor_can_manage_tickets(), 'Ticket pack must pass');
$fixture_seller = false;
check(!\Koopo_Tickets\Access::vendor_can_manage_tickets(), 'Non-vendor must be denied');
$fixture_admin = true;
check(\Koopo_Tickets\Access::vendor_can_manage_tickets(), 'Administrator bypass must pass');
$fixture_admin = false;
$fixture_override = true;
check(\Koopo_Tickets\Access::vendor_can_manage_tickets(), 'Existing filter override must pass');
$fixture_override = null;
$fixture_seller = true;
$fixture_pack = 30;
check(isset(\Koopo_Tickets\Dokan_Dashboard::add_nav_items([])['tickets']), 'Locked seller retains Tickets navigation');
$wp_query = (object) ['query_vars' => ['koopo-tickets' => '']];
\Koopo_Tickets\Dokan_Dashboard::enqueue_assets();
check(in_array('koopo-tickets-upgrade', $enqueued_styles), 'Locked dashboard receives upgrade stylesheet');
ob_start();
\Koopo_Tickets\Dokan_Dashboard::load_templates(['koopo-tickets' => '']);
$locked_html = ob_get_clean();
check(strpos($locked_html, 'kt-upgrade-title') !== false, 'Locked route renders upgrade screen');
$packs = \Koopo_Tickets\Access::ticket_packs();
check(count($packs) === 2 && $packs[0]->get_id() === 10, 'Only ticket-enabled packs, cheapest first');
ob_start();
include __DIR__ . '/../templates/dokan/upgrade.php';
$html = ob_get_clean();
check(strpos($html, '/product/plan-10/') !== false && strpos($html, '/product/plan-30/') === false, 'Only eligible plan purchase destinations');
check(strpos($html, 'Appointment booking tools') !== false, 'Configured extras rendered');
$fixture_features = [];
ob_start();
include __DIR__ . '/../templates/dokan/upgrade.php';
$empty = ob_get_clean();
check(strpos($empty, 'currently unavailable') !== false, 'Empty catalog fallback');
if (!empty($argv[1])) {
  $css = file_get_contents(__DIR__ . '/../assets/upgrade.css');
  file_put_contents($argv[1], '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ticket upgrade — fixture preview</title><style>body{margin:0;padding:32px;background:#f4f4f1;font-family:Arial,sans-serif}*{box-sizing:border-box} @media(max-width:600px){body{padding:12px}}' . $css . '</style>' . $html . '</html>');
}
echo "PASS: access rules, eligible plans, price ordering, links, extras and empty state\n";
