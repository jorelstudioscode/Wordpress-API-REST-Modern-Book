<?php
/**
 * Plugin Name:       Wordpress Posts Vistis
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
use \WP_Post;
use \WP_Error;

readonly final class PostVisit {
    public function __construct(
        public int $id,
        public string $title,
        public string $thumbnail,
        public string $url,
        public int $visits,
    ) {}
}

function register_visits_post_meta(): void {
    register_post_meta(post_type: 'post', meta_key: 'visits', args: [
        'type'         => 'integer',
        'single'       => true,
        'default'      => 0,
        'show_in_rest' => true,
        'sanitize_callback' => 'absint',
    ]);
}


function increase_post_visits( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $post_id =  $request->get_param( 'id' );

    if ( ! get_post_type( $post_id ) ) {
        return new WP_Error(code: 'no_post', message: 'ID invalido', data: [ 'status' => 404 ] );
    }

    $meta_key = 'visits';

    $current_visits = (int) get_post_meta( post_id: $post_id, key: $meta_key, single: true );
    $visits = $current_visits + 1;

    update_post_meta( post_id: $post_id, meta_key: $meta_key , meta_value: $visits );

    return rest_ensure_response(response: ['post_id' => $post_id, 'visits' => $visits ]);
}

function get_post_most_visits() {
    $posts = get_posts([
        'post_type'      => 'post',
        'numberposts'    => 10,
        'meta_key'       => 'visits',
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
    ]);

    $visits = array_map(callback: fn(WP_Post $post) => new PostVisit(
        id: $post->ID,
        title: $post->post_title,
        thumbnail: get_the_post_thumbnail_url( $post, 'thumbnail' ) ?: '',
        url: get_permalink( $post ),
        visits: (int) get_post_meta( post_id: $post->ID, key: 'visits', single: true ),
    ), array: $posts );

    return rest_ensure_response(response: $visits);
}

function register_endpoints_visits(): void {
    register_rest_route(route_namespace: 'custom/v1',route: '/visits/(?P<id>\d+)', args: [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => __NAMESPACE__ . '\\increase_post_visits',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'required'          => true,
                'validate_callback' => fn(string $param) => is_numeric( $param ),
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    register_rest_route(route_namespace: 'custom/v1',route: '/visits/', args: [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => __NAMESPACE__ . '\\get_post_most_visits',
        'permission_callback' => '__return_true'
    ]);
}



add_action(hook_name: 'init', callback: __NAMESPACE__ . '\\register_visits_post_meta' );
add_action(hook_name: 'rest_api_init',  callback: __NAMESPACE__ . '\\register_endpoints_visits' );