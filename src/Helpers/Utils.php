<?php
/**
 * Utils Helper Class
 *
 * Provides utility functions for gathering system information,
 * sanitizing data, and other helper operations.
 *
 * @package LinnoSDK\Telemetry
 * @since 1.0.0
 */

namespace LinnoSDK\Telemetry\Helpers;

/**
 * Utils class
 *
 * @since 1.0.0
 */
class Utils {
    /**
     * Get PHP version
     *
     * @return string PHP version string
     * @since 1.0.0
     */
    public static function getPhpVersion(): string {
        return PHP_VERSION;
    }

    /**
     * Get WordPress version
     *
     * @return string WordPress version string
     * @since 1.0.0
     */
    public static function getWordPressVersion(): string {
        global $wp_version;
        return $wp_version ?? '';
    }

    /**
     * Get MySQL version
     *
     * @return string MySQL/MariaDB version string
     * @since 1.0.0
     */
    public static function getMySqlVersion(): string {
        global $wpdb;
        
        if ( ! isset( $wpdb ) ) {
            return '';
        }
        
        return $wpdb->db_version();
    }

    /**
     * Get server software information
     *
     * @return string Server software string
     * @since 1.0.0
     */
    public static function getServerSoftware(): string {
        return $_SERVER['SERVER_SOFTWARE'] ?? '';
    }

    /**
     * Get site URL
     *
     * @return string Site URL
     * @since 1.0.0
     */
    public static function getSiteUrl(): string {
        return get_site_url();
    }

    /**
     * Get current timestamp in ISO 8601 format
     *
     * @return string ISO 8601 formatted timestamp
     * @since 1.0.0
     */
    public static function getCurrentTimestamp(): string {
        return gmdate( 'c' );
    }

    /**
     * Sanitize event name to allow only alphanumeric characters and underscores
     *
     * @param string $event Event name to sanitize
     *
     * @return string Sanitized event name
     * @since 1.0.0
     */
    public static function sanitizeEventName( string $event ): string {
        return preg_replace( '/[^a-zA-Z0-9_\/]/', '', $event );
    }

    /**
     * Sanitize properties array recursively
     *
     * Preserves special OpenPanel properties like __identify that start with double underscore.
     * Also preserves the case of keys inside the __identify block as OpenPanel is case-sensitive
     * for those specific identification keys (profileId, firstName, etc).
     *
     * @param array $properties Properties array to sanitize
     * @param bool  $is_identify Whether we are currently sanitizing the __identify block
     *
     * @return array Sanitized properties array
     * @since 1.0.0
     */
    public static function sanitizeProperties( array $properties, bool $is_identify = false ): array {
        $sanitized = array();
        
        foreach ( $properties as $key => $value ) {
            // Preserve special OpenPanel properties (starting with __)
            // or if we are already inside the __identify block (to maintain camelCase)
            if ( $is_identify || strpos( $key, '__' ) === 0 ) {
                $sanitized_key = $key;
            } else {
                $sanitized_key = sanitize_key( $key );
            }
            
            if ( is_array( $value ) ) {
                $sanitized[ $sanitized_key ] = self::sanitizeProperties( $value, $is_identify || $key === '__identify' );
            } elseif ( is_string( $value ) ) {
                $sanitized[ $sanitized_key ] = sanitize_text_field( $value );
            } elseif ( is_numeric( $value ) ) {
                $sanitized[ $sanitized_key ] = $value;
            } elseif ( is_bool( $value ) ) {
                $sanitized[ $sanitized_key ] = $value;
            } else {
                $sanitized[ $sanitized_key ] = sanitize_text_field( (string) $value );
            }
        }
        
        return $sanitized;
    }

    /**
     * Generate a UUID v4 for profile identification
     *
     * Uses WordPress wp_generate_uuid4() if available, otherwise generates manually.
     *
     * @return string UUID v4 string
     * @since 1.0.0
     */
    public static function generateProfileId(): string {
        // Use WordPress function if available
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }
        
        // Fallback: Generate UUID v4 manually
        $data = random_bytes( 16 );
        
        // Set version to 0100
        $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
        // Set bits 6-7 to 10
        $data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
        
        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
    }

    /**
     * Get or create a site-level profile ID
     *
     * This creates a consistent profile ID for the WordPress installation,
     * stored in the options table. This allows tracking the site as a profile
     * without requiring user-specific identification.
     *
     * @return string Site profile ID (UUID v4)
     * @since 1.0.0
     */
    public static function getSiteProfileId(): string {
        $option_name = 'linno_telemetry_site_profile_id';
        $profile_id = get_option( $option_name );
        
        if ( empty( $profile_id ) ) {
            $profile_id = self::generateProfileId();
            update_option( $option_name, $profile_id, false );
        }
        
        return $profile_id;
    }

    /**
     * Extract plugin version from plugin file headers
     *
     * @param string $plugin_file Path to the plugin file
     *
     * @return string Plugin version or '0.0.0' if not found
     * @since 1.0.0
     */
    public static function getPluginVersion( string $plugin_file ): string {
        if ( ! file_exists( $plugin_file ) ) {
            return '0.0.0';
        }
        
        // Use WordPress function if available
        if ( function_exists( 'get_plugin_data' ) ) {
            $plugin_data = get_plugin_data( $plugin_file, false, false );
            return $plugin_data['Version'] ?? '0.0.0';
        }
        
        // Fallback: Read file and extract version manually
        $file_content = file_get_contents( $plugin_file );
        
        if ( $file_content === false ) {
            return '0.0.0';
        }
        
        // Look for Version: x.x.x in the plugin header
        if ( preg_match( '/Version:\s*([0-9.]+)/i', $file_content, $matches ) ) {
            return $matches[1];
        }
        
        return '0.0.0';
    }

    /**
     * Get the current user's identification data for telemetry.
     *
     * @return array Identification data with profileId and user info.
     * @since 1.0.1
     */
    public static function get_current_user_identify(): array {
        $identify = [
            'profileId' => self::getSiteProfileId(),
        ];

        if ( function_exists( 'wp_get_current_user' ) ) {
            $current_user = wp_get_current_user();
            if ( $current_user && $current_user->ID > 0 ) {
                $identify['email']     = $current_user->user_email;
                $identify['firstName'] = $current_user->first_name ?: 'User';
                $identify['lastName']  = $current_user->last_name ?: '';
                if ( function_exists( 'get_avatar_url' ) ) {
                    $identify['avatar'] = get_avatar_url( $current_user->ID );
                }
            }
        }

        return $identify;
    }

    /**
     * Get unique site ID
     *
     * @return string Unique site ID
     * @since 1.0.0
     */
    public static function getUniqueSiteId(): string
    {
        return md5( home_url() );
    }
}
