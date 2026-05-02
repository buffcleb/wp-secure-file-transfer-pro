<?php
/**
 * Admin panel — menu registration, asset enqueueing, POST handler, and tab dispatcher.
 *
 * The admin panel is restricted to users with `manage_options`. It provides:
 *   Dashboard  — vault/share/file stats at a glance
 *   Vaults     — browse every user's vaults; click into a vault to see its files,
 *                shares, and audit trail; admins can download any file (logged)
 *   Audit Log  — filterable, paginated event log for all plugin activity
 *   Settings   — OTP TTL, max file size, audit retention, and data deletion policy
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Load tab renderers ───────────────────────────────────────────────────────

require_once SFT_DIR . 'admin/tabs/tab-dashboard.php';
require_once SFT_DIR . 'admin/tabs/tab-vaults.php';
require_once SFT_DIR . 'admin/tabs/tab-audit.php';
require_once SFT_DIR . 'admin/tabs/tab-settings.php';
require_once SFT_DIR . 'admin/tabs/tab-users.php';

// ─── POST handler (admin_init — before any HTML output) ───────────────────────

add_action( 'admin_init', 'sft_handle_admin_post' );

function sft_handle_admin_post(): void {
	if ( ! isset( $_POST['sft_nonce'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sft-pro' ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-sft-pro' ) );
	}

	check_admin_referer( 'sft_admin_action', 'sft_nonce' );

	$current_tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );

	// ── Settings save ────────────────────────────────────────────────────────
	if ( isset( $_POST['sft_save_settings'] ) ) {
		// Two-factor.
		$otp_ttl          = max( 5, min( 60, (int) ( $_POST['sft_otp_ttl_minutes'] ?? 15 ) ) );
		$otp_max_attempts = max( 1, min( 20, (int) ( $_POST['sft_otp_max_attempts'] ?? 5 ) ) );

		// Download limits.
		$allow_unlimited_downloads = isset( $_POST['sft_allow_unlimited_downloads'] ) ? '1' : '0';
		$default_max_downloads     = max( 0, (int) ( $_POST['sft_default_max_downloads'] ?? 0 ) );
		$max_download_limit        = max( 0, (int) ( $_POST['sft_max_download_limit'] ?? 0 ) );

		// Link expiration.
		$allow_no_expiry     = isset( $_POST['sft_allow_no_expiry'] ) ? '1' : '0';
		$default_expiry_days = max( 0, (int) ( $_POST['sft_default_expiry_days'] ?? 0 ) );
		$max_expiry_days     = max( 0, (int) ( $_POST['sft_max_expiry_days'] ?? 0 ) );

		// File uploads.
		$max_file_mb = max( 1, (int) ( $_POST['sft_max_file_mb'] ?? 50 ) );

		// Audit log retention.
		$prune_enabled       = isset( $_POST['sft_audit_prune_enabled'] ) ? '1' : '0';
		$prune_days          = max( 30, (int) ( $_POST['sft_audit_prune_days'] ?? 365 ) );

		// Data & privacy.
		$delete_on_uninstall = isset( $_POST['sft_delete_on_uninstall'] ) ? '1' : '0';

		update_option( 'sft_otp_ttl_minutes',            $otp_ttl );
		update_option( 'sft_otp_max_attempts',            $otp_max_attempts );
		update_option( 'sft_allow_unlimited_downloads',   $allow_unlimited_downloads );
		update_option( 'sft_default_max_downloads',       $default_max_downloads );
		update_option( 'sft_max_download_limit',          $max_download_limit );
		update_option( 'sft_allow_no_expiry',             $allow_no_expiry );
		update_option( 'sft_default_expiry_days',         $default_expiry_days );
		update_option( 'sft_max_expiry_days',             $max_expiry_days );
		update_option( 'sft_max_file_mb',                 $max_file_mb );
		update_option( 'sft_audit_prune_enabled',         $prune_enabled );
		update_option( 'sft_audit_prune_days',            $prune_days );
		update_option( 'sft_delete_on_uninstall',         $delete_on_uninstall );

		sft_log( SFT_EVT_SETTINGS_SAVED, null, null, [
			'otp_ttl_minutes'  => $otp_ttl,
			'max_file_mb'      => $max_file_mb,
			'otp_max_attempts' => $otp_max_attempts,
		] );

		sft_set_notice( 'Settings saved.', 'success' );
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Enforce share limits on existing shares ───────────────────────────────
	if ( isset( $_POST['sft_enforce_share_limits'] ) ) {
		$updated = sft_enforce_share_limits();
		sft_set_notice(
			sprintf( 'Share limits enforced. <strong>%d</strong> share%s updated.', $updated, $updated === 1 ? '' : 's' ),
			'success'
		);
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'settings' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Manual audit prune ───────────────────────────────────────────────────
	if ( isset( $_POST['sft_manual_prune'] ) ) {
		$days    = max( 1, (int) ( $_POST['sft_prune_days_manual'] ?? 365 ) );
		$deleted = sft_prune_audit_log( $days );
		sft_set_notice( sprintf( 'Pruned <strong>%d</strong> audit log entr%s older than %d days.', $deleted, $deleted === 1 ? 'y' : 'ies', $days ), 'success' );
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'audit' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: revoke share ──────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_revoke_share'] ) ) {
		$share_id = (int) ( $_POST['share_id'] ?? 0 );
		if ( $share_id ) {
			sft_revoke_share( $share_id, get_current_user_id() );
			sft_set_notice( 'Share revoked.', 'success' );
		}
		$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: change vault status ───────────────────────────────────────────
	if ( isset( $_POST['sft_admin_vault_status'] ) ) {
		$vault_id   = (int) ( $_POST['vault_id'] ?? 0 );
		$new_status = sanitize_key( $_POST['new_status'] ?? '' );
		if ( $vault_id && $new_status ) {
			sft_update_vault_status( $vault_id, $new_status, get_current_user_id() );
			sft_set_notice( 'Vault status updated to <strong>' . esc_html( $new_status ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: delete vault ───────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_delete_vault'] ) ) {
		$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
		if ( $vault_id ) {
			sft_delete_vault( $vault_id );
			sft_set_notice( 'Vault permanently deleted.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin: delete file ────────────────────────────────────────────────────
	if ( isset( $_POST['sft_admin_delete_file'] ) ) {
		$file_id  = (int) ( $_POST['file_id'] ?? 0 );
		$vault_id = (int) ( $_POST['vault_id'] ?? 0 );
		if ( $file_id ) {
			sft_delete_file( $file_id, get_current_user_id() );
			sft_set_notice( 'File deleted.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Grant vault access to a user ─────────────────────────────────────────
	if ( isset( $_POST['sft_grant_user'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->add_cap( 'use_sft_vaults', true );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'grant_vault_access', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( 'Vault access granted to <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Revoke vault access from a user ──────────────────────────────────────
	if ( isset( $_POST['sft_revoke_user'] ) ) {
		$user_id = (int) ( $_POST['sft_user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->remove_cap( 'use_sft_vaults' );
			sft_log( SFT_EVT_SETTINGS_SAVED, null, null,
				[ 'action' => 'revoke_vault_access', 'target_user' => $user->user_login ],
				get_current_user_id() );
			sft_set_notice( 'Vault access revoked for <strong>' . esc_html( $user->display_name ) . '</strong>.', 'success' );
		}
		wp_redirect( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}

// ─── Menu registration ────────────────────────────────────────────────────────

add_action( 'admin_menu', 'sft_register_admin_menu' );

function sft_register_admin_menu(): void {
	$hook = add_menu_page(
		'WP Secure File Transfer Pro',
		'Secure Transfer',
		'manage_options',
		'sft-pro',
		'sft_admin_page',
		'dashicons-lock',
		80
	);

	add_action( "load-{$hook}", 'sft_register_admin_help_tabs' );
}

// ─── Contextual help ──────────────────────────────────────────────────────────

function sft_register_admin_help_tabs(): void {
	$screen = get_current_screen();
	$tab    = sanitize_key( $_GET['tab'] ?? 'dashboard' );

	switch ( $tab ) {

		case 'dashboard':
			$screen->add_help_tab( [
				'id'      => 'sft-dash-overview',
				'title'   => 'Dashboard Overview',
				'content' =>
					'<p>The Dashboard gives you a real-time summary of everything happening across all vaults.</p>' .
					'<ul>' .
					'<li><strong>Active Vaults</strong> — vaults currently in the active state.</li>' .
					'<li><strong>Encrypted Files / Total Size</strong> — all files stored across every vault.</li>' .
					'<li><strong>Active Shares</strong> — share links that are pending or active and not yet expired.</li>' .
					'<li><strong>Total Downloads</strong> — cumulative download count across all shares.</li>' .
					'<li><strong>OTP Failures</strong> — failed two-factor verification attempts in the last 30 days. Elevated counts may indicate a brute-force attempt.</li>' .
					'<li><strong>Audit Events</strong> — total rows in the audit log.</li>' .
					'</ul>' .
					'<p>The <strong>7-Day Download Activity</strong> sparkline shows daily download volume so you can spot unusual spikes.</p>' .
					'<p>The <strong>Recent Activity</strong> table lists the 10 most recent audit events site-wide.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-dash-security',
				'title'   => 'Security Status',
				'content' =>
					'<p>The Security Status card at the bottom of the Dashboard shows the current state of key security controls:</p>' .
					'<ul>' .
					'<li><strong>Key Source</strong> — <em>wp-config.php constant</em> is the most secure option. If it shows <em>database</em>, use the Settings tab to generate a key and move it to wp-config.php.</li>' .
					'<li><strong>Algorithm</strong> — AES-256-CBC with a unique IV per file.</li>' .
					'<li><strong>OTP TTL</strong> — how long a verification code is valid before it expires.</li>' .
					'<li><strong>Storage Path</strong> — where encrypted files are written. The directory should be .htaccess-protected.</li>' .
					'<li><strong>Cron</strong> — confirms the lifecycle cron job is scheduled. If it shows as missing, deactivate and reactivate the plugin.</li>' .
					'</ul>',
			] );
			break;

		case 'vaults':
			$screen->add_help_tab( [
				'id'      => 'sft-vaults-browse',
				'title'   => 'Browsing Vaults',
				'content' =>
					'<p>The Vaults tab lists every vault created on this site, across all users.</p>' .
					'<p>Use the <strong>filter panel</strong> on the left to narrow the list by status (active, expired, archived) or by searching the vault name or owner.</p>' .
					'<p>Click a vault name or the <strong>Inspect</strong> button to open the vault inspector, which shows all files, shares, and the vault\'s own audit trail.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-vaults-actions',
				'title'   => 'Vault Actions',
				'content' =>
					'<p>Inside the vault inspector you can:</p>' .
					'<ul>' .
					'<li><strong>Download any file</strong> — admin downloads are fully decrypted on the fly and logged in the audit trail.</li>' .
					'<li><strong>Delete a file</strong> — permanently removes the encrypted file from disk and the database.</li>' .
					'<li><strong>Revoke a share</strong> — immediately blocks the recipient from accessing the vault, even if they have an active download session.</li>' .
					'<li><strong>Change vault status</strong> — set a vault to active, archived, or expired. Expired and archived vaults cannot be shared or uploaded to.</li>' .
					'<li><strong>Delete vault</strong> — permanently removes all files, shares, and the vault record. This cannot be undone.</li>' .
					'</ul>' .
					'<p>All admin actions in the vault inspector are recorded in the audit log with your user account and IP address.</p>',
			] );
			break;

		case 'audit':
			$screen->add_help_tab( [
				'id'      => 'sft-audit-filter',
				'title'   => 'Filtering Events',
				'content' =>
					'<p>The Audit Log records every security-relevant action taken by users, recipients, and the system.</p>' .
					'<p>Use the filter panel to narrow results by:</p>' .
					'<ul>' .
					'<li><strong>Event Type</strong> — choose a specific event such as File Downloaded, OTP Verification Failed, or Share Revoked.</li>' .
					'<li><strong>Vault ID</strong> — show only events for a specific vault (the ID is visible in the Vaults tab URL).</li>' .
					'<li><strong>From / To</strong> — restrict the date range.</li>' .
					'<li><strong>Search Details</strong> — case-insensitive keyword search across the event detail data (e.g. an email address, file name, or status value).</li>' .
					'</ul>' .
					'<p>Dates and times are displayed in the site\'s configured timezone (Settings → General).</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-audit-export',
				'title'   => 'Exporting & Pruning',
				'content' =>
					'<p><strong>Export to CSV</strong> downloads the current filtered result set as a CSV file. The export respects all active filters, so you can export a targeted subset of events.</p>' .
					'<p><strong>Manual Prune</strong> permanently deletes all audit entries older than the number of days you specify. Use this to comply with data-retention policies or to keep the table size manageable.</p>' .
					'<p>Automatic pruning can also be configured in the <strong>Settings</strong> tab under Audit Log Retention — it runs hourly via WP-Cron.</p>',
			] );
			break;

		case 'users':
			$screen->add_help_tab( [
				'id'      => 'sft-users-access',
				'title'   => 'Granting Access',
				'content' =>
					'<p>By default only administrators can use the secure vault features. Use this tab to extend access to non-administrator users.</p>' .
					'<p>To grant access, search for a user by their WordPress username or email address, then click <strong>Grant Access</strong>. The user will immediately see a <strong>My Vaults</strong> menu item in their wp-admin sidebar and can create and manage their own vaults.</p>' .
					'<p>Administrators always have full access and do not appear in the granted-users list.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-users-revoke',
				'title'   => 'Revoking Access',
				'content' =>
					'<p>The <strong>Users with Vault Access</strong> table lists every non-administrator who has been granted the <em>use_sft_vaults</em> capability.</p>' .
					'<p>Click <strong>Revoke</strong> next to a user to remove their access. Revoking access does <em>not</em> delete their existing vaults or files — it only prevents them from logging in to manage vaults going forward. An admin can still inspect and manage their vaults from the Vaults tab.</p>' .
					'<p>All grant and revoke actions are recorded in the audit log.</p>',
			] );
			break;

		case 'settings':
			$screen->add_help_tab( [
				'id'      => 'sft-settings-twofactor',
				'title'   => 'Two-Factor Verification',
				'content' =>
					'<p><strong>OTP Validity</strong> — how many minutes a one-time code remains valid after it is emailed to a share recipient. Shorter values are more secure; longer values are more forgiving if email delivery is slow. Range: 5–60 minutes.</p>' .
					'<p><strong>Max Verification Attempts</strong> — how many times a recipient can enter an incorrect code before it is invalidated and they must request a new one. Lower values reduce brute-force risk.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-limits',
				'title'   => 'Download Limits & Expiration',
				'content' =>
					'<p>These settings control what constraints non-administrator users must follow when creating share links. Administrators are always exempt.</p>' .
					'<p><strong>Download Limits</strong></p>' .
					'<ul>' .
					'<li><em>Allow Unlimited Downloads</em> — when unchecked, every share must have a finite download count.</li>' .
					'<li><em>Default Download Limit</em> — pre-filled value in the share creation form. Set to 0 for no pre-fill (when unlimited is allowed).</li>' .
					'<li><em>Maximum Download Limit</em> — the highest value a user can enter. Set to 0 to impose no ceiling.</li>' .
					'</ul>' .
					'<p><strong>Link Expiration</strong></p>' .
					'<ul>' .
					'<li><em>Allow No Expiry</em> — when unchecked, every share must have an expiration date.</li>' .
					'<li><em>Default Expiry</em> — days from today pre-filled in the share form. Set to 0 for no pre-fill.</li>' .
					'<li><em>Maximum Expiry</em> — furthest-out expiration date allowed, in days from today. Set to 0 for no ceiling.</li>' .
					'</ul>' .
					'<p>Use <strong>Apply Limits to Existing Shares</strong> to retroactively enforce the current settings on shares that were created before these limits were configured.</p>',
			] );
			$screen->add_help_tab( [
				'id'      => 'sft-settings-key',
				'title'   => 'Encryption Key',
				'content' =>
					'<p>The master encryption key is used to derive a unique per-vault encryption key for every vault. All files are encrypted with AES-256-CBC.</p>' .
					'<p>The most secure configuration is to define the key as a PHP constant in <code>wp-config.php</code>:</p>' .
					'<pre><code>define( \'SFT_MASTER_KEY\', \'your-64-hex-char-key\' );</code></pre>' .
					'<p>Use the <strong>Generate New Key</strong> button to produce a cryptographically secure key. The key is generated server-side and never stored — copy it immediately into wp-config.php.</p>' .
					'<p><strong>Warning:</strong> Replacing an existing key will permanently break decryption of all files already uploaded. Only generate a new key on a fresh installation.</p>',
			] );
			break;
	}

	$screen->set_help_sidebar(
		'<p><strong>WP Secure File Transfer Pro</strong></p>' .
		'<p>Version ' . SFT_VERSION . '</p>' .
		'<hr>' .
		'<p>Encrypted vault storage with two-factor external sharing and full audit logging.</p>'
	);
}

// ─── Admin asset enqueueing ───────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'sft_enqueue_admin_assets' );

function sft_enqueue_admin_assets( string $hook ): void {
	if ( $hook !== 'toplevel_page_sft-pro' ) {
		return;
	}

	// Admin file download (multipart) — handled inline; we also need to handle
	// admin vault file serving via a direct AJAX action.
	add_action( 'admin_head', 'sft_admin_inline_js' );

	wp_register_style( 'sft-admin', false );
	wp_enqueue_style( 'sft-admin' );

	wp_add_inline_style( 'sft-admin', '
		/* ── Buttons ── */
		.sft-btn { background:#fff; border:1px solid #ccd0d4; padding:5px 12px; border-radius:4px;
		           cursor:pointer; font-size:12px; color:#2271b1; text-decoration:none; display:inline-block; }
		.sft-btn:hover { background:#f0f6fb; color:#2271b1; }
		.sft-danger { color:#d63638; border-color:#d63638; }
		.sft-danger:hover { background:#fef0f0; }
		.sft-primary { background:#2271b1; color:#fff; border-color:#2271b1; }
		.sft-primary:hover { background:#135e96; color:#fff; }

		/* ── Cards ── */
		.sft-card { background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-top:20px; }

		/* ── Stat cards (dashboard) ── */
		.sft-stats { display:flex; gap:16px; flex-wrap:wrap; margin-top:20px; }
		.sft-stat { flex:1; min-width:130px; background:#fff; border:1px solid #ccd0d4;
		            border-radius:4px; padding:16px; text-align:center; }
		.sft-stat-num { font-size:32px; font-weight:700; line-height:1.2; color:#2271b1; }
		.sft-stat-label { font-size:12px; color:#666; margin-top:4px; }

		/* ── Status badges ── */
		.sft-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
		.sft-badge-active  { background:#d1e7dd; color:#0a3622; }
		.sft-badge-expired,.sft-badge-revoked { background:#f8d7da; color:#58151c; }
		.sft-badge-archived,.sft-badge-pending { background:#e2e3e5; color:#41464b; }

		/* ── Tables ── */
		.sft-table { width:100%; border-collapse:collapse; }
		.sft-table th { text-align:left; padding:8px 10px; border-bottom:2px solid #ddd; font-size:12px; }
		.sft-table td { padding:8px 10px; border-bottom:1px solid #f0f2f5; font-size:13px; vertical-align:middle; }
		.sft-table tr:hover td { background:#f9fafc; }

		/* ── Vault inspector header ── */
		.sft-vault-inspector { margin-top:20px; }
		.sft-vault-inspector h2 { display:flex; align-items:center; gap:10px; margin-bottom:4px; }
		.sft-vault-meta { color:#888; font-size:13px; margin:0 0 16px; }

		/* ── Pagination ── */
		.sft-pagination { display:flex; align-items:center; justify-content:center; gap:4px; margin-top:16px; }
		.sft-pagination a, .sft-pagination span { display:inline-flex; align-items:center; justify-content:center;
		    min-width:32px; height:32px; padding:0 8px; border:1px solid #ccd0d4; border-radius:6px;
		    font-size:13px; text-decoration:none; color:#2271b1; background:#fff; transition:background .15s; }
		.sft-pagination .current { background:#2271b1; color:#fff; border-color:#2271b1; font-weight:600; }
		.sft-pagination a:hover { background:#f0f6fb; }
		.sft-pagination .dots { border:none; background:none; color:#999; }

		/* ── Filter panel ── */
		.sft-filter-wrap { display:flex; gap:20px; align-items:flex-start; margin-top:20px; }
		.sft-filter-panel { flex:0 0 220px; }
		.sft-filter-body { flex:1; min-width:0; }
	' );
}

function sft_admin_inline_js(): void {
	?>
	<script>
	function sftAdminDownload(fileId) {
		var url = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>'
			+ '?action=sft_admin_download&file_id=' + fileId
			+ '&_wpnonce=' + encodeURIComponent('<?php echo wp_create_nonce( 'sft_admin_download' ); ?>');
		window.location.href = url;
	}
	</script>
	<?php
}

// ─── Admin file download ──────────────────────────────────────────────────────

add_action( 'wp_ajax_sft_admin_download', 'sft_ajax_admin_download' );

function sft_ajax_admin_download(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.', 403 );
	}

	check_ajax_referer( 'sft_admin_download', '_wpnonce' );

	$file_id = (int) ( $_GET['file_id'] ?? 0 );
	$file    = sft_get_file( $file_id );

	if ( ! $file ) {
		wp_die( 'File not found.', 404 );
	}

	$vault = sft_get_vault( (int) $file->vault_id );
	if ( ! $vault ) {
		wp_die( 'Vault not found.', 404 );
	}

	// Log admin vault access before serving.
	sft_log( SFT_EVT_ADMIN_VAULT_ACCESS, (int) $vault->id, null,
		[ 'file_id' => $file_id, 'original_name' => $file->original_name ],
		get_current_user_id()
	);

	sft_serve_file( $file, $vault, null, true );
}

// ─── Encryption key preview generator ────────────────────────────────────────

add_action( 'wp_ajax_sft_generate_key_preview', 'sft_ajax_generate_key_preview' );

function sft_ajax_generate_key_preview(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Access denied.', 403 );
	}

	check_ajax_referer( 'sft_generate_key_preview', '_wpnonce' );

	// Generate 32 cryptographically secure random bytes → 64-char hex string.
	// Never stored — only returned for the admin to copy into wp-config.php.
	$key = bin2hex( random_bytes( 32 ) );

	wp_send_json_success( [ 'key' => $key ] );
}

// ─── Admin notice helpers ─────────────────────────────────────────────────────

function sft_set_notice( string $message, string $type = 'success' ): void {
	set_transient( 'sft_admin_notice_' . get_current_user_id(), compact( 'message', 'type' ), 30 );
}

function sft_show_notice(): void {
	$key    = 'sft_admin_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice ) {
		return;
	}
	delete_transient( $key );
	$class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
	printf(
		'<div class="notice %s is-dismissible" style="margin-top:15px;"><p>%s</p></div>',
		esc_attr( $class ),
		$notice['message'] // pre-escaped at set time
	);
}

// ─── Shared pagination helper ─────────────────────────────────────────────────

function sft_render_pagination( int $current, int $total_pages, array $extra_args = [] ): void {
	if ( $total_pages <= 1 ) {
		return;
	}

	$base = array_merge( [ 'page' => 'sft-pro' ], $extra_args );

	echo '<div class="sft-pagination">';

	if ( $current > 1 ) {
		$url = add_query_arg( array_merge( $base, [ 'paged' => $current - 1 ] ), admin_url( 'admin.php' ) );
		echo '<a href="' . esc_url( $url ) . '">&laquo;</a>';
	} else {
		echo '<span class="dots">&laquo;</span>';
	}

	for ( $p = 1; $p <= $total_pages; $p++ ) {
		$near  = abs( $p - $current ) <= 2;
		$edge  = $p === 1 || $p === $total_pages;
		if ( ! $near && ! $edge ) {
			if ( $p === 2 || $p === $total_pages - 1 ) {
				echo '<span class="dots">…</span>';
			}
			continue;
		}
		if ( $p === $current ) {
			echo '<span class="current">' . $p . '</span>';
		} else {
			$url = add_query_arg( array_merge( $base, [ 'paged' => $p ] ), admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '">' . $p . '</a>';
		}
	}

	if ( $current < $total_pages ) {
		$url = add_query_arg( array_merge( $base, [ 'paged' => $current + 1 ] ), admin_url( 'admin.php' ) );
		echo '<a href="' . esc_url( $url ) . '">&raquo;</a>';
	} else {
		echo '<span class="dots">&raquo;</span>';
	}

	echo '</div>';
}

// ─── Main admin page callback ─────────────────────────────────────────────────

function sft_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-sft-pro' ) );
	}

	$current_tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );

	sft_show_notice();

	echo '<div class="wrap"><h1>WP Secure File Transfer Pro</h1>';
	echo '<h2 class="nav-tab-wrapper">';

	$tabs = [
		'dashboard' => 'Dashboard',
		'vaults'    => 'Vaults',
		'audit'     => 'Audit Log',
		'users'     => 'Users',
		'settings'  => 'Settings',
	];

	foreach ( $tabs as $slug => $label ) {
		$url   = add_query_arg( [ 'page' => 'sft-pro', 'tab' => $slug ], admin_url( 'admin.php' ) );
		$class = $current_tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
		echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
	}

	echo '</h2>';

	switch ( $current_tab ) {
		case 'vaults':
			sft_render_tab_vaults();
			break;
		case 'audit':
			sft_render_tab_audit();
			break;
		case 'users':
			sft_render_tab_users();
			break;
		case 'settings':
			sft_render_tab_settings();
			break;
		default:
			sft_render_tab_dashboard();
	}

	echo '</div>';
}
