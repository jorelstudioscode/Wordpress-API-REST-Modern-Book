<?php
/**
 * Plugin Name:       Wordpress Custom Settings
 * Plugin URI:        https://tudominio.com/nombre-del-plugin
 * Description:       Custom settings for WordPress. 
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Author:            Your Name
 * Author URI:        https://tudominio.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types = 1 );

namespace WordpressCustomSettings;
use \WP_REST_Server;
use \WP_REST_Request;
use \WP_REST_Response;
use \AllowDynamicProperties;


#[AllowDynamicProperties]
final class CustomHomeSettings {
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?int $layout = null,
        public ?string $color = null,
        public ?bool $show_featured = null,
        public ?array $sections = null
    ) {}
}

final readonly class Constants {
    public const string OPTION_NAME = 'custom_home_settings';
}

function handle_create_settings(WP_REST_Request $request): WP_REST_Response {
    $settings = new CustomHomeSettings(
        ...array_intersect_key(
            $request->get_params(), 
            $request->get_attributes()['args']
        )
    );
    update_option(option: Constants::OPTION_NAME, value: $settings );
    return rest_ensure_response(response: $settings);
}

function handle_get_settings(): WP_REST_Response {
    $settings = get_option(option: Constants::OPTION_NAME) ?: new CustomHomeSettings;
    return rest_ensure_response(response: $settings);
}

function register_endpoints(): void {
    register_rest_route(
        route_namespace: 'custom/v1',
        route: '/settings',
        args: [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => __NAMESPACE__ . '\\handle_create_settings',
            'permission_callback' => '__return_true',
            'args' => [
                'title' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'description' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'layout' => [
                    'required' => false,
                    'type' => 'integer',
                ],
                'color' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'show_featured' => [
                    'required' => false,
                    'type' => 'boolean',
                ],
                'sections' => [
                    'required' => false,
                    'type' => 'array',
                ],
            ]
        ]
    );

    register_rest_route(
        route_namespace: 'custom/v1',
        route: '/settings',
        args: [
            'methods' => WP_REST_Server::READABLE,
            'callback' => __NAMESPACE__ . '\\handle_get_settings',
            'permission_callback' => '__return_true',
        ]
    );
}

add_action(hook_name: 'rest_api_init', callback: __NAMESPACE__ . '\\register_endpoints' );