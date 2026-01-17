<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Admin_Settings {
  public static function init() {
    add_action('admin_menu', [__CLASS__, 'register_menu']);
  }

  public static function register_menu() {
    add_options_page(
      'Koopo Tickets',
      'Koopo Tickets',
      'manage_options',
      Settings::SETTINGS_PAGE,
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page() {
    echo '<div class="wrap">';
    echo '<h1>Koopo Tickets Settings</h1>';
    echo '<form method="post" action="options.php">';

    settings_fields(Settings::SETTINGS_GROUP);
    do_settings_sections(Settings::SETTINGS_PAGE);
    submit_button();

    echo '</form>';
    echo '</div>';
  }
}
