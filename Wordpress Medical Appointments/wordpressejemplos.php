<?php
/**
 * Plugin Name:       Wordpress Medical Appointments
 * Plugin URI:        https://tudominio.com/nombre-del-plugin
 * Description:       Medical appointment booking for WordPress. 
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.4
 * Author:            Your Name
 * Author URI:        https://tudominio.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types = 1 );

namespace WordpressMedicalAppointments;
use \WP_REST_Server;
use \WP_REST_Request;
use \WP_REST_Response;
use \WP_Post;
use \WP_Error;
use \DateTime;
use \DateTimeImmutable;

final class Constants {
    public const string TABLE_NAME = 'medical_appointments_info';
}

enum TypeEnum : string {
    case VISIT = 'visit';
    case CONSULTATION = 'consultation';
    case FOLLOW_UP = 'follow';
    case CHECKUP = 'checkup';
}

enum StatusEnum : string {
    case RESERVED = 'reserved';
    case AVAILABLE = 'available';
}

final readonly class Appointment {
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $phone,
        public string $reason,
        public string $scheduled,
        public ?TypeEnum $type = null
    ) {}
}

final readonly class AppointmentHour {
    public function __construct(
        public string $hour,
        public string $status
    ) {}
}

final readonly class AppointmentDay {
    public function __construct(
        public string $date,
        public array $hours
    ) {}
}

function install_table(): void {
    global $wpdb;
    $table = $wpdb->prefix . Constants::TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = <<<SQL
     CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(100) NOT NULL,
            reason text NOT NULL,
            scheduled varchar(100) NOT NULL,
            type varchar(100),
            PRIMARY KEY  (id)
        ) $charset_collate;
    SQL;

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta(queries: $sql );
}

function insert_appointment(WP_REST_Request $request): array|WP_Error {
    global $wpdb;
    $table = $wpdb->prefix . Constants::TABLE_NAME;

    $data = [
        'name' => $request->get_param(key: 'name'),
        'email' => $request->get_param(key: 'email'),
        'phone' => $request->get_param(key: 'phone'),
        'reason' => $request->get_param(key: 'reason'),
        'scheduled' => $request->get_param(key: 'scheduled'),
        'type' => $request->get_param(key: 'type')
    ];

    $inserted = $wpdb->insert(
        table: $table,
        data: $data,
        format: ['%s', '%s', '%s', '%s', '%s', '%s' ] 
    );

    if ( ! $inserted ) {
        return new WP_Error( 
            code: 'db_error', 
            message: 'Error in insert.', 
            data: [ 'status' => 500 ] 
        );
    }

    return [ 'id' => $wpdb->insert_id, ...$data ];
}


function is_scheduled_reserved( string $start ): bool {
    global $wpdb;
    $table = $wpdb->prefix . Constants::TABLE_NAME;
    
    return (bool) $wpdb->get_var(query: $wpdb->prepare(
        <<<SQL
            SELECT 1 FROM $table
            WHERE scheduled < DATE_ADD(%s, INTERVAL 1 HOUR) 
            AND DATE_ADD(scheduled, INTERVAL 1 HOUR) > %s 
            LIMIT 1 
         SQL,
        $start, $start 
    ));
}

function handle_create_appointment(WP_REST_Request $request) {

    $reserved = is_scheduled_reserved(start: $request->get_param('scheduled') );

    if ( $reserved ) {
        return new WP_Error( 
            code: 'rest_scheduled_reserved', 
            message: 'The selected time is already reserved.', 
            data: [ 'status' => 400 ] 
        );
    }

    $data = insert_appointment(request: $request );

    return rest_ensure_response(response: $data );
}

function validate_datetime_schema( string $raw_date ): bool|WP_Error {
    $date = DateTimeImmutable::createFromFormat(format: 'Y-m-d H:i:s', datetime: $raw_date);
    
    if ($date && $date->format(format: 'Y-m-d H:i:s') === $raw_date) {
        if ($date < new DateTimeImmutable(datetime: 'now')) {
            return new WP_Error(
                code: 'rest_invalid_date', 
                message: 'Scheduled in past', 
                data: ['status' => 400]
            );
        }
        return true;
    }
    
    return false;
}

function validate_visit_type( ?string $type ): bool|WP_Error {
    return ( $type === null || TypeEnum::tryFrom(value: $type ) ) 
        ? true 
        : new WP_Error(code: 'invalid_enum', message: "The value '$type' is invalid." );
}

function query_appointments(int $page, int $limit): array {
    global $wpdb;
    $table = $wpdb->prefix . Constants::TABLE_NAME;

    $offset = ($page - 1) * $limit;

    $results = $wpdb->get_results(
        query: $wpdb->prepare(
            <<<SQL
                SELECT id, name, email, phone, reason, scheduled, type
                FROM $table
                ORDER BY scheduled ASC
                LIMIT %d OFFSET %d
            SQL,
            $limit,
            $offset
        ),
        output: ARRAY_A
    );

    return array_map(
        callback: fn(array $item) => new Appointment(
            ...[
                'id' => (int)$item['id'],
                'type' => TypeEnum::tryFrom(value: $item['type'] ?? '' )
            ] + $item),
        array: $results    
    );
}

function handle_list_appointments(WP_REST_Request $request) {

    $page = $request->get_param(key: 'page') ?? 1;
    $limit = $request->get_param(key: 'limit') ?? 10;

    $results = query_appointments(page: $page, limit: $limit );
    return rest_ensure_response(response: $results );
}

function handle_calendar_appointments(WP_REST_Request $request) {
    global $wpdb;

    $month = $request->get_param(key: 'month') ?? (int) date(format: 'n');
    $year = $request->get_param(key: 'year') ?? (int) date(format:'Y');

    $date = new DateTime(datetime: "$year-$month-01");
    $days = (int) $date->format(format: 't');

    $table = $wpdb->prefix . Constants::TABLE_NAME;

    $results = $wpdb->get_results(
        query: $wpdb->prepare(
            "SELECT scheduled FROM $table WHERE scheduled >= %s AND scheduled <= %s",
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $days)
        ),
        output: OBJECT
    );

    $reserved = [];
    array_walk(array: $results, callback: function(object $row) use (&$reserved) {
        $row_date = date_create(datetime: $row->scheduled);
        if ($row_date) {
            $row_day = (int)$row_date->format(format: 'j');
            $row_hour = (int)$row_date->format(format: 'G');
            $reserved[$row_day][$row_hour] = true;
        }
    });

    $calendar = array_map(callback: function(string $day) use ($year, $month, $reserved) {

        $hours = array_map(callback: fn(string $hour) => new AppointmentHour(
            hour: sprintf('%02d:00:00', $hour), 
            status: !empty($reserved[$day][$hour]) ? 
                StatusEnum::RESERVED->value 
                : StatusEnum::AVAILABLE->value
        ), array: range(start: 0, end: 23));

        return new AppointmentDay(
            date: sprintf('%04d-%02d-%02d', $year, $month, $day),
            hours: $hours
        );
    }, array: range(start: 1, end: $days));

    return rest_ensure_response(response: $calendar );
}

function register_endpoints(): void {
    register_rest_route(
        route_namespace: 'medical/v1',
        route: '/appointments',
        args: [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => __NAMESPACE__ . '\\handle_create_appointment',
            'permission_callback' => '__return_true',
            'args' => [
                'name' => array(
                    'type' => 'string', 
                    'required' => true, 
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'email' => array(
                    'type' => 'string', 
                    'required' => true, 
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => 'is_email'
                ),
                'phone' => array(
                    'type' => 'string', 
                    'required' => false, 
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'reason' => [
                    'type'  => 'string',
                    'required'  => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'scheduled' => [
                    'type' => 'string',
                    'required' => true,
                    'validate_callback' => __NAMESPACE__ . '\\validate_datetime_schema',
                ],
                'type' => [
                    'type' => 'string',
                    'required' => true,
                    'validate_callback' => __NAMESPACE__ . '\\validate_visit_type',
                ]
            ]
        ]
    );


    register_rest_route(
        route_namespace: 'medical/v1',
        route: '/appointments',
        args: [
            'methods' => WP_REST_Server::READABLE,
            'callback' => __NAMESPACE__ . '\\handle_list_appointments',
            'permission_callback' => '__return_true',
            'args' => [
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validate_request_arg',
                    'minimum' => 1
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validate_request_arg',
                    'minimum' => 1,
                    'maximum' => 100
                ]
            ],
        ]
    );


    register_rest_route(
        route_namespace: 'medical/v1',
        route: '/calendar',
        args: [
            'methods' => WP_REST_Server::READABLE,
            'callback' => __NAMESPACE__ . '\\handle_calendar_appointments',
            'permission_callback' => '__return_true',
            'args' => [
                'month' => [
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validate_request_arg',
                    'minimum' => 1,
                    'maximum' => 12
                ],
                'year' => [
                    'type' => 'integer',
                    'default' => (int) date('Y'),
                    'sanitize_callback' => 'absint',
                    'validate_callback' => 'rest_validate_request_arg',
                    'minimum' => 1,
                    'maximum' => (int) date('Y')
                ]
            ],
        ]
    );
}

register_activation_hook(file: __FILE__,callback: __NAMESPACE__ . '\\install_table' );
add_action(hook_name: 'rest_api_init', callback: __NAMESPACE__ . '\\register_endpoints' );