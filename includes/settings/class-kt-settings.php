<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Settings {
  const OPTION_KEY = 'koopo_tickets_settings';
  const SETTINGS_GROUP = 'koopo_tickets';
  const SETTINGS_PAGE = 'koopo-tickets';

  public static function init() {
    add_action('admin_init', [__CLASS__, 'register']);
  }

  public static function defaults() {
    return [
      'debug' => false,
      'event_cpt' => 'gd_event',
    ];
  }

  public static function get($key = null) {
    $options = get_option(self::OPTION_KEY, []);
    $options = wp_parse_args($options, self::defaults());

    if (null === $key) return $options;

    return array_key_exists($key, $options) ? $options[$key] : null;
  }

  public static function register() {
    register_setting(
      self::SETTINGS_GROUP,
      self::OPTION_KEY,
      ['sanitize_callback' => [__CLASS__, 'sanitize']]
    );

    add_settings_section(
      'koopo_tickets_main',
      'General',
      '__return_false',
      self::SETTINGS_PAGE
    );

    add_settings_field(
      'debug',
      'Debug mode',
      [__CLASS__, 'render_debug_field'],
      self::SETTINGS_PAGE,
      'koopo_tickets_main'
    );

    add_settings_field(
      'event_cpt',
      'Event CPT',
      [__CLASS__, 'render_event_cpt_field'],
      self::SETTINGS_PAGE,
      'koopo_tickets_main'
    );
  }

  public static function sanitize($input) {
    $defaults = self::defaults();

    $output = [
      'debug' => !empty($input['debug']),
      'event_cpt' => isset($input['event_cpt']) ? sanitize_key($input['event_cpt']) : $defaults['event_cpt'],
    ];

    return $output;
  }

  public static function render_debug_field() {
    $options = self::get();
    $checked = !empty($options['debug']) ? 'checked' : '';

    echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[debug]" value="1" ' . $checked . '> Enable debug logging</label>';
  }

  public static function render_event_cpt_field() {
    $options = self::get();
    $value = isset($options['event_cpt']) ? $options['event_cpt'] : 'gd_event';

    echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_KEY) . '[event_cpt]" value="' . esc_attr($value) . '">';
  }
}
