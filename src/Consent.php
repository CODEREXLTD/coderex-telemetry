<?php
namespace LinnoSDK\Telemetry;

class Consent {
    /**
     * Client instance
     *
     * @var Client
     */
    private Client $client;

    /**
     * Text domain for i18n
     *
     * @var string
     */
    private string $textDomain;

    /**
     * Constructor
     *
     * @param Client $client
     */
    public function __construct( Client $client ) {
        $this->client = $client;
        $this->textDomain = $client->get_text_domain();
    }

    /**
     * Initialize the consent functionality
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_optin_optout' ) );
        add_action( 'admin_notices', array( $this, 'show_consent_notice' ) );
    }

    /**
     * Handle the user's opt-in/opt-out choice
     *
     * @return void
     */
    public function handle_optin_optout(): void {
        if ( ! isset( $_GET['action'] ) || ! isset( $_GET['plugin'] ) ) {
            return;
        }

        if ( $_GET['plugin'] !== $this->client->get_slug() ) {
            return;
        }

        if ( $_GET['action'] === 'optin' ) {
            $this->client->set_optin_state( 'yes' );
            $this->dismiss_notice();

            // Explicitly call activate() to ensure table is created and flags are set.
            // Since we just set optin to 'yes', activate() will now track the 'plugin_activated' event.
            $this->client->activate();
        }

        if ( $_GET['action'] === 'optout' ) {
            $this->client->set_optin_state( 'no' );
            $this->dismiss_notice();
        }

        wp_safe_redirect( remove_query_arg( array( 'action', 'plugin' ) ) );
        exit;
    }

    /**
     * Show the consent notice
     *
     * @return void
     */
    public function show_consent_notice(): void {
        if ( $this->is_notice_dismissed() ) {
            return;
        }

        // Don't show the notice if the user has already opted in or out
        if ( null !== $this->client->get_optin_state() ) {
            return;
        }
        
        $plugin_name = $this->client->get_plugin_name();

        $optin_url = add_query_arg( array(
            'action' => 'optin',
            'plugin' => $this->client->get_slug(),
        ) );

        $optout_url = add_query_arg( array(
            'action' => 'optout',
            'plugin' => $this->client->get_slug(),
        ) );

        ?>
        <div class="notice notice-info">
            <p>
                <?php
                $privacy_policy_url = apply_filters(
                    $this->client->get_slug() . '_telemetry_privacy_policy_url',
                    $this->client->get_privacy_url()
                );
                $consent_service_name = apply_filters(
                    $this->client->get_slug() . '_telemetry_consent_service_name',
                    $this->client->get_consent_service_name()
                );
                $message = apply_filters(
                    $this->client->get_slug() . '_telemetry_consent_message',
                    sprintf(
                        __( 'Help us improve %1$s. With your permission, this plugin sends usage and technical data to %3$s, including: site URL, plugin name and version, event timestamps, anonymous site profile ID, custom event properties, and your current admin profile details (email, first name, last name, and avatar) for identification and product support. Tracking is off by default. <a href="%2$s" target="_blank" rel="noopener noreferrer">Learn more</a>.', $this->textDomain ),
                        '<strong>' . $plugin_name . '</strong>',
                        esc_url( $privacy_policy_url ),
                        esc_html( $consent_service_name )
                    )
                );
                
                echo '<p>' . $message . '</p>';
                ?>
            <p>
                <a href="<?php echo esc_url( $optin_url ); ?>" class="button-primary"><?php _e( 'Allow', $this->textDomain ); ?></a>
                <a href="<?php echo esc_url( $optout_url ); ?>" class="button-secondary"><?php _e( 'Do not allow', $this->textDomain ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Check if the notice has been dismissed
     *
     * @return bool
     */
    private function is_notice_dismissed(): bool {
        return 'yes' === get_option( $this->get_notice_dismissed_key() );
    }

    /**
     * Dismiss the notice
     *
     * @return void
     */
    private function dismiss_notice(): void {
        update_option( $this->get_notice_dismissed_key(), 'yes' );
    }

    /**
     * Get the option key for dismissing the notice
     *
     * @return string
     */
    private function get_notice_dismissed_key(): string {
        return $this->client->get_notice_dismissed_key();
    }
}
