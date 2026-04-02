<?php
namespace LinnoSDK\Telemetry;

class Queue {
    /**
     * The name of the custom table
     *
     * @var string
     */
    private string $table_name;

    /**
     * Constructor
     */
    public function __construct($wpdb = null)
    {
        if ( ! $wpdb ) {
            global $wpdb;
        }
        $prefix           = ( $wpdb && isset( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
        $this->table_name = $prefix . 'linno_telemetry_queue';
    }

    /**
     * Check whether the queue table exists.
     *
     * @return bool
     */
    public function table_exists(): bool {
        global $wpdb;

        $table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) );

        return $table === $this->table_name;
    }

    /**
     * Create the custom table
     *
     * @return void
     */
    public function create_table(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            plugin_slug varchar(255) NOT NULL,
            event varchar(255) NOT NULL,
            properties longtext NOT NULL,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            INDEX plugin_slug_index (plugin_slug)
        ) $charset_collate;";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        }
        dbDelta( $sql );
    }

    /**
     * Add an event to the queue
     *
     * @param string $event
     * @param array $properties
     * @return void
     */
    public function add( string $plugin_slug, string $event, array $properties ): void {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            $this->create_table();
        }

        $wpdb->insert(
            $this->table_name,
            [
                'plugin_slug' => $plugin_slug,
                'event'      => $event,
                'properties' => wp_json_encode( $properties ),
                'timestamp'  => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Get all events from the queue
     *
     * @return array
     */
    public function get_all( string $plugin_slug ): array {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return [];
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE plugin_slug = %s ORDER BY timestamp ASC",
                $plugin_slug
            )
        );

        return $results;
    }

    /**
     * Delete events from the queue
     *
     * @param array $ids
     * @return void
     */
    public function delete( array $ids ): void {
        global $wpdb;

        if ( ! $this->table_exists() || empty( $ids ) ) {
            return;
        }

        $ids = implode( ',', array_map( 'absint', $ids ) );

        $wpdb->query( "DELETE FROM {$this->table_name} WHERE id IN ($ids)" );
    }

    /**
     * Clear all events for a specific plugin from the queue.
     *
     * @param string $plugin_slug The slug of the plugin whose events should be cleared.
     * @return void
     */
    public function clear_for_plugin( string $plugin_slug ): void {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE plugin_slug = %s",
                $plugin_slug
            )
        );
    }
}
