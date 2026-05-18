<?php
/**
 * Data-practice catalog.
 *
 * Templates for common data-practice categories. Used by the admin typeahead
 * on the eotc_data_practice create screen. Every entry is a starting point —
 * users must review legal basis, retention, and shared-with lists before
 * publishing. All template fields live under `fields_review` so the UI marks
 * them as "verify before publishing".
 *
 * Extend without forking via the `ettic_otc_data_practice_catalog` filter.
 *
 * Legal basis values must match keys in Ettic_OTC_Render::legal_basis_labels().
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [

	'website-analytics' => [
		'name'    => 'Website Analytics',
		'aliases' => [ 'analytics', 'web analytics', 'site analytics', 'ga', 'google analytics' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Measure website traffic, understand visitor behavior, and improve content and navigation.',
			'_ettic_otc_dp_legal_basis'      => 'legitimate_interest',
			'_ettic_otc_dp_retention_period' => '14 months',
			'_ettic_otc_dp_data_items'       => [ 'IP address', 'Browser and device', 'Page views', 'Referrer', 'Session duration' ],
			'_ettic_otc_dp_shared_with'      => [ 'Analytics provider' ],
		],
	],

	'product-telemetry' => [
		'name'    => 'Product Telemetry',
		'aliases' => [ 'telemetry', 'product analytics', 'usage analytics', 'events' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Track in-product feature usage to prioritize improvements and diagnose problems.',
			'_ettic_otc_dp_legal_basis'      => 'legitimate_interest',
			'_ettic_otc_dp_retention_period' => '24 months',
			'_ettic_otc_dp_data_items'       => [ 'User ID', 'Feature events', 'Session metadata', 'Device and OS' ],
			'_ettic_otc_dp_shared_with'      => [ 'Product analytics provider' ],
		],
	],

	'error-monitoring' => [
		'name'    => 'Error Monitoring',
		'aliases' => [ 'error tracking', 'crash reporting', 'sentry', 'bug tracking', 'exceptions' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Detect and diagnose application errors to maintain service reliability and security.',
			'_ettic_otc_dp_legal_basis'      => 'legitimate_interest',
			'_ettic_otc_dp_retention_period' => '90 days',
			'_ettic_otc_dp_data_items'       => [ 'Stack trace', 'User ID', 'Request URL', 'Browser and device' ],
			'_ettic_otc_dp_shared_with'      => [ 'Error monitoring provider' ],
		],
	],

	'transactional-email' => [
		'name'    => 'Transactional Email',
		'aliases' => [ 'email', 'service email', 'notifications', 'receipts', 'password reset' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Send account-related and service-related emails such as receipts, password resets, and notifications.',
			'_ettic_otc_dp_legal_basis'      => 'contract',
			'_ettic_otc_dp_retention_period' => 'Duration of account',
			'_ettic_otc_dp_data_items'       => [ 'Email address', 'Name', 'Email content' ],
			'_ettic_otc_dp_shared_with'      => [ 'Email delivery provider' ],
		],
	],

	'marketing-email' => [
		'name'    => 'Marketing Email',
		'aliases' => [ 'newsletter', 'marketing', 'promotional email', 'email marketing' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Send product updates, newsletters, and promotional messages to users who have opted in.',
			'_ettic_otc_dp_legal_basis'      => 'consent',
			'_ettic_otc_dp_retention_period' => 'Until unsubscribe',
			'_ettic_otc_dp_data_items'       => [ 'Email address', 'Name', 'Subscription preferences' ],
			'_ettic_otc_dp_shared_with'      => [ 'Email marketing provider' ],
		],
	],

	'customer-support' => [
		'name'    => 'Customer Support',
		'aliases' => [ 'support', 'helpdesk', 'help desk', 'tickets', 'customer service' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Respond to customer inquiries and maintain a record of support interactions.',
			'_ettic_otc_dp_legal_basis'      => 'contract',
			'_ettic_otc_dp_retention_period' => '3 years after case closure',
			'_ettic_otc_dp_data_items'       => [ 'Name', 'Email address', 'Message contents', 'Account ID' ],
			'_ettic_otc_dp_shared_with'      => [ 'Support platform provider' ],
		],
	],

	'payment-processing' => [
		'name'    => 'Payment Processing',
		'aliases' => [ 'payments', 'billing', 'stripe', 'subscriptions', 'checkout' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Process payments for subscriptions or one-time purchases and manage billing records.',
			'_ettic_otc_dp_legal_basis'      => 'contract',
			'_ettic_otc_dp_retention_period' => '7 years (tax and accounting obligation)',
			'_ettic_otc_dp_data_items'       => [ 'Name', 'Billing address', 'Email address', 'Payment method (tokenized)', 'Transaction history' ],
			'_ettic_otc_dp_shared_with'      => [ 'Payment processor' ],
		],
	],

	'account-authentication' => [
		'name'    => 'Account & Authentication',
		'aliases' => [ 'auth', 'login', 'sign in', 'signup', 'account', 'session' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Create and maintain user accounts, authenticate sign-in attempts, and secure sessions.',
			'_ettic_otc_dp_legal_basis'      => 'contract',
			'_ettic_otc_dp_retention_period' => 'Duration of account + 30 days',
			'_ettic_otc_dp_data_items'       => [ 'Email address', 'Password (hashed)', 'Session tokens', 'Last sign-in IP' ],
			'_ettic_otc_dp_shared_with'      => [ 'Authentication provider' ],
		],
	],

	'session-replay' => [
		'name'    => 'Session Replay',
		'aliases' => [ 'session recording', 'replay', 'fullstory', 'logrocket', 'heatmaps' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Record anonymized user sessions to diagnose usability issues and improve the product.',
			'_ettic_otc_dp_legal_basis'      => 'legitimate_interest',
			'_ettic_otc_dp_retention_period' => '30 days',
			'_ettic_otc_dp_data_items'       => [ 'Mouse movements', 'Click events', 'Page navigation', 'Screen size' ],
			'_ettic_otc_dp_shared_with'      => [ 'Session replay provider' ],
		],
	],

	'crm-records' => [
		'name'    => 'CRM & Contact Records',
		'aliases' => [ 'crm', 'contacts', 'hubspot', 'salesforce', 'pipedrive' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Manage customer relationships and track sales and account interactions.',
			'_ettic_otc_dp_legal_basis'      => 'legitimate_interest',
			'_ettic_otc_dp_retention_period' => 'Duration of relationship + 3 years',
			'_ettic_otc_dp_data_items'       => [ 'Name', 'Email address', 'Company', 'Job title', 'Interaction history' ],
			'_ettic_otc_dp_shared_with'      => [ 'CRM provider' ],
		],
	],

	'security-logging' => [
		'name'    => 'Security & Audit Logging',
		'aliases' => [ 'audit log', 'security log', 'access log', 'siem' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Detect and investigate security events, prevent abuse, and meet compliance obligations.',
			'_ettic_otc_dp_legal_basis'      => 'legitimate_interest',
			'_ettic_otc_dp_retention_period' => '12 months',
			'_ettic_otc_dp_data_items'       => [ 'User ID', 'IP address', 'Action performed', 'Timestamp', 'User agent' ],
			'_ettic_otc_dp_shared_with'      => [ 'Logging and SIEM providers' ],
		],
	],

	'infrastructure-hosting' => [
		'name'    => 'Infrastructure Hosting',
		'aliases' => [ 'hosting', 'cloud', 'aws', 'gcp', 'server' ],
		'fields'  => [],
		'fields_review' => [
			'_ettic_otc_dp_purpose'          => 'Store and serve application data, run backend services, and deliver the product to users.',
			'_ettic_otc_dp_legal_basis'      => 'contract',
			'_ettic_otc_dp_retention_period' => 'Duration of account',
			'_ettic_otc_dp_data_items'       => [ 'All user-submitted data', 'Account information', 'Application state' ],
			'_ettic_otc_dp_shared_with'      => [ 'Cloud infrastructure provider' ],
		],
	],

];
