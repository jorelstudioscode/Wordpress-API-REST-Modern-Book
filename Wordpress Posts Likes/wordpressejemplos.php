<?php
/**
 * Plugin Name:       Wordpress Posts Likes
 * Plugin URI:        https://tudominio.com/nombre-del-plugin
 * Description:       Add likes to posts
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Author:            Your Name
 * Author URI:        https://tudominio.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types = 1 );

namespace WordpressPostsLikes;
use \WP_REST_Server;
use \WP_REST_Request;
use \WP_REST_Response;
use \WP_Error;

function register_likes_post_meta(): void {
    register_post_meta(post_type: 'post', meta_key: 'likes', args: [
        'type'         => 'integer',
        'single'       => true,
        'default'      => 0,
        'show_in_rest' => true,
        'sanitize_callback' => 'absint',
    ]);
}


function increase_post_likes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $post_id =  $request->get_param( 'id' );

    if ( ! get_post_type( $post_id ) ) {
        return new WP_Error(code: 'no_post', message: 'ID invalido', data: [ 'status' => 404 ] );
    }

    $meta_key = 'likes';

    $current_likes = (int) get_post_meta( post_id: $post_id, key: $meta_key, single: true );
    $likes = $current_likes + 1;

    update_post_meta( post_id: $post_id, meta_key: $meta_key , meta_value: $likes );

    return rest_ensure_response(response: ['post_id' => $post_id, 'likes' => $likes ]);
}

function register_endpoints_likes(): void {
    register_rest_route(route_namespace: 'custom/v1',route: '/likes/(?P<id>\d+)', args: [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => __NAMESPACE__ . '\\increase_post_likes',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'required'          => true,
                'validate_callback' => fn(string $param) => is_numeric( $param ),
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
}



add_action(hook_name: 'init', callback: __NAMESPACE__ . '\\register_likes_post_meta' );
add_action(hook_name: 'rest_api_init',  callback: __NAMESPACE__ . '\\register_endpoints_likes' );