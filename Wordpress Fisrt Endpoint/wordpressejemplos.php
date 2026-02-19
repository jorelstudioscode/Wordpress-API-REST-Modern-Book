<?php
/**
 * Plugin Name:       Wordpress Fisrt Endpoint
 * Plugin URI:        https://tudominio.com/nombre-del-plugin
 * Description:       Example Fisrt Endpoint
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Author:            Your Name
 * Author URI:        https://tudominio.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace WordpressFirstEndpoint;

function custom_greetings(): array {
  return ['greeting' => 'hi'];
}

add_action(hook_name: 'rest_api_init',callback: function (): void {
  register_rest_route(route_namespace: 'custom/v1', route: '/greetings', args: [
    'methods' => 'GET',
    'callback' => __NAMESPACE__ . '\\custom_greetings',
    'permission_callback' => '__return_true'
  ] );
} );
