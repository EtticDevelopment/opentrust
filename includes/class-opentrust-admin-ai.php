<?php
/**
 * AI Chat settings tab and its admin-post handlers.
 *
 * Owns the entire "AI Chat" surface inside the OpenTrust settings page:
 * the provider picker (Anthropic primary, others behind an "advanced"
 * disclosure), the per-provider key card with validate-and-save flow,
 * the post-key model picker + budget/limit form, and the four
 * admin-post.php endpoints that drive key save/forget/refresh and the
 * summary-backfill sweep.
 *
 * Bootstrapped by OpenTrust_Admin's constructor; subscribes its own
 * admin_post_* hooks. The settings page (which still lives on
 * OpenTrust_Admin as the menu callback) calls render_ai_tab() when the
 * "ai" tab is active.
 *
 * Settings writes that bypass the sanitize_settings filter (key
 * validation flips ai_enabled / ai_provider / ai_model_list_cached_at)
 * route through OpenTrust_Admin_Settings::save_settings_raw().
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Admin_AI {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_post_opentrust_ai_save_key',        [$this, 'handle_ai_save_key']);
        add_action('admin_post_opentrust_ai_forget_key',      [$this, 'handle_ai_forget_key']);
        add_action('admin_post_opentrust_ai_refresh_models',  [$this, 'handle_ai_refresh_models']);
        add_action('admin_post_opentrust_ai_summarize_sweep', [$this, 'handle_ai_summarize_sweep']);
    }

    // ──────────────────────────────────────────────
    // AI tab — rendering
    // ──────────────────────────────────────────────

    public function render_ai_tab(array $settings): void {
        $stored_keys     = OpenTrust_Chat_Secrets::get_all();
        $active_provider = $settings['ai_provider'] ?? '';
        $has_active_key  = $active_provider !== '' && isset($stored_keys[$active_provider]);
        $is_non_anthropic_active = $has_active_key && $active_provider !== 'anthropic';

        // Transient notice from the admin-post handlers.
        $notice = get_transient('opentrust_ai_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('opentrust_ai_notice_' . get_current_user_id());
            $variant = $notice['type'] === 'error' ? 'error' : 'success';
            $this->ds_notice($variant, (string) ($notice['message'] ?? ''));
        }

        $this->render_summary_backfill_banner($settings, $has_active_key);

        if ($is_non_anthropic_active):
            $intro = sprintf(
                /* translators: %s: provider label, e.g. OpenAI */
                __('You are currently using %s. Only Anthropic uses a structural Citations API — every other provider relies on prompted citation tags the model can ignore or fabricate. For a published trust center, switch to Anthropic below.', 'opentrust'),
                ucfirst($active_provider)
            );
            ?>
            <div class="opentrust-notice opentrust-notice--warn" role="alert">
                <div class="opentrust-notice__body">
                    <strong><?php esc_html_e('Heads up: citation fidelity is not guaranteed on your active provider.', 'opentrust'); ?></strong>
                    <p><?php echo esc_html($intro); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Citation-backed AI assistant', 'opentrust'); ?></h2>
                <p>
                    <?php
                    echo wp_kses(
                        __('OpenTrust uses <strong>Anthropic Claude with the native Citations API</strong> to answer visitor questions about your trust center. Every claim the assistant makes is tied to an exact quote from one of your published documents — no policy text is invented, nothing is paraphrased into something you did not publish.', 'opentrust'),
                        ['strong' => []]
                    );
                    ?>
                </p>
            </header>
            <div class="opentrust-card">
                <details class="opentrust-disclosure">
                    <summary><?php esc_html_e('Why Anthropic, and not OpenAI or another provider?', 'opentrust'); ?></summary>
                    <div class="opentrust-disclosure__body">
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
                            <?php esc_html_e('Every other provider (including OpenAI and any model accessed via OpenRouter) relies on prompted citation tags that we parse out of the answer after the fact. That works most of the time, but the model can ignore the instructions, make up document IDs, or attach a citation to a sentence it actually hallucinated. We support these providers as an escape hatch for organisations that cannot use Anthropic for procurement or data-residency reasons — but we very strongly recommend you do not run a public trust center on them.', 'opentrust'); ?>
                        </p>
                    </div>
                </details>
            </div>
        </section>

        <?php $this->render_ai_provider_picker($settings, $stored_keys); ?>

        <?php if ($has_active_key): ?>
            <?php $this->render_ai_settings_form($settings); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Emit a design system notice. Variants: info | success | warn | error.
     */
    private function ds_notice(string $variant, string $message, string $heading = ''): void {
        $allowed = ['info', 'success', 'warn', 'error'];
        $variant = in_array($variant, $allowed, true) ? $variant : 'info';
        ?>
        <div class="opentrust-notice opentrust-notice--<?php echo esc_attr($variant); ?>" role="status">
            <div class="opentrust-notice__body">
                <?php if ($heading !== ''): ?>
                    <strong><?php echo esc_html($heading); ?></strong>
                <?php endif; ?>
                <p><?php echo esc_html($message); ?></p>
            </div>
        </div>
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
        if (!$has_active_key || empty($settings['ai_auto_summarize']) || !class_exists('OpenTrust_Chat_Summarizer')) {
            return;
        }
        $missing = OpenTrust_Chat_Summarizer::missing_summary_count();
        if ($missing < 1) {
            return;
        }
        $heading = sprintf(
            /* translators: %d is the number of policies missing AI summaries. */
            _n(
                '%d policy is missing an AI summary.',
                '%d policies are missing AI summaries.',
                $missing,
                'opentrust'
            ),
            (int) $missing
        );
        ?>
        <div class="opentrust-notice opentrust-notice--warn" role="status">
            <div class="opentrust-notice__body">
                <strong><?php echo esc_html($heading); ?></strong>
                <p><?php esc_html_e('Generate them now so the assistant can route questions accurately.', 'opentrust'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="opentrust-notice__actions">
                    <?php wp_nonce_field('opentrust_ai_summarize_sweep'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_summarize_sweep">
                    <button type="submit" class="opentrust-btn opentrust-btn--primary opentrust-btn--sm"><?php esc_html_e('Generate now', 'opentrust'); ?></button>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_ai_provider_picker(array $settings, array $stored_keys): void {
        $providers       = OpenTrust_Chat_Provider::available();
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
            ?>
            <section class="opentrust-block">
                <header class="opentrust-block__head">
                    <h2><?php esc_html_e('Connect a provider', 'opentrust'); ?></h2>
                    <p><?php esc_html_e('Anthropic is not registered. Pick any available provider to continue.', 'opentrust'); ?></p>
                </header>
                <div class="opentrust-ai-advanced__grid">
                    <?php foreach ($providers as $provider): ?>
                        <?php $this->render_provider_card($provider, $stored_keys, $active_provider, 'advanced'); ?>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php
            return;
        }

        $advanced_open = $active_provider !== '' && $active_provider !== 'anthropic';
        ?>
        <section class="opentrust-block">
            <header class="opentrust-block__head">
                <h2><?php esc_html_e('Step 1 — Connect Anthropic', 'opentrust'); ?></h2>
                <p><?php esc_html_e('Paste your Anthropic API key. We validate it on save and cache the model list for routing.', 'opentrust'); ?></p>
            </header>
            <?php $this->render_provider_card($primary, $stored_keys, $active_provider, 'primary'); ?>

            <?php if (!empty($advanced)): ?>
                <details class="opentrust-disclosure opentrust-ai-advanced"<?php echo $advanced_open ? ' open' : ''; ?>>
                    <summary><?php esc_html_e('Advanced: use a different provider (not recommended)', 'opentrust'); ?></summary>
                    <div class="opentrust-disclosure__body">
                        <div class="opentrust-notice opentrust-notice--warn" role="alert">
                            <div class="opentrust-notice__body">
                                <strong><?php esc_html_e('These providers cannot guarantee citation fidelity.', 'opentrust'); ?></strong>
                                <p><?php esc_html_e('OpenAI and OpenRouter rely on prompted [[cite:document-id]] tags that we parse out of the answer after generation. The model can ignore the instruction, invent document IDs, or attach a citation to a sentence it actually hallucinated. We cannot detect when this happens.', 'opentrust'); ?></p>
                                <p>
                                    <strong><?php esc_html_e('Do not use these providers for a published trust center', 'opentrust'); ?></strong>
                                    <?php esc_html_e('unless your organisation genuinely cannot use Anthropic for procurement, contractual, or data-residency reasons. Inaccurate claims about your security posture are a real compliance risk.', 'opentrust'); ?>
                                </p>
                            </div>
                        </div>
                        <div class="opentrust-ai-advanced__grid">
                            <?php foreach ($advanced as $provider): ?>
                                <?php $this->render_provider_card($provider, $stored_keys, $active_provider, 'advanced'); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </section>
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
        $masked    = $has_key ? OpenTrust_Chat_Secrets::mask($stored_keys[$slug]) : '';

        $card_classes = ['opentrust-card', 'opentrust-ai-card', 'opentrust-ai-card--' . $variant];
        if ($is_active) {
            $card_classes[] = 'is-active';
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
            <div class="opentrust-ai-card__header">
                <h3 class="opentrust-ai-card__title"><?php echo esc_html($label); ?></h3>
                <?php if ($variant === 'primary'): ?>
                    <span class="opentrust-ai-card__badge"><?php esc_html_e('Required for citation fidelity', 'opentrust'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($variant === 'primary'): ?>
                <p class="opentrust-ai-card__description">
                    <?php esc_html_e('Uses Claude with the native Citations API. Every quote the assistant attributes to one of your documents is structurally guaranteed to come from that document.', 'opentrust'); ?>
                </p>
            <?php endif; ?>

            <p class="opentrust-ai-card__keylink">
                <a href="<?php echo esc_url($key_url); ?>" target="_blank" rel="noopener">
                    <?php
                    /* translators: %s: provider name (e.g. Anthropic) */
                    printf(esc_html__('Get a %s API key', 'opentrust'), esc_html($label));
                    ?> &rarr;
                </a>
            </p>

            <?php if ($has_key && $is_active): ?>
                <div class="opentrust-ai-card__saved">
                    <span class="opentrust-ai-card__check" aria-hidden="true">
                        <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2.5 6 5 8.5 9.5 4"/></svg>
                    </span>
                    <code><?php echo esc_html($masked); ?></code>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="opentrust-ai-card__form">
                    <?php wp_nonce_field('opentrust_ai_forget_key'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_forget_key">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                    <button type="submit" class="opentrust-btn opentrust-btn--text" onclick="return confirm('<?php echo esc_js(__('Remove the saved key for this provider?', 'opentrust')); ?>')">
                        <?php esc_html_e('Replace key', 'opentrust'); ?>
                    </button>
                </form>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="opentrust-ai-card__form">
                    <?php wp_nonce_field('opentrust_ai_save_key'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_save_key">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                    <input type="password" name="api_key" class="opentrust-input opentrust-input--md" autocomplete="off" placeholder="<?php echo esc_attr(sprintf(
                        /* translators: %s: provider name (e.g. Anthropic) */
                        __('Paste your %s API key…', 'opentrust'),
                        $label
                    )); ?>" required>
                    <button type="submit" class="opentrust-btn opentrust-btn--primary">
                        <?php esc_html_e('Validate &amp; save', 'opentrust'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_ai_settings_form(array $settings): void {
        $active_provider = (string) $settings['ai_provider'];
        $models          = $this->get_cached_model_list($active_provider);
        $current_model   = (string) ($settings['ai_model'] ?? '');
        $refresh_url     = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_refresh_models&provider=' . rawurlencode($active_provider)),
            'opentrust_ai_refresh_models'
        );
        $cached_at       = (int) ($settings['ai_model_list_cached_at'] ?? 0);
        $oversized       = class_exists('OpenTrust_Chat_Corpus') ? OpenTrust_Chat_Corpus::oversized_policies() : [];
        $secret_saved    = !empty($settings['turnstile_secret_key']);
        ?>
        <form id="opentrust-settings-form" method="post" action="options.php">
            <?php settings_fields('opentrust_settings_group'); ?>
            <?php // Sentinel so sanitize_settings knows the AI tab is submitting. The
                  // sanitize callback carries every non-AI key forward from $old, so we
                  // do NOT need to re-POST values from other tabs as hidden inputs. ?>
            <input type="hidden" name="opentrust_settings[__ai_tab_save]" value="1">

            <section class="opentrust-block">
                <header class="opentrust-block__head">
                    <h2><?php esc_html_e('Step 2 — Model &amp; defaults', 'opentrust'); ?></h2>
                    <p><?php esc_html_e('Pick a model and tune budgets, rate limits, and visitor-facing behavior.', 'opentrust'); ?></p>
                </header>
                <div class="opentrust-card">
                    <div class="opentrust-row opentrust-row--stacked">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Active model', 'opentrust'); ?></span>
                            <?php if ($cached_at > 0): ?>
                                <p class="opentrust-row__help">
                                    <?php
                                    /* translators: %s: human-readable time difference (e.g. "5 minutes") */
                                    printf(esc_html__('Model list cached %s ago.', 'opentrust'), esc_html(human_time_diff($cached_at)));
                                    ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="opentrust-row__control opentrust-row__control--stack">
                            <div class="opentrust-ai-model-row">
                                <?php if (empty($models)): ?>
                                    <p class="opentrust-field-msg opentrust-field-msg--error">
                                        <?php esc_html_e('No cached models found. Use Refresh to re-fetch the model list.', 'opentrust'); ?>
                                    </p>
                                <?php else: ?>
                                    <div class="opentrust-select">
                                        <select id="opentrust_ai_model" name="opentrust_settings[ai_model]">
                                            <?php foreach ($models as $model): ?>
                                                <option value="<?php echo esc_attr((string) $model['id']); ?>" <?php selected($current_model, (string) $model['id']); ?>>
                                                    <?php echo esc_html((string) $model['display_name']); ?>
                                                    <?php if (!empty($model['recommended'])): ?>
                                                        — <?php esc_html_e('Recommended', 'opentrust'); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($refresh_url); ?>" class="opentrust-btn opentrust-btn--ghost opentrust-btn--sm">
                                    <?php esc_html_e('Refresh models', 'opentrust'); ?>
                                </a>
                            </div>
                        </div>
                    </div>

                    <?php $this->ds_row_number('ai_daily_token_budget', __('Daily token budget', 'opentrust'), (int) ($settings['ai_daily_token_budget'] ?? OpenTrust_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET), 0, 100000000, 10000, __('tokens', 'opentrust'), __('Hard cap per site per day. Default 500,000 (~$12/day at Sonnet 4.5 rates).', 'opentrust')); ?>

                    <?php $this->ds_row_number('ai_monthly_token_budget', __('Monthly token budget', 'opentrust'), (int) ($settings['ai_monthly_token_budget'] ?? OpenTrust_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET), 0, 1000000000, 100000, __('tokens', 'opentrust'), __('Hard cap per site per month. Default 10,000,000.', 'opentrust')); ?>

                    <?php $this->ds_row_number('ai_rate_limit_per_ip', __('Rate limit — per IP', 'opentrust'), (int) ($settings['ai_rate_limit_per_ip'] ?? 10), 0, 1000, 1, __('messages per minute', 'opentrust')); ?>

                    <?php $this->ds_row_number('ai_rate_limit_per_session', __('Rate limit — per session', 'opentrust'), (int) ($settings['ai_rate_limit_per_session'] ?? 50), 0, 10000, 1, __('messages per hour', 'opentrust')); ?>

                    <?php $this->ds_row_number('ai_max_message_length', __('Max message length', 'opentrust'), (int) ($settings['ai_max_message_length'] ?? OpenTrust_Chat::DEFAULT_MAX_MESSAGE_LENGTH), 100, 4000, 100, __('characters', 'opentrust')); ?>

                    <div class="opentrust-row">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Refuse-to-answer contact URL', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('When the AI cannot confidently answer, it links here. Leave blank to use the trust center home.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control">
                            <input type="url" class="opentrust-input opentrust-input--md" id="opentrust_ai_contact_url" name="opentrust_settings[ai_contact_url]" value="<?php echo esc_attr((string) ($settings['ai_contact_url'] ?? '')); ?>" placeholder="https://example.com/contact" inputmode="url" autocomplete="off">
                        </div>
                    </div>

                    <?php $this->ds_row_toggle('ai_show_model_attribution', __('Visitor display', 'opentrust'), !empty($settings['ai_show_model_attribution']), __('Show the active model name under the chat input.', 'opentrust')); ?>

                    <?php $this->ds_row_toggle('ai_logging_enabled', __('Analytics logging', 'opentrust'), !empty($settings['ai_logging_enabled']), __('Log anonymised visitor questions for admin review (90-day auto-purge, no PII).', 'opentrust')); ?>

                    <?php $this->ds_row_toggle('ai_auto_summarize', __('Improve answer quality', 'opentrust'), !empty($settings['ai_auto_summarize']), __('Auto-generate a 2–3 sentence AI summary of each policy for routing. Cost ~$0.05–$0.10 per 50 policies lifetime; pennies per edit afterward. Uses your configured AI key.', 'opentrust')); ?>
                </div>
            </section>

            <?php if (!empty($oversized)): ?>
                <div class="opentrust-notice opentrust-notice--error" role="alert">
                    <div class="opentrust-notice__body">
                        <strong><?php esc_html_e('Oversized policies', 'opentrust'); ?></strong>
                        <p><?php esc_html_e('The following policies will be truncated when retrieved by the AI. Consider splitting them into shorter documents:', 'opentrust'); ?></p>
                        <ul class="opentrust-notice__list">
                            <?php foreach ($oversized as $row): ?>
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
                </div>
            <?php endif; ?>

            <section class="opentrust-block">
                <header class="opentrust-block__head">
                    <h2><?php esc_html_e('Anti-abuse — Cloudflare Turnstile', 'opentrust'); ?></h2>
                    <p><?php esc_html_e('Optional. Turnstile challenges suspicious visitors on their first chat message of a session. Requires a free Cloudflare account.', 'opentrust'); ?></p>
                </header>
                <div class="opentrust-card">
                    <?php $this->ds_row_toggle('ai_turnstile_enabled', __('Enable Turnstile for chat', 'opentrust'), !empty($settings['ai_turnstile_enabled']), __('Require Turnstile verification on first chat message.', 'opentrust')); ?>

                    <div class="opentrust-row">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Turnstile Site Key', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('Public site key from your Cloudflare Turnstile widget.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control">
                            <input type="text" class="opentrust-input opentrust-input--md opentrust-input--mono" id="opentrust_turnstile_site_key" name="opentrust_settings[turnstile_site_key]" value="<?php echo esc_attr((string) ($settings['turnstile_site_key'] ?? '')); ?>">
                        </div>
                    </div>

                    <div class="opentrust-row">
                        <div class="opentrust-row__main">
                            <span class="opentrust-row__label"><?php esc_html_e('Turnstile Secret Key', 'opentrust'); ?></span>
                            <p class="opentrust-row__help"><?php esc_html_e('Stored server-side, encrypted via libsodium. Never exposed to the frontend.', 'opentrust'); ?></p>
                        </div>
                        <div class="opentrust-row__control opentrust-row__control--stack">
                            <input type="password" class="opentrust-input opentrust-input--md opentrust-input--mono" id="opentrust_turnstile_secret_key" name="opentrust_settings[turnstile_secret_key]" value="<?php echo esc_attr($secret_saved ? str_repeat('•', 20) : ''); ?>" autocomplete="off" placeholder="<?php esc_attr_e('Enter secret key…', 'opentrust'); ?>">
                            <?php if ($secret_saved): ?>
                                <span class="opentrust-field-msg opentrust-field-msg--success">
                                    <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><polyline points="2.5 6 5 8.5 9.5 4"/></svg>
                                    <?php esc_html_e('Key saved', 'opentrust'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </form>
        <?php
    }

    /**
     * Design system helpers used by render_ai_settings_form. Kept locally
     * because OpenTrust_Admin_Settings::ds_row_* are private; once a common
     * "design system helper" surface is needed, lift the shared variants
     * into a trait or a static helper class.
     */
    private function ds_row_number(string $key, string $label, int $value, int $min, int $max, int $step, string $unit_label, string $help = ''): void {
        $name = sprintf('opentrust_settings[%s]', $key);
        ?>
        <div class="opentrust-row">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control">
                <input type="number" class="opentrust-input opentrust-input--num" id="<?php echo esc_attr('opentrust_' . $key); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" min="<?php echo esc_attr((string) $min); ?>" max="<?php echo esc_attr((string) $max); ?>" step="<?php echo esc_attr((string) $step); ?>">
                <span class="opentrust-row__unit"><?php echo esc_html($unit_label); ?></span>
            </div>
        </div>
        <?php
    }

    private function ds_row_toggle(string $key, string $label, bool $checked, string $help = ''): void {
        $name = sprintf('opentrust_settings[%s]', $key);
        ?>
        <div class="opentrust-row">
            <div class="opentrust-row__main">
                <span class="opentrust-row__label"><?php echo esc_html($label); ?></span>
                <?php if ($help !== ''): ?>
                    <p class="opentrust-row__help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
            </div>
            <div class="opentrust-row__control">
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
                <label class="opentrust-toggle">
                    <input class="opentrust-toggle__input" type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?>>
                    <span class="opentrust-toggle__thumb"></span>
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Read the cached model list for a provider. Returns an empty array if missing.
     */
    private function get_cached_model_list(string $provider): array {
        if ($provider === '') {
            return [];
        }
        $stored_keys = OpenTrust_Chat_Secrets::get_all();
        if (!isset($stored_keys[$provider])) {
            return [];
        }
        $fingerprint = OpenTrust_Chat_Secrets::fingerprint($stored_keys[$provider]);
        $cached      = get_transient('opentrust_models_' . $provider . '_' . $fingerprint);
        return is_array($cached) && isset($cached['models']) && is_array($cached['models'])
            ? $cached['models']
            : [];
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

        $adapter = OpenTrust_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }
        if ($api_key === '') {
            $this->ai_notice('error', __('API key cannot be empty.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $result = $adapter->validate_and_list_models($api_key);

        if (empty($result['ok'])) {
            $error = $result['error'] ?? __('Validation failed.', 'opentrust');
            /* translators: 1: provider label, 2: provider error message */
            $msg = sprintf(__('%1$s rejected the key: %2$s', 'opentrust'), $adapter->label(), $error);
            $this->ai_notice('error', $msg);
            $this->redirect_to_ai_tab();
        }

        // Persist the key (encrypted).
        OpenTrust_Chat_Secrets::put($provider, $api_key);

        // Cache the model list keyed by fingerprint, NOT by key.
        $fingerprint = OpenTrust_Chat_Secrets::fingerprint($api_key);
        set_transient(
            'opentrust_models_' . $provider . '_' . $fingerprint,
            ['models' => $result['models'], 'fetched_at' => time()],
            24 * HOUR_IN_SECONDS
        );

        // Update settings: mark AI enabled, record provider + cache timestamp,
        // and if no model is selected yet, pre-pick the first recommended model.
        $settings = OpenTrust::get_settings();
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
        OpenTrust_Admin_Settings::instance()->save_settings_raw($settings);

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

        $adapter = OpenTrust_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        // Clear cached model list for this key before forgetting.
        $existing = OpenTrust_Chat_Secrets::get($provider);
        if ($existing !== null) {
            $fingerprint = OpenTrust_Chat_Secrets::fingerprint($existing);
            delete_transient('opentrust_models_' . $provider . '_' . $fingerprint);
        }

        OpenTrust_Chat_Secrets::forget($provider);

        // If the forgotten provider was the active one, disable chat and clear the model.
        $settings = OpenTrust::get_settings();
        if (($settings['ai_provider'] ?? '') === $provider) {
            $settings['ai_enabled']  = false;
            $settings['ai_provider'] = '';
            $settings['ai_model']    = '';
            $settings['ai_model_list_cached_at'] = 0;
            OpenTrust_Admin_Settings::instance()->save_settings_raw($settings);
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
        $adapter  = OpenTrust_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $api_key = OpenTrust_Chat_Secrets::get($provider);
        if ($api_key === null) {
            $this->ai_notice('error', __('No key on file for this provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $result = $adapter->validate_and_list_models($api_key);
        if (empty($result['ok'])) {
            $error = $result['error'] ?? __('Refresh failed.', 'opentrust');
            /* translators: %s: error message from the provider */
            $this->ai_notice('error', sprintf(__('Refresh failed: %s', 'opentrust'), $error));
            $this->redirect_to_ai_tab();
        }

        $fingerprint = OpenTrust_Chat_Secrets::fingerprint($api_key);
        set_transient(
            'opentrust_models_' . $provider . '_' . $fingerprint,
            ['models' => $result['models'], 'fetched_at' => time()],
            24 * HOUR_IN_SECONDS
        );

        $settings = OpenTrust::get_settings();
        $settings['ai_model_list_cached_at'] = time();
        OpenTrust_Admin_Settings::instance()->save_settings_raw($settings);

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
        if (class_exists('OpenTrust_Chat_Summarizer')) {
            $count = OpenTrust_Chat_Summarizer::sweep_all();
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
}
