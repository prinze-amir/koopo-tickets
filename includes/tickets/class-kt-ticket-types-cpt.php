<?php

namespace Koopo_Tickets;

defined('ABSPATH') || exit;

class Ticket_Types_CPT {
  const POST_TYPE = 'koopo_ticket_type';

  public static function init() {
    add_action('init', [__CLASS__, 'register_cpt']);
  }

  public static function register_cpt() {
    register_post_type(self::POST_TYPE, [
      'label' => 'Ticket Types',
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'show_in_rest' => false,
      'supports' => ['title', 'author'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
      'exclude_from_search' => true,
    ]);
  }
}
