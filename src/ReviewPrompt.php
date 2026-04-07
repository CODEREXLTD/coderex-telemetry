<?php
/**
 * ReviewPrompt Class
 *
 * Renders a floating NPS / feedback prompt in the WordPress admin. Fully
 * self-contained: CSS and JS are output inline so no external asset URLs are
 * required. Any plugin can enable the prompt by passing a `review_prompt`
 * config array to the Client constructor, or by calling
 * `$client->enable_review_prompt( $config )` after construction.
 *
 * Config options
 * --------------
 * lark_webhook        (string)   Webhook URL that receives feedback payloads.
 * min_feedback_length (int)      Minimum textarea characters before submit (default 50).
 * days_after_install  (int)      Days after install before showing (default 3).
 * snooze_days         (int)      Days between re-shows after snooze (default 30).
 * question            (string)   Main prompt question. Defaults to "Is {plugin_name} helping you?".
 * positive_label      (string)   Label for the happy-face button (default "Yes, it's great!").
 * neutral_label       (string)   Label for the neutral-face button (default "It's okay").
 * negative_label      (string)   Label for the sad-face button (default "Not really").
 * review_url          (string)   URL opened when user clicks the positive/happy button.
 *                                Defaults to the WP.org reviews page for the slug.
 * privacy_url         (string)   Privacy policy link in the footer.
 * installed_option_key (string)  WP option key holding the plugin's install timestamp.
 *                                Defaults to "{slug}_installed_time".
 * condition_callback  (callable) Optional. Called instead of the default timing gate.
 *                                Return true to show, false to hide.
 * allowed_screens     (string[]) Admin page slugs or screen IDs that may show the prompt.
 *                                Empty array (default) = show on any WP admin screen.
 *
 * @package LinnoSDK\Telemetry
 * @since   1.1.0
 */

namespace LinnoSDK\Telemetry;

class ReviewPrompt {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const OPTION_STATUS = 'linno_review_status_';
    private const OPTION_SNOOZE = 'linno_review_snooze_';

    private const DEFAULTS = [
        'lark_webhook'         => '',
        'min_feedback_length'  => 50,
        'days_after_install'   => 3,
        'snooze_days'          => 30,
        'question'             => '',
        'positive_label'       => "Yes, it's great!",
        'neutral_label'        => "It's okay",
        'negative_label'       => 'Not really',
        'review_url'           => '',
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

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function __construct( Client $client, array $config = [] ) {
        $this->client = $client;
        $this->slug   = $client->get_slug();
        $this->config = array_merge( self::DEFAULTS, $config );

        // Fill dynamic defaults that depend on slug / plugin name.
        if ( empty( $this->config['question'] ) ) {
            $this->config['question'] = sprintf( 'Is %s helping you?', $client->get_plugin_name() );
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

    // -------------------------------------------------------------------------
    // Visibility logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether the prompt should be rendered on this page load.
     */
    public function should_show(): bool {
        if ( null !== $this->should_show_cache ) {
            return $this->should_show_cache;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return $this->set_cache( false );
        }

        if ( ! $this->is_allowed_screen() ) {
            return $this->set_cache( false );
        }

        // Developer test-mode bypass: ?{slug}_test_review=1
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET[ $this->slug . '_test_review' ] ) && '1' === $_GET[ $this->slug . '_test_review' ] ) {
            return $this->set_cache( true );
        }

        if ( 'completed' === get_option( $this->get_status_option() ) ) {
            return $this->set_cache( false );
        }

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
        .linno-review-prompt{position:fixed;bottom:30px;right:30px;width:420px;background:#fff;box-shadow:0 20px 25px -5px rgba(0,0,0,.10),0 8px 10px -6px rgba(0,0,0,.10);border-radius:16px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;overflow:hidden;display:none}
        .linno-review-header{background:#fff;padding:20px 20px 3px;display:flex;justify-content:space-between;align-items:center}
        .linno-review-header>span:first-child{color:#1D2327;font-size:18px;font-weight:600;line-height:1}
        .linno-review-close{cursor:pointer;color:#666;width:20px;height:20px;display:flex}
        .linno-review-body{padding:0 20px 20px}
        .linno-review-question{margin-bottom:20px;color:#707070;font-size:14px;font-weight:400;line-height:1.5}
        .linno-review-options{display:flex;flex-direction:column;gap:12px}
        .linno-review-btn{display:flex;align-items:center;background:#FCFCFE;border:1px solid #EEF0F3;padding:13px 16px;border-radius:10px;cursor:pointer;transition:all .2s ease;font-weight:500;color:#3c434a;font-size:14px;outline:none;box-shadow:none;text-decoration:none;width:100%}
        .linno-review-btn:hover{border-color:#d0d0d0}
        .linno-review-btn-icon{margin-right:8px;display:flex}
        .linno-review-btn.selected-positive{background:#d1e7dd;color:#0a3622;border-color:#a3cfbb}
        .linno-review-btn.selected-neutral{background:#fff3cd;color:#664d03;border-color:#ffe69c}
        .linno-review-btn.selected-negative{background:#f8d7da;color:#58151c;border-color:#f1aeb5}
        .linno-review-feedback-form{display:none;margin-top:20px}
        .linno-review-feedback-label{font-size:14px;color:#1F2937;margin-bottom:8px;font-weight:500;display:block}
        .linno-review-feedback-label span{color:#7A8B9A;font-weight:400}
        .linno-review-textarea{width:100%;min-height:100px;padding:12px;border:1px solid #EEF0F3;border-radius:10px;resize:vertical;font-family:inherit;font-size:14px;line-height:1.5;transition:all .2s;box-sizing:border-box;outline:none!important;box-shadow:none!important}
        .linno-review-textarea:focus{border-color:#6E42D3}
        .linno-review-char-counter{font-size:12px;color:#d43849;text-align:right;margin-top:-5px;margin-bottom:12px;display:block}
        .linno-review-footer{display:flex;gap:12px;margin-top:20px;border-top:1px solid #EEF0F3;padding-top:24px}
        .linno-review-cancel,.linno-review-submit{padding:10px 24px;border-radius:10px;cursor:pointer;font-weight:500;font-size:14px;transition:all .2s;border:none}
        .linno-review-cancel{background:#f5f5f5;color:#3c434a}
        .linno-review-cancel:hover{background:#e8e8e8}
        .linno-review-submit{background:#6E42D3;color:#fff}
        .linno-review-submit:hover{background:#5b36b3}
        .linno-review-submit:disabled{opacity:.6;cursor:not-allowed}
        .linno-review-privacy{font-size:12px;color:#8c8f94;margin-top:7px;display:block}
        .linno-review-privacy a{color:#6E42D3}
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

        $slug           = esc_attr( $this->slug );
        $min_chars      = (int) $this->config['min_feedback_length'];
        $question       = esc_html( $this->config['question'] );
        $review_url     = esc_url( $this->config['review_url'] );
        $privacy_url    = esc_url( $this->config['privacy_url'] );
        $positive_label = esc_html( $this->config['positive_label'] );
        $neutral_label  = esc_html( $this->config['neutral_label'] );
        $negative_label = esc_html( $this->config['negative_label'] );
        ?>

        <div id="<?php echo $slug; ?>-review-wrap" class="linno-review-prompt">
            <div class="linno-review-header">
                <span>Share Your Feedback</span>
                <span class="linno-review-close" id="<?php echo $slug; ?>-review-close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M15 5L5 15" stroke="#99a1af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M5 5L15 15" stroke="#99a1af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </div>

            <div class="linno-review-body">
                <div class="linno-review-question" id="<?php echo $slug; ?>-review-question">
                    <?php echo $question; ?>
                </div>

                <div class="linno-review-options" id="<?php echo $slug; ?>-review-options">
                    <a href="<?php echo $review_url; ?>" target="_blank"
                        rel="noopener noreferrer"
                        class="linno-review-btn"
                        id="<?php echo $slug; ?>-review-positive">
                        <span class="linno-review-btn-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><g clip-path="url(#lrp-a)"><path d="M9.99984 18.3333C14.6022 18.3333 18.3332 14.6023 18.3332 9.99996C18.3332 5.39759 14.6022 1.66663 9.99984 1.66663C5.39746 1.66663 1.6665 5.39759 1.6665 9.99996C1.6665 14.6023 5.39746 18.3333 9.99984 18.3333Z" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.6665 11.6666C6.6665 11.6666 7.9165 13.3333 9.99984 13.3333C12.0832 13.3333 13.3332 11.6666 13.3332 11.6666" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 7.5H7.50833" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 7.5H12.5083" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="lrp-a"><rect width="20" height="20" fill="#fff"/></clipPath></defs></svg>
                        </span>
                        <?php echo $positive_label; ?>
                    </a>

                    <button type="button" class="linno-review-btn"
                        id="<?php echo $slug; ?>-review-neutral"
                        data-feedback-type="neutral">
                        <span class="linno-review-btn-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><g clip-path="url(#lrp-b)"><path d="M9.99984 18.3333C14.6022 18.3333 18.3332 14.6023 18.3332 9.99996C18.3332 5.39759 14.6022 1.66663 9.99984 1.66663C5.39746 1.66663 1.6665 5.39759 1.6665 9.99996C1.6665 14.6023 5.39746 18.3333 9.99984 18.3333Z" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.6665 12.5H13.3332" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 7.5H7.50833" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 7.5H12.5083" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="lrp-b"><rect width="20" height="20" fill="#fff"/></clipPath></defs></svg>
                        </span>
                        <?php echo $neutral_label; ?>
                    </button>

                    <button type="button" class="linno-review-btn"
                        id="<?php echo $slug; ?>-review-negative"
                        data-feedback-type="negative">
                        <span class="linno-review-btn-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><g clip-path="url(#lrp-c)"><path d="M9.99984 18.3333C14.6022 18.3333 18.3332 14.6023 18.3332 9.99996C18.3332 5.39759 14.6022 1.66663 9.99984 1.66663C5.39746 1.66663 1.6665 5.39759 1.6665 9.99996C1.6665 14.6023 5.39746 18.3333 9.99984 18.3333Z" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.3332 13.3333C13.3332 13.3333 12.0832 11.6666 9.99984 11.6666C7.9165 11.6666 6.6665 13.3333 6.6665 13.3333" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 7.5H7.50833" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 7.5H12.5083" stroke="#4a5565" stroke-width="1.67" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="lrp-c"><rect width="20" height="20" fill="#fff"/></clipPath></defs></svg>
                        </span>
                        <?php echo $negative_label; ?>
                    </button>
                </div>

                <div class="linno-review-feedback-form" id="<?php echo $slug; ?>-review-feedback-form">
                    <label class="linno-review-feedback-label">
                        How can we improve? <span>(Optional)</span>
                    </label>
                    <textarea class="linno-review-textarea"
                        id="<?php echo $slug; ?>-review-textarea"
                        placeholder="Share your thoughts"></textarea>
                    <span class="linno-review-char-counter" id="<?php echo $slug; ?>-char-counter">
                        <span id="<?php echo $slug; ?>-char-count">0</span> /
                        <?php echo esc_html( $min_chars ); ?> characters minimum
                    </span>
                    <div class="linno-review-footer">
                        <button class="linno-review-cancel"
                            id="<?php echo $slug; ?>-review-cancel">Cancel</button>
                        <button class="linno-review-submit"
                            id="<?php echo $slug; ?>-review-submit">Submit</button>
                    </div>
                    <span class="linno-review-privacy">
                        By submitting, you agree to our
                        <a href="<?php echo $privacy_url; ?>" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.
                    </span>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        (function ($, w) {
            'use strict';

            var slug        = <?php echo wp_json_encode( $this->slug ); ?>;
            var minChars    = <?php echo (int) $min_chars; ?>;
            var ajaxUrl     = (typeof w.ajaxurl !== 'undefined') ? w.ajaxurl : '';
            var ajaxAction  = <?php echo wp_json_encode( $this->get_ajax_action() ); ?>;
            var nonce       = <?php echo wp_json_encode( wp_create_nonce( $this->get_nonce_action() ) ); ?>;
            var initQuestion = <?php echo wp_json_encode( $this->config['question'] ); ?>;
            var errorMsg    = <?php echo wp_json_encode( sprintf(
                'Please share at least %d characters so we can understand what needs improvement and make things better for you and other users.',
                $min_chars
            ) ); ?>;

            var wrap       = '#' + slug + '-review-wrap';
            var questionEl = '#' + slug + '-review-question';
            var optionsEl  = '#' + slug + '-review-options';
            var formEl     = '#' + slug + '-review-feedback-form';
            var textareaEl = '#' + slug + '-review-textarea';
            var charCountEl  = '#' + slug + '-char-count';
            var charCounterEl = '#' + slug + '-char-counter';
            var errorElId  = slug + '-review-feedback-error';
            var selectedType = '';

            function sendAction(type, feedback, feedbackType) {
                if (!ajaxUrl) { return; }
                $.post(ajaxUrl, {
                    action:            ajaxAction,
                    linno_action_type: type,
                    feedback:          feedback || '',
                    feedback_type:     feedbackType || '',
                    nonce:             nonce
                });
            }

            $(function () {
                // Slide in after 2 s so it doesn't interrupt the page load.
                setTimeout(function () { $(wrap).fadeIn(); }, 2000);

                // Close / snooze.
                $('#' + slug + '-review-close').on('click', function () {
                    $(wrap).fadeOut();
                    sendAction('snooze');
                });

                // Positive — open WP.org review tab and mark completed.
                $('#' + slug + '-review-positive').on('click', function () {
                    setTimeout(function () { $(wrap).fadeOut(); }, 300);
                    sendAction('completed');
                });

                // Neutral / negative — show textarea form.
                $('#' + slug + '-review-neutral, #' + slug + '-review-negative').on('click', function () {
                    var btnId = $(this).attr('id');
                    var type  = (btnId === slug + '-review-neutral') ? 'neutral' : 'negative';
                    $('.linno-review-btn').removeClass('selected-positive selected-neutral selected-negative');
                    $(this).addClass('selected-' + type);
                    $(questionEl).text('Sorry to hear that! What could we do better?');
                    $(optionsEl).hide();
                    $(formEl).fadeIn();
                    selectedType = $(this).data('feedback-type') || '';
                });

                // Cancel — restore initial state.
                $('#' + slug + '-review-cancel').on('click', function () {
                    $(formEl).hide();
                    $(textareaEl).val('');
                    $(charCountEl).text('0');
                    $(charCounterEl).css('color', '#d43849');
                    $(questionEl).text(initQuestion);
                    $(optionsEl).fadeIn();
                    $('.linno-review-btn').removeClass('selected-positive selected-neutral selected-negative');
                    $('#' + errorElId).remove();
                    selectedType = '';
                });

                // Live character count.
                $(textareaEl).on('input', function () {
                    var len = $(this).val().trim().length;
                    $(charCountEl).text(len);
                    $('#' + errorElId).hide();
                    $(charCounterEl).css('color', len >= minChars ? '#00a32a' : '#d63638');
                });

                // Submit.
                $('#' + slug + '-review-submit').on('click', function () {
                    var val = $(textareaEl).val();
                    if (val.trim().length < minChars) {
                        if ($('#' + errorElId).length === 0) {
                            $(charCounterEl).after(
                                '<div id="' + errorElId + '" style="background:#fcf0f1;border-left:3px solid #d63638;'
                                + 'padding:10px 12px;font-size:12px;color:#d63638;line-height:1.4;margin-bottom:12px;'
                                + 'border-radius:0 3px 3px 0;margin-top:3px;">' + errorMsg + '</div>'
                            );
                        } else {
                            $('#' + errorElId).text(errorMsg).show();
                        }
                        return;
                    }
                    $(this).text('Submitting...').prop('disabled', true);
                    sendAction('feedback', val, selectedType);
                    setTimeout(function () { $(wrap).fadeOut(); }, 500);
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
            update_option( $this->get_snooze_option(), time() );

        } elseif ( 'completed' === $type ) {
            update_option( $this->get_status_option(), 'completed' );

        } elseif ( 'feedback' === $type ) {
            update_option( $this->get_status_option(), 'completed' );

            $feedback      = isset( $_POST['feedback'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
            $feedback_type = isset( $_POST['feedback_type'] )
                ? sanitize_text_field( wp_unslash( $_POST['feedback_type'] ) ) : '';

            if ( ! empty( $feedback ) && ! empty( $this->config['lark_webhook'] ) ) {
                $this->send_feedback( $feedback, $feedback_type );
            }
        }

        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Feedback delivery
    // -------------------------------------------------------------------------

    private function send_feedback( string $feedback, string $feedback_type ): void {
        $current_user = wp_get_current_user();

        $payload = [
            'productSlug'  => $this->slug,
            'productName'  => $this->client->get_plugin_name(),
            'feedback'     => $feedback,
            'feedbackType' => $feedback_type,
            'siteUrl'      => get_site_url(),
            'userEmail'    => ( $current_user instanceof \WP_User ) ? $current_user->user_email : '',
            'userName'     => ( $current_user instanceof \WP_User ) ? $current_user->display_name : '',
            'submittedAt'  => current_time( 'mysql' ),
        ];

        $response = wp_remote_post(
            $this->config['lark_webhook'],
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 8,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log(
                '[Linno Review Prompt] Lark webhook failed for ' . $this->slug
                . ': ' . $response->get_error_message()
            );
        }
    }
}
