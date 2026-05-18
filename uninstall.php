<?php
/**
 * Ettic_OTC uninstall routine.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * through the WordPress admin.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope, this file runs standalone

global $wpdb;

// Delete all Ettic_OTC post types and their meta.
//
// uninstall.php is intentionally self-contained — WordPress invokes it without
// loading the rest of the plugin, so we cannot reference Ettic_OTC_CPT::ALL
// here. The list below MUST stay in sync with that constant; if a CPT is
// added or renamed there, mirror the change here.
$ot_post_types = [
    'eotc_policy',
    'eotc_subprocessor',
    'eotc_certification',
    'eotc_data_practice',
    'eotc_faq',
];

foreach ($ot_post_types as $ot_post_type) {
    $posts = get_posts([
        'post_type'      => $ot_post_type,
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ]);

    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// Drop chat log table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL with dynamic table prefix cannot use prepare()
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ettic_otc_chat_log");

// Unschedule chat log purge cron.
$ot_timestamp = wp_next_scheduled('ettic_otc_chat_log_purge');
if ($ot_timestamp) {
    wp_unschedule_event($ot_timestamp, 'ettic_otc_chat_log_purge');
}

// Unschedule AI model-list refresh cron.
wp_clear_scheduled_hook('ettic_otc_ai_models_refresh');

// Clear any pending policy-summary single-events. Pending events would otherwise
// fire post-uninstall and fatal because the Ettic_OTC_Chat_Summarizer class is
// gone — wp_clear_scheduled_hook() removes every scheduled occurrence.
wp_clear_scheduled_hook('ettic_otc_generate_policy_summary');

// Clear any pending import-preview cleanup single-events. The handler lives on
// Ettic_OTC_Admin_Tools, which won't load post-uninstall.
wp_clear_scheduled_hook('ettic_otc_io_preview_cleanup');

// Delete plugin options.
delete_option('ettic_otc_settings');
delete_option('ettic_otc_provider_keys');
delete_option('ettic_otc_db_version');
delete_option('ettic_otc_cache_version');
delete_option('ettic_otc_faqs_seeded');

// Delete orphaned post and user meta the CPT-post deletion above does not
// reach. The import dedupe marker lives on attachment posts (never an
// Ettic_OTC CPT), and the review-notice dismissal lives in user meta. Both
// the current `_ettic_otc_*` keys and the legacy `_ot_*` key are cleared —
// the latter covers an install removed before the v4→v5 key migration ran.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-shot uninstall cleanup, fixed key list, no user input.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ettic_otc_import_sha256', '_ot_import_sha256')");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-shot uninstall cleanup, fixed key list, no user input.
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_ettic_otc_review_dismissed_v1'");

// Clean up any transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bulk cleanup of plugin transients on uninstall, no user input
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ettic_otc_%' OR option_name LIKE '_transient_timeout_ettic_otc_%'");

// Sweep the import-stash directory. Pre-1.1.0 installs created files directly
// under wp-content/uploads/ettic-otc-tmp/. From 1.1.0 onward wp_handle_upload()
// (scoped via an upload_dir filter) also lands here, so the runtime path is
// the same as the legacy path. Walk depth-first to cover any nested layout —
// the legacy structure was flat in practice but we cannot rely on that, and
// orphaned import-preview ZIPs may have collected here over time.
$ot_uploads = wp_upload_dir();
if (!empty($ot_uploads['basedir'])) {
    $ot_stash = rtrim((string) $ot_uploads['basedir'], '/') . '/ettic-otc-tmp';
    if (is_dir($ot_stash)) {
        try {
            $ot_iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($ot_stash, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($ot_iterator as $ot_entry) {
                /** @var SplFileInfo $ot_entry */
                $ot_path = (string) $ot_entry->getPathname();
                if ($ot_entry->isDir()) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- one-shot uninstall cleanup; WP_Filesystem is disproportionate for tearing down a plugin-private temp dir.
                    @rmdir($ot_path);
                } else {
                    wp_delete_file($ot_path);
                }
            }
        } catch (\Throwable $ot_e) {
            // Best-effort cleanup; never fatal during uninstall.
            unset($ot_e);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- one-shot uninstall cleanup; WP_Filesystem is disproportionate here.
        @rmdir($ot_stash);
    }
}

// Flush rewrite rules.
flush_rewrite_rules();
