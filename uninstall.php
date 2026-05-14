<?php
/**
 * OpenTrust uninstall routine.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * through the WordPress admin.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope, this file runs standalone

global $wpdb;

// Delete all OpenTrust post types and their meta.
//
// uninstall.php is intentionally self-contained — WordPress invokes it without
// loading the rest of the plugin, so we cannot reference OpenTrust_CPT::ALL
// here. The list below MUST stay in sync with that constant; if a CPT is
// added or renamed there, mirror the change here.
$ot_post_types = ['ot_policy', 'ot_subprocessor', 'ot_certification', 'ot_data_practice', 'ot_faq'];

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
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}opentrust_chat_log");

// Unschedule chat log purge cron.
$ot_timestamp = wp_next_scheduled('opentrust_chat_log_purge');
if ($ot_timestamp) {
    wp_unschedule_event($ot_timestamp, 'opentrust_chat_log_purge');
}

// Unschedule AI model-list refresh cron.
wp_clear_scheduled_hook('opentrust_ai_models_refresh');

// Clear any pending policy-summary single-events. Pending events would otherwise
// fire post-uninstall and fatal because the OpenTrust_Chat_Summarizer class is
// gone — wp_clear_scheduled_hook() removes every scheduled occurrence.
wp_clear_scheduled_hook('opentrust_generate_policy_summary');

// Clear any pending import-preview cleanup single-events. The handler lives on
// OpenTrust_Admin_Tools, which won't load post-uninstall.
wp_clear_scheduled_hook('opentrust_io_preview_cleanup');

// Delete plugin options.
delete_option('opentrust_settings');
delete_option('opentrust_provider_keys');
delete_option('opentrust_db_version');
delete_option('opentrust_cache_version');
delete_option('opentrust_faqs_seeded');

// Clean up any transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bulk cleanup of plugin transients on uninstall, no user input
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_opentrust_%' OR option_name LIKE '_transient_timeout_opentrust_%'");

// Sweep the import-stash directory. Pre-1.1.0 installs created files directly
// under wp-content/uploads/opentrust-tmp/. From 1.1.0 onward wp_handle_upload()
// (scoped via an upload_dir filter) also lands here, so the runtime path is
// the same as the legacy path. Walk depth-first to cover any nested layout —
// the legacy structure was flat in practice but we cannot rely on that, and
// orphaned import-preview ZIPs may have collected here over time.
$ot_uploads = wp_upload_dir();
if (!empty($ot_uploads['basedir'])) {
    $ot_stash = rtrim((string) $ot_uploads['basedir'], '/') . '/opentrust-tmp';
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
