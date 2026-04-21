<?php
/**
 * ReviewPrompt Class
 *
 * Renders a centered NPS modal in the WordPress admin. Fully self-contained:
 * CSS and JS are output inline so no external asset URLs are required.
 *
 * Step 1  — NPS scale (0–10). "Not likely" … "Very likely".
 * Step 2a — Score 0–6 (detractor): feedback textarea, required, min chars.
 * Step 2b — Score 7–10 (promoter): opens review URL and closes.
 *
 * Config options
 * --------------
 * webhook         (string)   Webhook URL that receives feedback payloads.
 * min_feedback_length  (int)      Minimum textarea chars before submit (default 50).
 * days_after_install   (int)      Days after install before showing (default 3).
 * snooze_days          (int)      Days between re-shows after snooze (default 30).
 * nps_question         (string)   NPS question text.
 * low_score_threshold  (int)      Scores BELOW this value show the feedback form (default 7).
 * review_url           (string)   URL opened for high scores.
 * support_url          (string)   "Contact support" link shown on the feedback form.
 * privacy_url          (string)   Privacy policy link in the form footer.
 * installed_option_key (string)   WP option key holding the install timestamp.
 * condition_callback   (callable) Optional. Return true to show, false to hide.
 * allowed_screens      (string[]) Admin page slugs / screen IDs. Empty = any admin screen.
 *
 * @package LinnoSDK\Telemetry
 * @since   1.1.0
 */

namespace LinnoSDK\Telemetry;

class ReviewPrompt {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const OPTION_STATUS       = 'linno_review_status_';
    private const OPTION_SNOOZE       = 'linno_review_snooze_';
    private const OPTION_SNOOZE_COUNT = 'linno_review_snooze_count_';
    private const OPTION_TRIGGER      = 'linno_review_trigger_';

    /**
     * Tracks whether the initial snooze seed has been written for this plugin.
     * Prevents the prompt from firing immediately on existing installations
     * when event-triggered mode is activated for the first time.
     */
    private const OPTION_BOOTSTRAPPED = 'linno_review_bootstrapped_';

    private const DEFAULTS = [
        'webhook'         => '',
        'min_feedback_length'  => 50,
        'days_after_install'   => 3,
        'snooze_days'          => 30,
        'snooze_schedule'      => [],  // Progressive schedule e.g. [7, 30, 90]. When non-empty, enables event-triggered mode.
        'nps_question'         => '',
        'low_score_threshold'  => 7,   // 0–6 = detractors, 7–10 = promoters
        'position'             => 'middle', // middle | bottom-right | bottom-left | top-right | top-left
        'review_url'           => '',
        'support_url'          => '',
        'privacy_url'          => 'https://rextheme.com/privacy-policy/',
        'installed_option_key' => '',
        'condition_callback'   => null,
        'allowed_screens'      => [],
    ];

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    private Client $client;
    private string $slug;
    private array  $config;

    /** @var bool|null Cached decision for the current request. */
    private ?bool $should_show_cache = null;

    /**
     * Trigger context resolved during should_show() for use during render.
     *
     * @var array|null
     */
    private ?array $current_trigger = null;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function __construct( Client $client, array $config = [] ) {
        $this->client = $client;
        $this->slug   = $client->get_slug();
        $this->config = array_merge( self::DEFAULTS, $config );

        // Fill dynamic defaults that depend on slug / plugin name.
        if ( empty( $this->config['nps_question'] ) ) {
            $this->config['nps_question'] = sprintf(
                'How likely are you to recommend %s to your friends or colleagues?',
                $client->get_plugin_name()
            );
        }
        if ( empty( $this->config['review_url'] ) ) {
            $this->config['review_url'] = 'https://wordpress.org/support/plugin/' . $this->slug . '/reviews/#new-post';
        }
        if ( empty( $this->config['installed_option_key'] ) ) {
            $this->config['installed_option_key'] = $this->slug . '_installed_time';
        }
    }

    /**
     * Register WordPress hooks.
     */
    public function init(): void {
        $this->maybe_bootstrap_snooze();
        add_action( 'admin_enqueue_scripts', [ $this, 'maybe_output_style' ] );
        add_action( 'admin_footer',          [ $this, 'render_prompt' ] );
        add_action( 'wp_ajax_' . $this->get_ajax_action(), [ $this, 'handle_ajax' ] );
    }

    // -------------------------------------------------------------------------
    // Identifier helpers
    // -------------------------------------------------------------------------

    private function get_status_option(): string {
        return self::OPTION_STATUS . $this->slug;
    }

    private function get_snooze_option(): string {
        return self::OPTION_SNOOZE . $this->slug;
    }

    private function get_ajax_action(): string {
        return $this->slug . '_review_action';
    }

    private function get_nonce_action(): string {
        return $this->slug . '_review_nonce';
    }

    /** JS global variable name (hyphens are not valid in JS identifiers). */
    private function get_js_global(): string {
        return 'linnoReview_' . str_replace( '-', '_', $this->slug );
    }

    private function get_snooze_count_option(): string {
        return self::OPTION_SNOOZE_COUNT . $this->slug;
    }

    private function get_trigger_option(): string {
        return self::OPTION_TRIGGER . $this->slug;
    }

    private function get_bootstrapped_option(): string {
        return self::OPTION_BOOTSTRAPPED . $this->slug;
    }

    // -------------------------------------------------------------------------
    // First-run bootstrap
    // -------------------------------------------------------------------------

    /**
     * Seed an initial snooze for existing installations when event-triggered
     * mode is activated for the first time.
     *
     * Without this, an old plugin install that already satisfies a trigger
     * condition (e.g. 7+ days old with no funnels) would show the prompt
     * immediately on the very first page load after the code update.
     *
     * Logic:
     *   - Runs only when snooze_schedule is set (event-triggered mode).
     *   - Runs only once per site (guarded by OPTION_BOOTSTRAPPED).
     *   - If the plugin was installed more than one day ago, seeds
     *     the snooze timestamp to "now" so the first interval starts
     *     from today rather than from the past install date.
     *   - New installs (≤ 1 day old) are unaffected — the schedule
     *     runs naturally from first trigger.
     *
     * @return void
     */
    private function maybe_bootstrap_snooze(): void {
        if ( empty( $this->config['snooze_schedule'] ) ) {
            return;
        }

        if ( get_option( $this->get_bootstrapped_option() ) ) {
            return;
        }

        // Mark as bootstrapped immediately to avoid running again.
        update_option( $this->get_bootstrapped_option(), '1' );

        $installed_time = (int) get_option( $this->config['installed_option_key'], 0 );
        if ( ! $installed_time ) {
            return;
        }

        $age_days = ( time() - $installed_time ) / DAY_IN_SECONDS;

        // Only seed for existing installs — new installs (≤ 1 day) start clean.
        if ( $age_days > 1.0 ) {
            update_option( $this->get_snooze_option(), time() );
        }
    }

    // -------------------------------------------------------------------------
    // Event-triggered prompt API
    // -------------------------------------------------------------------------

    /**
     * Store a pending trigger that will cause the prompt to appear on the next
     * eligible admin page load.
     *
     * Call this method from any WordPress hook that represents a meaningful user
     * success or failure event (e.g. order placed, feature used, no funnel created).
     *
     * The $context array may contain prompt copy overrides:
     *   - modal_title    (string) Modal header title.
     *   - nps_question   (string) The NPS question shown to the user.
     *   - feedback_msg   (string) Message above the detractor feedback textarea.
     *   - thank_you_title (string)
     *   - thank_you_text  (string)
     *
     * @param string $event_key  Short slug identifying the trigger type (e.g. 'funnel_order').
     * @param array  $context    Optional metadata and copy-override keys.
     * @return void
     */
    public function trigger_prompt( string $event_key, array $context = [] ): void {
        if ( 'completed' === get_option( $this->get_status_option() ) ) {
            return;
        }

        update_option(
            $this->get_trigger_option(),
            wp_json_encode( [
                'event_key'    => sanitize_key( $event_key ),
                'context'      => $context,
                'triggered_at' => time(),
            ] ),
            false // Do not autoload — only needed on admin pages.
        );
    }

    // -------------------------------------------------------------------------
    // Progressive snooze helper
    // -------------------------------------------------------------------------

    /**
     * Return the effective snooze duration in days for the given dismiss count.
     *
     * When a snooze_schedule array is configured (e.g. [7, 30, 90]) the Nth
     * dismissal uses schedule[N-1]; once the last value is reached it repeats.
     * Falls back to the legacy scalar snooze_days when no schedule is set.
     *
     * @param int $snooze_count Total number of times the user has dismissed.
     * @return int Days to snooze.
     */
    private function compute_effective_snooze_days( int $snooze_count ): int {
        $schedule = (array) $this->config['snooze_schedule'];
        if ( empty( $schedule ) ) {
            return (int) $this->config['snooze_days'];
        }
        $index = max( 0, $snooze_count - 1 );
        $index = min( $index, count( $schedule ) - 1 );
        return (int) $schedule[ $index ];
    }

    // -------------------------------------------------------------------------
    // Visibility logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether the prompt should be rendered on this page load.
     */
    public function should_show(): bool {
        // Developer test-mode bypass: ?{slug}_test_review=1
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET[ $this->slug . '_test_review' ] ) && '1' === $_GET[ $this->slug . '_test_review' ] ) {
            return $this->set_cache( true );
        }

        if ( null !== $this->should_show_cache ) {
            return $this->should_show_cache;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return $this->set_cache( false );
        }

        if ( ! $this->is_allowed_screen() ) {
            return $this->set_cache( false );
        }

        if ( 'completed' === get_option( $this->get_status_option() ) ) {
            return $this->set_cache( false );
        }

        // -----------------------------------------------------------------
        // Event-triggered mode: snooze_schedule is set.
        // The prompt only shows when trigger_prompt() has stored a pending
        // trigger and the progressive snooze window has expired.
        // -----------------------------------------------------------------
        if ( ! empty( $this->config['snooze_schedule'] ) ) {
            return $this->set_cache( $this->should_show_event_triggered() );
        }

        // -----------------------------------------------------------------
        // Default page-load mode (legacy).
        // -----------------------------------------------------------------
        $snooze_time = (int) get_option( $this->get_snooze_option(), 0 );
        if ( $snooze_time && time() < $snooze_time + ( (int) $this->config['snooze_days'] * DAY_IN_SECONDS ) ) {
            return $this->set_cache( false );
        }

        // If a custom condition callback is provided, delegate entirely to it.
        if ( is_callable( $this->config['condition_callback'] ) ) {
            return $this->set_cache( (bool) call_user_func( $this->config['condition_callback'] ) );
        }

        // Default gate: show only after N days from plugin install.
        $installed_time = (int) get_option( $this->config['installed_option_key'], 0 );
        if ( ! $installed_time || time() < $installed_time + ( (int) $this->config['days_after_install'] * DAY_IN_SECONDS ) ) {
            return $this->set_cache( false );
        }

        return $this->set_cache( true );
    }

    /**
     * Visibility decision for event-triggered mode.
     *
     * Returns true when a pending trigger exists and was stored after the
     * current snooze window expired.
     *
     * @return bool
     */
    private function should_show_event_triggered(): bool {
        $trigger_json = get_option( $this->get_trigger_option() );
        if ( ! $trigger_json ) {
            return false;
        }

        $trigger = json_decode( $trigger_json, true );
        if ( ! is_array( $trigger ) || empty( $trigger['triggered_at'] ) ) {
            return false;
        }

        $trigger_time = (int) $trigger['triggered_at'];

        // Check whether the trigger occurred inside an active snooze window.
        // Bootstrap-seeded snooze (snooze_count = 0) does NOT block event triggers —
        // only a user-initiated dismiss (snooze_count > 0) should suppress the prompt.
        $snooze_set_at = (int) get_option( $this->get_snooze_option(), 0 );
        if ( $snooze_set_at ) {
            $snooze_count = (int) get_option( $this->get_snooze_count_option(), 0 );
            if ( $snooze_count > 0 ) {
                $snooze_days  = $this->compute_effective_snooze_days( $snooze_count );
                $snooze_until = $snooze_set_at + ( $snooze_days * DAY_IN_SECONDS );

                if ( $trigger_time < $snooze_until ) {
                    return false;
                }
            }
        }

        // Persist resolved trigger for use during render.
        $this->current_trigger = $trigger;
        return true;
    }

    private function set_cache( bool $value ): bool {
        $this->should_show_cache = $value;
        return $value;
    }

    /**
     * Check whether the current admin screen is in the allowed list.
     * An empty allowed_screens array means "show everywhere in wp-admin".
     */
    private function is_allowed_screen(): bool {
        if ( ! is_admin() ) {
            return false;
        }

        $allowed = (array) $this->config['allowed_screens'];
        if ( empty( $allowed ) ) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page && in_array( $page, $allowed, true ) ) {
            return true;
        }

        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && in_array( $screen->id, $allowed, true ) ) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Inline CSS
    // -------------------------------------------------------------------------

    /**
     * Output the prompt stylesheet as an inline <style> block. Runs on
     * admin_enqueue_scripts so it fires before admin_footer markup.
     */
    public function maybe_output_style(): void {
        if ( ! $this->should_show() ) {
            return;
        }
        ?>
        <style id="<?php echo esc_attr( $this->slug ); ?>-review-style">
        /* === Wrapper (no overlay — background stays fully interactive) === */
        .linno-nps-overlay{position:fixed;inset:0;background:transparent;z-index:99998;display:none;pointer-events:none}
        .linno-nps-overlay.is-visible{display:block}
        /* === Modal card: anchored to a corner via fixed positioning === */
        .linno-nps-modal{position:fixed;background:#fff;border-radius:16px;width:400px;max-width:calc(100vw - 32px);box-shadow:0 8px 32px rgba(0,0,0,.14),0 1.5px 6px rgba(0,0,0,.08);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;overflow:hidden;pointer-events:all;animation:linno-slide-in .25s ease}
        @keyframes linno-slide-in{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
        /* position variants — applied to the modal card itself */
        .linno-nps-pos-middle .linno-nps-modal{top:50%;left:50%;transform:translate(-50%,-50%)}
        .linno-nps-pos-middle .linno-nps-modal:not([style]){animation:linno-fade-in .25s ease}
        @keyframes linno-fade-in{from{opacity:0}to{opacity:1}}
        .linno-nps-pos-bottom-right .linno-nps-modal{bottom:24px;right:24px}
        .linno-nps-pos-bottom-left .linno-nps-modal{bottom:24px;left:24px}
        .linno-nps-pos-top-right .linno-nps-modal{top:52px;right:24px}
        .linno-nps-pos-top-left .linno-nps-modal{top:52px;left:24px}
        /* === Header === */
        .linno-nps-header{padding:18px 20px 0;display:flex;justify-content:space-between;align-items:center}
        .linno-nps-title{color:#1D2327;font-size:16px;font-weight:600;line-height:1}
        .linno-nps-close{cursor:pointer;padding:2px;border:none;background:none;display:flex;color:#6b7280}
        .linno-nps-close:hover{color:#374151}
        /* === Body === */
        .linno-nps-body{padding:16px 20px 20px}
        /* === Step 1: NPS question === */
        .linno-nps-question{color:#374151;font-size:14px;line-height:1.55;margin-bottom:16px}
        /* NPS buttons row */
        .linno-nps-scores{display:grid;grid-template-columns:repeat(11,1fr);gap:5px;margin-bottom:8px}
        .linno-nps-score-btn{aspect-ratio:1;width:100%;border-radius:50%;border:1.5px solid #d1d5db;background:#fff;cursor:pointer;font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;justify-content:center;transition:all .15s ease;padding:0}
        .linno-nps-score-btn:hover{border-color:#6E42D3;color:#6E42D3;background:#f5f0ff}
        /* selected colours by sentiment */
        .linno-nps-score-btn.nps-selected-low {border-color:#ef4444;background:#fef2f2;color:#b91c1c}
        .linno-nps-score-btn.nps-selected-mid {border-color:#f59e0b;background:#fffbeb;color:#92400e}
        .linno-nps-score-btn.nps-selected-high{border-color:#22c55e;background:#f0fdf4;color:#15803d}
        /* Not likely / Very likely labels */
        .linno-nps-labels{display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:4px}
        /* === Step 2: feedback form === */
        .linno-nps-feedback{display:none;margin-top:4px}
        .linno-nps-feedback-msg{color:#374151;font-size:14px;line-height:1.5;margin-bottom:16px}
        .linno-nps-feedback-label{display:block;font-size:13px;font-weight:600;color:#1D2327;margin-bottom:6px}
        .linno-nps-feedback-label .req{color:#6E42D3}
        .linno-nps-textarea{width:100%;min-height:96px;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:10px;resize:vertical;font-family:inherit;font-size:14px;line-height:1.5;box-sizing:border-box;outline:none}
        .linno-nps-textarea:focus{border-color:#6E42D3;box-shadow:0 0 0 3px rgba(110,66,211,.12)}
        .linno-nps-char-counter{font-size:12px;text-align:right;margin-top:4px;display:block}
        .linno-nps-support-link{display:inline-flex;align-items:center;gap:4px;font-size:13px;color:#6E42D3;text-decoration:none;margin-top:12px;margin-bottom:4px}
        .linno-nps-support-link:hover{text-decoration:underline}
        /* === Step 2: action bar === */
        .linno-nps-actions{display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #F3F4F6}
        .linno-nps-btn-cancel,.linno-nps-btn-submit{padding:9px 22px;border-radius:8px;cursor:pointer;font-size:14px;font-weight:500;border:none;transition:all .2s}
        .linno-nps-btn-cancel{background:#F3F4F6;color:#374151}
        .linno-nps-btn-cancel:hover{background:#e5e7eb}
        .linno-nps-btn-submit{background:#6E42D3;color:#fff;margin-left:auto}
        .linno-nps-btn-submit:hover{background:#5b36b3}
        .linno-nps-btn-submit:disabled{opacity:.55;cursor:not-allowed}
        .linno-nps-privacy{display:block;font-size:11.5px;color:#9ca3af;margin-top:10px}
        .linno-nps-privacy a{color:#6E42D3}
        /* === Step 2 (high score): thank-you === */
        .linno-nps-thankyou{display:none;text-align:center;padding:8px 0 4px}
        .linno-nps-thankyou-icon{font-size:40px;line-height:1;margin-bottom:12px}
        .linno-nps-thankyou-title{font-size:17px;font-weight:600;color:#1D2327;margin-bottom:8px}
        .linno-nps-thankyou-text{font-size:14px;color:#6b7280;line-height:1.55}
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * Output the prompt HTML and inline JS. Runs on admin_footer.
     */
    public function render_prompt(): void {
        if ( ! $this->should_show() ) {
            return;
        }

        $slug            = esc_attr( $this->slug );
        $min_chars       = (int) $this->config['min_feedback_length'];
        $low_threshold   = (int) $this->config['low_score_threshold']; // 0 – (threshold-1) = low
        $position        = sanitize_key( $this->config['position'] ?: 'middle' );

        // Dynamic copy: trigger context values override static config defaults.
        $ctx             = is_array( $this->current_trigger ) ? (array) ( $this->current_trigger['context'] ?? [] ) : [];
        $nps_question    = esc_html( ! empty( $ctx['nps_question'] )    ? $ctx['nps_question']    : $this->config['nps_question'] );
        $modal_title     = esc_html( ! empty( $ctx['modal_title'] )     ? $ctx['modal_title']     : 'Share Your Feedback' );
        $feedback_msg    = esc_html( ! empty( $ctx['feedback_msg'] )    ? $ctx['feedback_msg']    : "We're sorry to hear that. What's not working for you?" );
        $thank_you_title = esc_html( ! empty( $ctx['thank_you_title'] ) ? $ctx['thank_you_title'] : 'Thank you for your support!' );
        $thank_you_text  = esc_html( ! empty( $ctx['thank_you_text'] )  ? $ctx['thank_you_text']  : "We're thrilled you love it! Your review helps other users discover us." );
        $privacy_url     = esc_url( $this->config['privacy_url'] );
        $support_url     = esc_url( $this->config['support_url'] );
        ?>

        <!-- Linno NPS overlay -->
        <div id="<?php echo $slug; ?>-nps-overlay" class="linno-nps-overlay linno-nps-pos-<?php echo esc_attr( $position ); ?>">
            <div class="linno-nps-modal" role="dialog" aria-modal="true"
                 aria-labelledby="<?php echo $slug; ?>-nps-title">

                <!-- Header -->
                <div class="linno-nps-header">
                    <span class="linno-nps-title" id="<?php echo $slug; ?>-nps-title"><?php echo $modal_title; ?></span>
                    <button type="button" class="linno-nps-close" id="<?php echo $slug; ?>-nps-close"
                            aria-label="<?php esc_attr_e( 'Close', 'linno-telemetry' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M15 5L5 15M5 5l10 10" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="linno-nps-body">

                    <!-- Step 1: NPS question + score buttons -->
                    <div id="<?php echo $slug; ?>-nps-step1">
                        <p class="linno-nps-question"><?php echo $nps_question; ?></p>

                        <div class="linno-nps-scores" role="group" aria-label="NPS score 0 to 10"
                             id="<?php echo $slug; ?>-nps-scores">
                            <?php for ( $i = 0; $i <= 10; $i++ ) : ?>
                            <button type="button"
                                    class="linno-nps-score-btn"
                                    data-score="<?php echo $i; ?>"
                                    aria-label="Score <?php echo $i; ?>">
                                <?php echo $i; ?>
                            </button>
                            <?php endfor; ?>
                        </div>

                        <div class="linno-nps-labels">
                            <span>Not likely</span>
                            <span>Very likely</span>
                        </div>
                    </div>

                    <!-- Step 2a: Low-score feedback form (scores 0 – threshold-1) -->
                    <div id="<?php echo $slug; ?>-nps-feedback" class="linno-nps-feedback">
                        <p class="linno-nps-feedback-msg" id="<?php echo $slug; ?>-nps-feedback-msg">
                            <?php echo $feedback_msg; ?>
                        </p>

                        <label class="linno-nps-feedback-label" for="<?php echo $slug; ?>-nps-textarea">
                            What should we fix?&ensp;<span class="req">(Required)</span>
                        </label>
                        <textarea id="<?php echo $slug; ?>-nps-textarea"
                                  class="linno-nps-textarea"
                                  placeholder="Describe the issue so we can fix it..."
                                  rows="4"></textarea>
                        <span class="linno-nps-char-counter" id="<?php echo $slug; ?>-nps-counter"
                              style="color:#ef4444">
                            0 / <?php echo esc_html( $min_chars ); ?> characters minimum
                        </span>

                        <?php if ( $support_url ) : ?>
                        <a class="linno-nps-support-link"
                           href="<?php echo $support_url; ?>"
                           target="_blank" rel="noopener noreferrer">
                            Or contact our support team directly
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                <path d="M2.5 7h9M7.5 3l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                        <?php endif; ?>

                        <div class="linno-nps-actions">
                            <button type="button" class="linno-nps-btn-cancel"
                                    id="<?php echo $slug; ?>-nps-cancel">Cancel</button>
                            <button type="button" class="linno-nps-btn-submit"
                                    id="<?php echo $slug; ?>-nps-submit" disabled>Submit</button>
                        </div>

                        <span class="linno-nps-privacy">
                            By submitting, you agree to our
                            <a href="<?php echo $privacy_url; ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
                        </span>
                    </div>

                    <!-- Step 2b: High-score thank-you -->
                    <div id="<?php echo $slug; ?>-nps-thankyou" class="linno-nps-thankyou">
                        <div class="linno-nps-thankyou-icon">&#127881;</div>
                        <div class="linno-nps-thankyou-title"><?php echo $thank_you_title; ?></div>
                        <p class="linno-nps-thankyou-text">
                            <?php echo $thank_you_text; ?>
                        </p>
                    </div>

                </div><!-- /.linno-nps-body -->
            </div><!-- /.linno-nps-modal -->
        </div><!-- /.linno-nps-overlay -->

        <script type="text/javascript">
        (function ($, w) {
            'use strict';

            var slug         = <?php echo wp_json_encode( $this->slug ); ?>;
            var reviewUrl    = <?php echo wp_json_encode( $this->config['review_url'] ); ?>;
            var minChars     = <?php echo (int) $min_chars; ?>;
            var lowThreshold = <?php echo (int) $low_threshold; ?>; // scores < lowThreshold = detractors
            var ajaxUrl      = (typeof w.ajaxurl !== 'undefined') ? w.ajaxurl : '';
            var ajaxAction   = <?php echo wp_json_encode( $this->get_ajax_action() ); ?>;
            var nonce        = <?php echo wp_json_encode( wp_create_nonce( $this->get_nonce_action() ) ); ?>;

            var overlay     = '#' + slug + '-nps-overlay';
            var step1       = '#' + slug + '-nps-step1';
            var feedbackEl  = '#' + slug + '-nps-feedback';
            var thankyouEl  = '#' + slug + '-nps-thankyou';
            var textareaEl  = '#' + slug + '-nps-textarea';
            var counterEl   = '#' + slug + '-nps-counter';
            var submitBtn   = '#' + slug + '-nps-submit';
            var cancelBtn   = '#' + slug + '-nps-cancel';
            var closeBtn    = '#' + slug + '-nps-close';
            var scoresEl    = '#' + slug + '-nps-scores';

            var selectedScore = null;

            function sendAction(type, data) {
                if (!ajaxUrl) { return; }
                $.post(ajaxUrl, $.extend({ action: ajaxAction, linno_action_type: type, nonce: nonce }, data || {}));
            }

            function closeModal() {
                $(overlay).removeClass('is-visible');
                sendAction('snooze');
            }

            function resetFeedback() {
                $(textareaEl).val('');
                updateCounter(0);
                $(submitBtn).prop('disabled', true);
            }

            function updateCounter(len) {
                $(counterEl).text(len + ' / ' + minChars + ' characters minimum')
                            .css('color', len >= minChars ? '#16a34a' : '#ef4444');
                $(submitBtn).prop('disabled', len < minChars);
            }

            function getScoreClass(score) {
                if (score < lowThreshold) { return 'nps-selected-low'; }
                if (score <= 8)           { return 'nps-selected-mid'; }
                return 'nps-selected-high';
            }

            $(function () {

                // Show after a short delay (avoids interrupting page load).
                setTimeout(function () { $(overlay).addClass('is-visible'); }, 2000);

                // Close button — snooze.
                $(closeBtn).on('click', closeModal);

                // NPS score buttons.
                $(scoresEl).on('click', '.linno-nps-score-btn', function () {
                    var score = parseInt($(this).data('score'), 10);
                    selectedScore = score;

                    // Highlight the selected button.
                    $(scoresEl).find('.linno-nps-score-btn')
                        .removeClass('nps-selected-low nps-selected-mid nps-selected-high');
                    $(this).addClass(getScoreClass(score));

                    if (score < lowThreshold) {
                        // Detractor — show feedback form.
                        $(step1).hide();
                        resetFeedback();
                        $(feedbackEl).fadeIn(200);
                    } else {
                        // Promoter / passive — open review URL and show thank-you.
                        $(step1).hide();
                        if (reviewUrl) { w.open(reviewUrl, '_blank', 'noopener,noreferrer'); }
                        $(thankyouEl).fadeIn(200);
                        sendAction('completed', { nps_score: score });
                        setTimeout(function () { $(overlay).removeClass('is-visible'); }, 3000);
                    }
                });

                // Cancel — go back to score step.
                $(cancelBtn).on('click', function () {
                    $(feedbackEl).hide();
                    resetFeedback();
                    $(scoresEl).find('.linno-nps-score-btn')
                        .removeClass('nps-selected-low nps-selected-mid nps-selected-high');
                    selectedScore = null;
                    $(step1).fadeIn(200);
                });

                // Live char counter.
                $(textareaEl).on('input', function () {
                    updateCounter($(this).val().trim().length);
                });

                // Submit.
                $(submitBtn).on('click', function () {
                    var val = $(textareaEl).val().trim();
                    if (val.length < minChars) { return; }

                    $(this).text('Submitting…').prop('disabled', true);
                    sendAction('feedback', { feedback: val, nps_score: selectedScore !== null ? selectedScore : '' });
                    setTimeout(function () { $(overlay).removeClass('is-visible'); }, 500);
                });

            });
        }(jQuery, window));
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handler
    // -------------------------------------------------------------------------

    public function handle_ajax(): void {
        check_ajax_referer( $this->get_nonce_action(), 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
            return;
        }

        $type = isset( $_POST['linno_action_type'] )
            ? sanitize_text_field( wp_unslash( $_POST['linno_action_type'] ) )
            : '';

        if ( 'snooze' === $type ) {
            // In event-triggered mode, increment the dismiss counter so the
            // next snooze interval is fetched from the progressive schedule.
            if ( ! empty( $this->config['snooze_schedule'] ) ) {
                $snooze_count = (int) get_option( $this->get_snooze_count_option(), 0 ) + 1;
                update_option( $this->get_snooze_count_option(), $snooze_count );
            }
            update_option( $this->get_snooze_option(), time() );

        } elseif ( 'completed' === $type ) {
            update_option( $this->get_status_option(), 'completed' );

            $nps_score = isset( $_POST['nps_score'] ) && is_numeric( $_POST['nps_score'] )
                ? (int) $_POST['nps_score'] : null;

            if ( null !== $nps_score ) {
                $this->track_nps_to_posthog( $nps_score, '' );
            }

        } elseif ( 'feedback' === $type ) {
            update_option( $this->get_status_option(), 'completed' );

            $feedback  = isset( $_POST['feedback'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
            $nps_score = isset( $_POST['nps_score'] ) && is_numeric( $_POST['nps_score'] )
                ? (int) $_POST['nps_score'] : null;

            $this->track_nps_to_posthog( $nps_score, $feedback );

            if ( ! empty( $feedback ) && ! empty( $this->config['webhook'] ) ) {
                $this->send_feedback( $feedback, $nps_score );
            }
        }

        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // PostHog NPS tracking
    // -------------------------------------------------------------------------

    /**
     * Send the NPS submission to PostHog via the parent Client.
     *
     * Event name : nps_survey_submitted
     * Properties :
     *   nps_score      int          0–10 raw score.
     *   nps_category   string       promoter | passive | detractor.
     *   feedback       string       Detractor feedback text (empty for promoters).
     *   trigger_event  string       The event_key that triggered this prompt.
     *   snooze_count   int          How many times the user dismissed before submitting.
     *   product_slug   string       Plugin slug.
     *
     * Uses track_immediate() so the event is dispatched in the same request,
     * bypassing the background queue to guarantee delivery on form submit.
     *
     * @param int|null $nps_score Raw score (0–10), or null if not captured.
     * @param string   $feedback  Detractor feedback text.
     * @return void
     */
    private function track_nps_to_posthog( ?int $nps_score, string $feedback ): void {
        $low_threshold = (int) $this->config['low_score_threshold'];

        if ( null !== $nps_score ) {
            if ( $nps_score < $low_threshold ) {
                $category = 'detractor';
            } elseif ( $nps_score <= 8 ) {
                $category = 'passive';
            } else {
                $category = 'promoter';
            }
        } else {
            $category = 'unknown';
        }

        // Resolve the trigger event key from the stored trigger payload.
        $trigger_json  = get_option( $this->get_trigger_option() );
        $trigger_data  = $trigger_json ? json_decode( $trigger_json, true ) : [];
        $trigger_event = isset( $trigger_data['event_key'] ) ? sanitize_key( $trigger_data['event_key'] ) : 'unknown';
        $snooze_count  = (int) get_option( $this->get_snooze_count_option(), 0 );

        $properties = [
            'nps_score'     => $nps_score,
            'nps_category'  => $category,
            'feedback'      => $feedback,
            'trigger_event' => $trigger_event,
            'snooze_count'  => $snooze_count,
            'product_slug'  => $this->slug,
        ];

        try {
            // track_immediate() sends directly to the configured driver (PostHog)
            // without queuing, using override=true to bypass the opt-in check
            // since this is an explicit user action (they chose to submit).
            $this->client->track_immediate( 'nps_survey_submitted', $properties, true );
        } catch ( \Exception $e ) {
            // Failure-safe — NPS tracking must not surface errors to the user.
            error_log( '[Linno Review Prompt] PostHog NPS track failed for ' . $this->slug . ': ' . $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------
    // Feedback delivery
    // -------------------------------------------------------------------------

    private function send_feedback( string $feedback, ?int $nps_score ): void {
        $current_user = wp_get_current_user();

        $payload = [
            'productSlug' => $this->slug,
            'productName' => $this->client->get_plugin_name(),
            'feedback'    => $feedback,
            'npsScore'    => $nps_score,
            'siteUrl'     => get_site_url(),
            'userEmail'   => ( $current_user instanceof \WP_User ) ? $current_user->user_email : '',
            'userName'    => ( $current_user instanceof \WP_User ) ? $current_user->display_name : '',
            'submittedAt' => current_time( 'mysql' ),
        ];

        $is_local = in_array(
            wp_get_environment_type(),
            [ 'local', 'development' ],
            true
        );

        $response = wp_remote_post(
            $this->config['webhook'],
            [
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'body'      => wp_json_encode( $payload ),
                'timeout'   => 8,
                'sslverify' => ! $is_local,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log(
                '[Linno Review Prompt] webhook failed for ' . $this->slug
                . ': ' . $response->get_error_message()
            );
        }
    }
}
