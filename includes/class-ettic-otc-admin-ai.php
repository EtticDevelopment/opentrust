<?php
/**
 * AI Chat settings tab and its admin-post handlers.
 *
 * Owns the entire "AI Chat" surface inside the Ettic_OTC settings page:
 * the provider picker (Anthropic primary, others behind an "advanced"
 * disclosure), the per-provider key card with validate-and-save flow,
 * the post-key model picker + budget/limit form, and the four
 * admin-post.php endpoints that drive key save/forget/refresh and the
 * summary-backfill sweep.
 *
 * Bootstrapped by Ettic_OTC_Admin's constructor; subscribes its own
 * admin_post_* hooks. The settings page (which still lives on
 * Ettic_OTC_Admin as the menu callback) calls render_ai_tab() when the
 * "ai" tab is active.
 *
 * Settings writes that bypass the sanitize_settings filter (key
 * validation flips ai_enabled / ai_provider / ai_model_list_cached_at)
 * route through Ettic_OTC_Admin_Settings::save_settings_raw().
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ettic_OTC_Admin_AI {

    public const CRON_HOOK    = 'opentrust_ai_models_refresh';
    public const CACHE_TTL    = 25 * HOUR_IN_SECONDS; // Slightly > daily cron cadence so the cache never expires between ticks.

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_post_opentrust_ai_save_key',        [$this, 'handle_ai_save_key']);
        add_action('admin_post_opentrust_ai_forget_key',      [$this, 'handle_ai_forget_key']);
        add_action('admin_post_opentrust_ai_refresh_models',  [$this, 'handle_ai_refresh_models']);
        add_action('admin_post_opentrust_ai_summarize_sweep', [$this, 'handle_ai_summarize_sweep']);

        // Idempotent re-schedule on admin page loads — defends against the
        // daily cron getting cleared externally without forcing a deactivate/
        // reactivate cycle. wp_next_scheduled is an in-request lookup.
        add_action('admin_init', [self::class, 'schedule_cron']);
    }

    // ──────────────────────────────────────────────
    // AI tab — rendering
    // ──────────────────────────────────────────────

    public function render_ai_tab(array $settings): void {
        $stored_keys     = Ettic_OTC_Chat_Secrets::get_all();
        $active_provider = $settings['ai_provider'] ?? '';
        $has_active_key  = $active_provider !== '' && isset($stored_keys[$active_provider]);
        $is_non_anthropic_active = $has_active_key && $active_provider !== 'anthropic';

        // Surface any transient notice from the admin-post handlers.
        $notice = get_transient('opentrust_ai_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('opentrust_ai_notice_' . get_current_user_id());
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                esc_attr($class),
                esc_html((string) $notice['message'])
            );
        }

        $this->render_summary_backfill_banner($settings, $has_active_key);

        ?>
        <?php if ($is_non_anthropic_active): ?>
            <div class="ot-ai-active-warning">
                <strong><?php esc_html_e('Heads up: citation fidelity is not guaranteed on your active provider.', 'opentrust'); ?></strong>
                <p>
                    <?php
                    printf(
                        /* translators: %s: provider label, e.g. OpenAI */
                        wp_kses(__('You are currently using <strong>%s</strong>. Only Anthropic uses a structural Citations API — every other provider relies on prompted citation tags the model can ignore or fabricate. For a published trust center, switch to Anthropic below.', 'opentrust'), ['strong' => []]),
                        esc_html(ucfirst($active_provider))
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <p class="ot-ai-intro">
            <?php
            echo wp_kses(
                __('Ettic_OTC uses <strong>Anthropic Claude with the native Citations API</strong> to answer visitor questions about your trust center. Every claim the assistant makes is tied to an exact quote from one of your published documents — so no policy text is invented and nothing is paraphrased into something you did not actually publish.', 'opentrust'),
                ['strong' => []]
            );
            ?>
        </p>

        <details class="ot-ai-rationale">
            <summary><?php esc_html_e('Why Anthropic, and not OpenAI or any other provider?', 'opentrust'); ?></summary>
            <div class="ot-ai-rationale__body">
                <p>
                    <?php
                    echo wp_kses(
                        __('A trust center is a <strong>compliance surface</strong>. If the assistant invents a security commitment you never made, that is not a UX papercut — it is a misrepresentation of your security posture, and your customers and auditors will hold you to it.', 'opentrust'),
                        ['strong' => []]
                    );
                    ?>
                </p>
                <p>
                    <?php
                    echo wp_kses(
                        __('Anthropic is the <strong>only major provider</strong> that exposes a structural Citations API. Documents are sent as typed blocks and the model emits citations as first-class events containing the exact source document and the exact quoted text. The model literally cannot return a citation for text that is not in your source documents.', 'opentrust'),
                        ['strong' => []]
                    );
                    ?>
                </p>
                <p>
                    <?php esc_html_e('Every other provider (including OpenAI and any model accessed via OpenRouter) relies on prompted citation tags that we parse out of the answer after the fact. That works most of the time, but the model can ignore the instructions, make up document IDs, or attach a citation to a sentence it actually hallucinated. We support these providers as an escape hatch for organizations that genuinely cannot use Anthropic for procurement or data-residency reasons — but we very, very strongly recommend you do not run a public trust center on them.', 'opentrust'); ?>
                </p>
            </div>
        </details>

        <?php $this->render_ai_provider_picker($settings, $stored_keys); ?>

        <?php if ($has_active_key): ?>
            <?php $this->render_ai_settings_form($settings); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Top-of-page CTA prompting the operator to backfill missing AI policy
     * summaries. Lives outside render_ai_settings_form()'s settings <form>
     * so its own POST to admin-post.php isn't swallowed by the outer
     * options.php form (HTML disallows nested forms).
     *
     * Visible only when summary generation can actually run: AI configured,
     * the auto-summarize feature on, and at least one policy missing an
     * up-to-date summary.
     */
    private function render_summary_backfill_banner(array $settings, bool $has_active_key): void {
        if (!$has_active_key || empty($settings['ai_auto_summarize']) || !class_exists('Ettic_OTC_Chat_Summarizer')) {
            return;
        }
        $missing = Ettic_OTC_Chat_Summarizer::missing_summary_count();
        if ($missing < 1) {
            return;
        }
        ?>
        <div class="notice notice-warning" style="margin:14px 0;padding:14px 16px">
            <p style="margin:0 0 10px;font-size:14px">
                <strong>
                    <?php
                    printf(
                        esc_html(
                            /* translators: %d is the number of policies missing AI summaries. */
                            _n(
                                '%d policy is missing an AI summary.',
                                '%d policies are missing AI summaries.',
                                $missing,
                                'opentrust'
                            )
                        ),
                        (int) $missing
                    );
                    ?>
                </strong>
                <?php esc_html_e('Generate them now so the assistant can route questions accurately.', 'opentrust'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0">
                <?php wp_nonce_field('opentrust_ai_summarize_sweep'); ?>
                <input type="hidden" name="action" value="opentrust_ai_summarize_sweep">
                <button type="submit" class="button button-primary"><?php esc_html_e('Generate now', 'opentrust'); ?></button>
            </form>
        </div>
        <?php
    }

    private function render_ai_provider_picker(array $settings, array $stored_keys): void {
        $providers       = Ettic_OTC_Chat_Provider::available();
        $active_provider = $settings['ai_provider'] ?? '';

        // Partition: Anthropic is the primary, everything else is "advanced".
        $primary  = null;
        $advanced = [];
        foreach ($providers as $provider) {
            if ($provider['slug'] === 'anthropic') {
                $primary = $provider;
            } else {
                $advanced[] = $provider;
            }
        }

        // Defensive fallback: if Anthropic is somehow not registered, render
        // everything flat so the tab never breaks.
        if ($primary === null) {
            echo '<h3 class="ot-ai-section-heading">' . esc_html__('Choose a provider and add your key', 'opentrust') . '</h3>';
            echo '<div class="ot-ai-advanced__grid">';
            foreach ($providers as $provider) {
                $this->render_provider_card($provider, $stored_keys, $active_provider, 'advanced');
            }
            echo '</div>';
            return;
        }

        $is_anthropic_active = $active_provider === 'anthropic' && isset($stored_keys['anthropic']);
        $advanced_open       = $active_provider !== '' && $active_provider !== 'anthropic';
        ?>
        <h3 class="ot-ai-section-heading"><?php esc_html_e('Step 1 — Connect Anthropic', 'opentrust'); ?></h3>

        <?php $this->render_provider_card($primary, $stored_keys, $active_provider, 'primary'); ?>

        <?php if (!empty($advanced)): ?>
            <details class="ot-ai-advanced"<?php echo $advanced_open ? ' open' : ''; ?>>
                <summary><?php esc_html_e('Advanced: use a different provider (not recommended)', 'opentrust'); ?></summary>

                <div class="ot-ai-advanced__warning">
                    <strong><?php esc_html_e('These providers cannot guarantee citation fidelity.', 'opentrust'); ?></strong>
                    <p>
                        <?php esc_html_e('OpenAI and OpenRouter rely on prompted [[cite:document-id]] tags that we parse out of the answer after generation. The model can ignore the instruction, invent document IDs, or attach a citation to a sentence it actually hallucinated. We cannot detect when this happens.', 'opentrust'); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Do not use these providers for a published trust center', 'opentrust'); ?></strong>
                        <?php esc_html_e('unless your organization genuinely cannot use Anthropic for procurement, contractual, or data-residency reasons. Inaccurate claims about your security posture are a real compliance risk.', 'opentrust'); ?>
                    </p>
                </div>

                <div class="ot-ai-advanced__grid">
                    <?php foreach ($advanced as $provider): ?>
                        <?php $this->render_provider_card($provider, $stored_keys, $active_provider, 'advanced'); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <?php
    }

    /**
     * Render a single provider card. The 'primary' variant is the full-width
     * Anthropic card with descriptive copy; the 'advanced' variant is the
     * smaller, muted card used inside the advanced disclosure.
     *
     * @param array<string, mixed> $provider
     * @param array<string, string> $stored_keys
     */
    private function render_provider_card(array $provider, array $stored_keys, string $active_provider, string $variant): void {
        $slug      = (string) $provider['slug'];
        $label     = (string) $provider['label'];
        $key_url   = (string) $provider['key_url'];
        $is_active = $slug === $active_provider;
        $has_key   = isset($stored_keys[$slug]);
        $masked    = $has_key ? Ettic_OTC_Chat_Secrets::mask($stored_keys[$slug]) : '';

        $card_classes = ['ot-ai-card', 'ot-ai-card--' . $variant];
        if ($is_active) {
            $card_classes[] = 'is-active';
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
            <div class="ot-ai-card__header">
                <h4 class="ot-ai-card__title"><?php echo esc_html($label); ?></h4>
                <?php if ($variant === 'primary'): ?>
                    <span class="ot-ai-card__badge"><?php esc_html_e('Required for citation fidelity', 'opentrust'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($variant === 'primary'): ?>
                <p class="ot-ai-card__description">
                    <?php esc_html_e('Uses Claude with the native Citations API. Every quote the assistant attributes to one of your documents is structurally guaranteed to come from that document.', 'opentrust'); ?>
                </p>
            <?php endif; ?>

            <p class="ot-ai-card__keylink">
                <a href="<?php echo esc_url($key_url); ?>" target="_blank" rel="noopener">
                    <?php
                    /* translators: %s: provider name (e.g. Anthropic) */
                    printf(esc_html__('Get a %s API key', 'opentrust'), esc_html($label));
                    ?> ↗
                </a>
            </p>

            <?php if ($has_key && $is_active): ?>
                <div class="ot-ai-card__saved">
                    ✓ <?php echo esc_html($masked); ?>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('opentrust_ai_forget_key'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_forget_key">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                    <button type="submit" class="button-link ot-ai-card__forget" onclick="return confirm('<?php echo esc_js(__('Remove the saved key for this provider?', 'opentrust')); ?>')">
                        <?php esc_html_e('Replace key', 'opentrust'); ?>
                    </button>
                </form>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('opentrust_ai_save_key'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_save_key">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                    <input type="password" name="api_key" class="ot-ai-card__input" autocomplete="off" placeholder="<?php echo esc_attr(sprintf(
                        /* translators: %s: provider name (e.g. Anthropic) */
                        __('Paste your %s API key…', 'opentrust'),
                        $label
                    )); ?>" required>
                    <button type="submit" class="button button-primary ot-ai-card__submit">
                        <?php esc_html_e('Validate & save', 'opentrust'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_ai_settings_form(array $settings): void {
        $active_provider = $settings['ai_provider'];
        $models          = $this->cached_models_for($active_provider);
        $current_model   = (string) ($settings['ai_model'] ?? '');
        $refresh_url     = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_refresh_models&provider=' . rawurlencode($active_provider)),
            'opentrust_ai_refresh_models'
        );

        // Cache may have expired (empty list) or the provider may have
        // dropped the id (non-empty list, no match). Either way, synthesize
        // the saved selection so the <select> isn't empty — an empty <select>
        // posts nothing on save and silently blanks ai_model. Empty cache
        // alone isn't proof of deprecation, so only the no-match-in-non-empty
        // case shows the warning icon. Falls back to the raw id when the
        // snapshot label is missing (e.g. v2→v3 upgrade ran with an already-
        // expired cache, so the backfill couldn't seed a name).
        $is_unavailable = false;
        if ($current_model !== '' && $this->find_model_meta($current_model, $models) === null) {
            $snapshot = [
                'id'           => $current_model,
                'display_name' => (string) ($settings['ai_model_display_name'] ?? $current_model),
                'recommended'  => !empty($settings['ai_model_recommended']),
            ];
            if (!empty($models)) {
                $is_unavailable          = true;
                $snapshot['unavailable'] = true;
            }
            array_unshift($models, $snapshot);
        }
        ?>
        <h3 style="margin-top:32px"><?php esc_html_e('Step 2 — Pick a model and tune defaults', 'opentrust'); ?></h3>

        <form method="post" action="options.php">
            <?php settings_fields('opentrust_settings_group'); ?>

            <?php // Sentinel so sanitize_settings knows the AI tab is submitting. The
                  // sanitize callback's else-branches (admin.php:446-465, 478-488) carry
                  // every non-AI key forward from $old_settings byte-for-byte, so we do
                  // NOT need to re-POST those values via hidden inputs. ?>
            <input type="hidden" name="opentrust_settings[__ai_tab_save]" value="1">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="opentrust_ai_model"><?php esc_html_e('Active model', 'opentrust'); ?></label></th>
                    <td>
                        <?php if (empty($models)): ?>
                            <p class="description" style="color:#b91c1c">
                                <?php esc_html_e('No cached models found. Use Refresh to re-fetch the model list.', 'opentrust'); ?>
                            </p>
                        <?php else: ?>
                            <select id="opentrust_ai_model" name="opentrust_settings[ai_model]" style="min-width:360px">
                                <?php foreach ($models as $model): ?>
                                    <option value="<?php echo esc_attr($model['id']); ?>" <?php selected($current_model, $model['id']); ?>>
                                        <?php
                                        echo esc_html($model['display_name']);
                                        if (!empty($model['unavailable'])) {
                                            echo ' ' . esc_html__('(unavailable)', 'opentrust');
                                        }
                                        ?>
                                        <?php if (!empty($model['recommended'])): ?>
                                            — ★ <?php esc_html_e('Recommended', 'opentrust'); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_unavailable): ?>
                                <span class="opentrust-ai-model-unavailable" aria-hidden="false">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                                        <line x1="3" y1="3" x2="13" y2="13"/>
                                        <line x1="13" y1="3" x2="3" y2="13"/>
                                    </svg>
                                    <span class="description"><?php esc_html_e('Model unavailable', 'opentrust'); ?></span>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="<?php echo esc_url($refresh_url); ?>" class="button" style="margin-left:8px">
                            <?php esc_html_e('Refresh models', 'opentrust'); ?>
                        </a>
                        <?php
                        $cached_at = (int) ($settings['ai_model_list_cached_at'] ?? 0);
                        if ($cached_at > 0):
                            $diff = human_time_diff($cached_at);
                            ?>
                            <p class="description">
                                <?php
                                /* translators: %s: human-readable time difference (e.g. "5 minutes") */
                                printf(esc_html__('Model list cached %s ago.', 'opentrust'), esc_html($diff));
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="opentrust_ai_daily_token_budget"><?php esc_html_e('Daily token budget', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_daily_token_budget" name="opentrust_settings[ai_daily_token_budget]" value="<?php echo esc_attr((string) ($settings['ai_daily_token_budget'] ?? Ettic_OTC_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET)); ?>" min="0" step="10000" class="regular-text">
                        <p class="description"><?php esc_html_e('Hard cap per site per day. Default 500,000 tokens (~$12/day at Sonnet 4.5 rates).', 'opentrust'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_monthly_token_budget"><?php esc_html_e('Monthly token budget', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_monthly_token_budget" name="opentrust_settings[ai_monthly_token_budget]" value="<?php echo esc_attr((string) ($settings['ai_monthly_token_budget'] ?? Ettic_OTC_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET)); ?>" min="0" step="100000" class="regular-text">
                        <p class="description"><?php esc_html_e('Hard cap per site per month. Default 10,000,000 tokens.', 'opentrust'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_rate_limit_per_ip"><?php esc_html_e('Rate limit — per IP', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_rate_limit_per_ip" name="opentrust_settings[ai_rate_limit_per_ip]" value="<?php echo esc_attr((string) ($settings['ai_rate_limit_per_ip'] ?? 10)); ?>" min="0" max="1000" step="1" class="small-text"> <span class="description"><?php esc_html_e('messages per minute', 'opentrust'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_rate_limit_per_session"><?php esc_html_e('Rate limit — per session', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_rate_limit_per_session" name="opentrust_settings[ai_rate_limit_per_session]" value="<?php echo esc_attr((string) ($settings['ai_rate_limit_per_session'] ?? 50)); ?>" min="0" max="10000" step="1" class="small-text"> <span class="description"><?php esc_html_e('messages per hour', 'opentrust'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_max_message_length"><?php esc_html_e('Max message length', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_max_message_length" name="opentrust_settings[ai_max_message_length]" value="<?php echo esc_attr((string) ($settings['ai_max_message_length'] ?? Ettic_OTC_Chat::DEFAULT_MAX_MESSAGE_LENGTH)); ?>" min="100" max="4000" step="100" class="small-text"> <span class="description"><?php esc_html_e('characters', 'opentrust'); ?></span>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="opentrust_ai_contact_url"><?php esc_html_e('Refuse-to-answer contact URL', 'opentrust'); ?></label></th>
                    <td>
                        <input type="url" id="opentrust_ai_contact_url" name="opentrust_settings[ai_contact_url]" value="<?php echo esc_attr((string) ($settings['ai_contact_url'] ?? '')); ?>" class="regular-text" placeholder="https://example.com/contact">
                        <p class="description"><?php esc_html_e('When the AI cannot confidently answer a question, it links here. Leave blank to use the trust center home.', 'opentrust'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Visitor display', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_show_model_attribution]" value="1" <?php checked(!empty($settings['ai_show_model_attribution'])); ?>>
                            <?php esc_html_e('Show the active model name under the chat input', 'opentrust'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Analytics logging', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_logging_enabled]" value="1" <?php checked(!empty($settings['ai_logging_enabled'])); ?>>
                            <?php esc_html_e('Log anonymized visitor questions for admin review (90-day auto-purge, no PII)', 'opentrust'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Improve answer quality', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_auto_summarize]" value="1" <?php checked(!empty($settings['ai_auto_summarize'])); ?>>
                            <?php esc_html_e('Generate AI summaries of each policy', 'opentrust'); ?>
                        </label>
                        <p class="description" style="max-width:680px">
                            <?php esc_html_e('When on, the AI generates a 2–3 sentence summary of each published policy and stores it for routing decisions. Improves answers on questions like "What\'s your data deletion policy?" that don\'t match a title literally. Cost is roughly $0.05–$0.10 per 50 policies, lifetime — pennies per edit afterward. Uses your configured AI key.', 'opentrust'); ?>
                        </p>
                    </td>
                </tr>

                <?php if (class_exists('Ettic_OTC_Chat_Corpus')): ?>
                    <?php $ot_oversized = Ettic_OTC_Chat_Corpus::oversized_policies(); ?>
                    <?php if (!empty($ot_oversized)): ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Oversized policies', 'opentrust'); ?></th>
                            <td>
                                <div style="padding:10px 14px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:4px;max-width:680px">
                                    <p style="margin:0 0 6px">
                                        <?php esc_html_e('The following policies are large enough that the AI will receive only a truncated version when retrieving them. Consider splitting them into shorter documents:', 'opentrust'); ?>
                                    </p>
                                    <ul style="margin:6px 0 0 18px">
                                        <?php foreach ($ot_oversized as $row): ?>
                                            <li><?php
                                                printf(
                                                    /* translators: 1: policy title, 2: token count. */
                                                    esc_html__('%1$s (~%2$s tokens)', 'opentrust'),
                                                    esc_html((string) $row['title']),
                                                    esc_html(number_format_i18n((int) $row['tokens']))
                                                );
                                            ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            </table>

            <h3 style="margin-top:24px"><?php esc_html_e('Advanced — Turnstile anti-abuse', 'opentrust'); ?></h3>
            <p class="description" style="max-width:720px">
                <?php esc_html_e('Cloudflare Turnstile is optional but recommended for public sites. It challenges suspicious visitors on the first message of each session. You need a free Cloudflare account to get site/secret keys.', 'opentrust'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Turnstile for chat', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_turnstile_enabled]" value="1" <?php checked(!empty($settings['ai_turnstile_enabled'])); ?>>
                            <?php esc_html_e('Require Turnstile verification on first chat message', 'opentrust'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_turnstile_site_key"><?php esc_html_e('Turnstile Site Key', 'opentrust'); ?></label></th>
                    <td>
                        <input type="text" id="opentrust_turnstile_site_key" name="opentrust_settings[turnstile_site_key]" value="<?php echo esc_attr((string) ($settings['turnstile_site_key'] ?? '')); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Public site key from your Cloudflare Turnstile widget.', 'opentrust'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_turnstile_secret_key"><?php esc_html_e('Turnstile Secret Key', 'opentrust'); ?></label></th>
                    <td>
                        <?php $ot_secret_saved = !empty($settings['turnstile_secret_key']); ?>
                        <input type="password" id="opentrust_turnstile_secret_key" name="opentrust_settings[turnstile_secret_key]" value="<?php echo esc_attr($ot_secret_saved ? '••••••••••••••••••••' : ''); ?>" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Enter secret key…', 'opentrust'); ?>">
                        <?php if ($ot_secret_saved): ?>
                            <span class="description" style="color:#16a34a">&#10003; <?php esc_html_e('Key saved', 'opentrust'); ?></span>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Secret key from Cloudflare Turnstile. Stored server-side — never exposed to the frontend.', 'opentrust'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save AI settings', 'opentrust')); ?>
        </form>
        <?php
    }

    /**
     * Read the cached model list for a provider. Empty if no key or no cache.
     */
    private function cached_models_for(string $provider): array {
        if ($provider === '') {
            return [];
        }
        $stored_keys = Ettic_OTC_Chat_Secrets::get_all();
        if (!isset($stored_keys[$provider])) {
            return [];
        }
        $cached = get_transient($this->cache_key_for($provider, $stored_keys[$provider]));
        return is_array($cached) && isset($cached['models']) && is_array($cached['models'])
            ? $cached['models']
            : [];
    }

    private function cache_key_for(string $provider, string $api_key): string {
        return 'opentrust_models_' . $provider . '_' . Ettic_OTC_Chat_Secrets::fingerprint($api_key);
    }

    /**
     * Find a model entry by id. Returns null if $models is empty or no match.
     *
     * @param array<int, array{id: string, display_name: string, recommended: bool}> $models
     * @return array{id: string, display_name: string, recommended: bool}|null
     */
    public function find_model_meta(string $id, array $models): ?array {
        if ($id === '') {
            return null;
        }
        foreach ($models as $model) {
            if (($model['id'] ?? '') === $id) {
                return $model;
            }
        }
        return null;
    }

    /**
     * Look up the snapshot fields for $model_id against the cached model list
     * for $provider. Null if no key, no cache, or no match. Used by
     * sanitize_settings, which doesn't otherwise know about the model cache.
     *
     * @return array{display_name: string, recommended: bool}|null
     */
    public function snapshot_for_provider(string $provider, string $model_id): ?array {
        if ($model_id === '') {
            return null;
        }
        $meta = $this->find_model_meta($model_id, $this->cached_models_for($provider));
        return $meta === null ? null : [
            'display_name' => (string) ($meta['display_name'] ?? ''),
            'recommended'  => !empty($meta['recommended']),
        ];
    }

    /**
     * Write the active model's metadata snapshot into $settings. No-op if the
     * id isn't in $models — the existing snapshot stays untouched so the
     * dropdown can still render a label after a deprecation or cache miss.
     *
     * @param array<int, array{id: string, display_name: string, recommended: bool}> $models
     */
    public function snapshot_active_model(array &$settings, array $models): void {
        $id = (string) ($settings['ai_model'] ?? '');
        if ($id === '') {
            return;
        }
        $meta = $this->find_model_meta($id, $models);
        if ($meta === null) {
            return;
        }
        $settings['ai_model_display_name'] = (string) ($meta['display_name'] ?? '');
        $settings['ai_model_recommended']  = !empty($meta['recommended']);
    }

    /**
     * Hit a provider's /models endpoint, refresh its transient cache on success.
     * Returns the raw validate_and_list_models() result so the caller can
     * branch on ok/error.
     *
     * @return array{ok: bool, models?: array<int, array{id: string, display_name: string, recommended: bool}>, error?: string}
     */
    private function refresh_provider_cache(string $slug, string $api_key): array {
        $adapter = Ettic_OTC_Chat_Provider::for($slug);
        if (!$adapter) {
            return ['ok' => false, 'error' => 'Unknown provider'];
        }
        $result = $adapter->validate_and_list_models($api_key);
        if (!empty($result['ok'])) {
            set_transient(
                $this->cache_key_for($slug, $api_key),
                ['models' => $result['models'], 'fetched_at' => time()],
                self::CACHE_TTL
            );
        }
        return $result;
    }

    // ──────────────────────────────────────────────
    // AI tab — admin-post handlers
    // ──────────────────────────────────────────────

    public function handle_ai_save_key(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_save_key');

        $provider = isset($_POST['provider']) ? sanitize_key((string) wp_unslash($_POST['provider'])) : '';
        $api_key  = isset($_POST['api_key'])  ? trim(sanitize_text_field((string) wp_unslash($_POST['api_key']))) : '';

        $adapter = Ettic_OTC_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }
        if ($api_key === '') {
            $this->ai_notice('error', __('API key cannot be empty.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $result = $this->refresh_provider_cache($provider, $api_key);

        if (empty($result['ok'])) {
            $error = $result['error'] ?? __('Validation failed.', 'opentrust');
            /* translators: 1: provider label, 2: provider error message */
            $msg = sprintf(__('%1$s rejected the key: %2$s', 'opentrust'), $adapter->label(), $error);
            $this->ai_notice('error', $msg);
            $this->redirect_to_ai_tab();
        }

        Ettic_OTC_Chat_Secrets::put($provider, $api_key);

        // Update settings: mark AI enabled, record provider + cache timestamp,
        // and if no model is selected yet, pre-pick the first recommended model.
        $settings = Ettic_OTC::get_settings();
        $settings['ai_enabled']              = true;
        $settings['ai_provider']             = $provider;
        $settings['ai_model_list_cached_at'] = time();
        if (empty($settings['ai_model'])) {
            foreach ($result['models'] as $model) {
                if (!empty($model['recommended'])) {
                    $settings['ai_model'] = $model['id'];
                    break;
                }
            }
            if (empty($settings['ai_model']) && !empty($result['models'][0]['id'])) {
                $settings['ai_model'] = $result['models'][0]['id'];
            }
        }
        $this->snapshot_active_model($settings, $result['models']);
        Ettic_OTC_Admin_Settings::instance()->save_settings_raw($settings);

        /* translators: 1: provider label, 2: number of models */
        $count_msg = sprintf(__('%1$s key validated. Found %2$d model(s).', 'opentrust'), $adapter->label(), count($result['models']));
        $this->ai_notice('success', $count_msg);
        $this->redirect_to_ai_tab();
    }

    public function handle_ai_forget_key(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_forget_key');

        $provider = isset($_POST['provider']) ? sanitize_key((string) wp_unslash($_POST['provider'])) : '';

        $adapter = Ettic_OTC_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        // Clear cached model list for this key before forgetting.
        $existing = Ettic_OTC_Chat_Secrets::get($provider);
        if ($existing !== null) {
            $fingerprint = Ettic_OTC_Chat_Secrets::fingerprint($existing);
            delete_transient('opentrust_models_' . $provider . '_' . $fingerprint);
        }

        Ettic_OTC_Chat_Secrets::forget($provider);

        // If the forgotten provider was the active one, disable chat and clear the model.
        $settings = Ettic_OTC::get_settings();
        if (($settings['ai_provider'] ?? '') === $provider) {
            $settings['ai_enabled']  = false;
            $settings['ai_provider'] = '';
            $settings['ai_model']    = '';
            $settings['ai_model_list_cached_at'] = 0;
            Ettic_OTC_Admin_Settings::instance()->save_settings_raw($settings);
        }

        $this->ai_notice('success', __('Key removed.', 'opentrust'));
        $this->redirect_to_ai_tab();
    }

    public function handle_ai_refresh_models(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_refresh_models');

        $provider = isset($_GET['provider']) ? sanitize_key((string) wp_unslash($_GET['provider'])) : '';
        $adapter  = Ettic_OTC_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $api_key = Ettic_OTC_Chat_Secrets::get($provider);
        if ($api_key === null) {
            $this->ai_notice('error', __('No key on file for this provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $result = $this->refresh_provider_cache($provider, $api_key);
        if (empty($result['ok'])) {
            $error = $result['error'] ?? __('Refresh failed.', 'opentrust');
            /* translators: %s: error message from the provider */
            $this->ai_notice('error', sprintf(__('Refresh failed: %s', 'opentrust'), $error));
            $this->redirect_to_ai_tab();
        }

        $settings = Ettic_OTC::get_settings();
        $settings['ai_model_list_cached_at'] = time();
        $this->snapshot_active_model($settings, $result['models']);
        Ettic_OTC_Admin_Settings::instance()->save_settings_raw($settings);

        /* translators: %d: number of models */
        $this->ai_notice('success', sprintf(__('Model list refreshed. Found %d model(s).', 'opentrust'), count($result['models'])));
        $this->redirect_to_ai_tab();
    }

    /**
     * Backfill missing AI summaries across every published policy in one go.
     * Triggered by the "Generate now" button on the AI Chat settings tab.
     *
     * Each policy is enqueued via wp_schedule_single_event with a 2-second
     * stagger so we don't hammer the AI provider in parallel. The next
     * admin page load will see fewer (or zero) missing summaries.
     */
    public function handle_ai_summarize_sweep(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_summarize_sweep');

        $count = 0;
        if (class_exists('Ettic_OTC_Chat_Summarizer')) {
            $count = Ettic_OTC_Chat_Summarizer::sweep_all();
        }

        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            [
                'type'    => 'success',
                'message' => $count > 0
                    ? sprintf(
                        /* translators: %d is the number of policies enqueued for summary generation. */
                        _n(
                            'Queued %d policy for AI summary generation. Summaries will appear over the next minute.',
                            'Queued %d policies for AI summary generation. Summaries will appear over the next few minutes.',
                            $count,
                            'opentrust'
                        ),
                        (int) $count
                    )
                    : __('All policies already have up-to-date AI summaries.', 'opentrust'),
            ],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust&tab=ai'));
        exit;
    }

    private function ai_notice(string $type, string $message): void {
        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => $type, 'message' => $message],
            MINUTE_IN_SECONDS
        );
    }

    private function redirect_to_ai_tab(): never {
        wp_safe_redirect(admin_url('admin.php?page=opentrust&tab=ai'));
        exit;
    }

    // ──────────────────────────────────────────────
    // Cron — daily model-list refresh
    // ──────────────────────────────────────────────

    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Daily cron: refresh every stored provider's model list, re-snapshot
     * the active provider's selected-model metadata. Failures are logged
     * and skipped per-provider so one bad provider doesn't poison the
     * others.
     */
    public static function cron_refresh_all_providers(): void {
        $stored_keys = Ettic_OTC_Chat_Secrets::get_all();
        if (empty($stored_keys)) {
            return;
        }

        $self     = self::instance();
        $settings = Ettic_OTC::get_settings();
        $active   = (string) ($settings['ai_provider'] ?? '');
        $log      = static fn(string $slug, string $why) => Ettic_OTC::debug_log("AI model refresh failed for {$slug}: {$why}");
        $dirty    = false;

        foreach ($stored_keys as $slug => $api_key) {
            try {
                $result = $self->refresh_provider_cache($slug, $api_key);
            } catch (\Throwable $e) {
                $log($slug, $e->getMessage());
                continue;
            }
            if (empty($result['ok'])) {
                $log($slug, (string) ($result['error'] ?? 'unknown'));
                continue;
            }
            if ($slug === $active) {
                $settings['ai_model_list_cached_at'] = time();
                $self->snapshot_active_model($settings, $result['models']);
                $dirty = true;
            }
        }

        if ($dirty) {
            Ettic_OTC_Admin_Settings::instance()->save_settings_raw($settings);
        }
    }
}

// Wire the cron hook at file load so it fires regardless of admin context.
add_action(Ettic_OTC_Admin_AI::CRON_HOOK, [Ettic_OTC_Admin_AI::class, 'cron_refresh_all_providers']);
