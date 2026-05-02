<?php
/**
 * Users tab — grant and revoke the use_sft_vaults capability.
 *
 * Users listed here can:
 *   - Access the [sft_my_vaults] shortcode on the front end
 *   - See the "My Vaults" dashboard panel in wp-admin
 *   - Create vaults, upload files, and share them
 *
 * Admins (manage_options) always have full access and do not appear here.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_tab_users(): void {
	$search       = sanitize_text_field( $_GET['sft_user_search'] ?? '' );
	$search_result = null;
	$search_error  = '';

	// ── Search result (GET) ──────────────────────────────────────────────────
	if ( $search ) {
		$found = get_user_by( 'login', $search ) ?: get_user_by( 'email', $search );
		if ( $found ) {
			$search_result = $found;
		} else {
			$search_error = 'No user found matching "' . esc_html( $search ) . '".';
		}
	}

	// ── Users who currently have the capability ──────────────────────────────
	$granted_users = get_users( [
		'capability' => 'use_sft_vaults',
		'orderby'    => 'display_name',
		'order'      => 'ASC',
	] );
	?>

	<div style="display:flex; gap:24px; align-items:flex-start; margin-top:20px; flex-wrap:wrap;">

		<!-- ── Left: search + grant ──────────────────────────────────────────── -->
		<div class="sft-card" style="flex:0 0 280px; margin-top:0;">
			<h3 style="margin-top:0;">Grant Access</h3>
			<p style="font-size:13px;color:#555;">Search by username or email, then grant the <code>use_sft_vaults</code> capability.</p>

			<!-- Search form -->
			<form method="get">
				<input type="hidden" name="page" value="sft-pro">
				<input type="hidden" name="tab"  value="users">
				<label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">Username or Email</label>
				<input type="text" name="sft_user_search" value="<?php echo esc_attr( $search ); ?>"
				       style="width:100%;margin-bottom:8px;" placeholder="e.g. jsmith or j@example.com">
				<input type="submit" value="Search" class="button" style="width:100%;">
			</form>

			<!-- Search result -->
			<?php if ( $search_error ) : ?>
				<p style="color:#d63638;font-size:13px;margin-top:10px;"><?php echo esc_html( $search_error ); ?></p>
			<?php endif; ?>

			<?php if ( $search_result ) :
				$already_granted = $search_result->has_cap( 'use_sft_vaults' );
				$is_admin        = $search_result->has_cap( 'manage_options' );
			?>
				<div style="margin-top:14px;padding:12px;background:#f6f7f7;border-radius:4px;border:1px solid #ddd;">
					<strong><?php echo esc_html( $search_result->display_name ); ?></strong><br>
					<span style="font-size:12px;color:#888;"><?php echo esc_html( $search_result->user_email ); ?></span><br>
					<span style="font-size:12px;color:#888;">Role: <?php echo esc_html( implode( ', ', $search_result->roles ) ); ?></span>

					<?php if ( $is_admin ) : ?>
						<p style="font-size:12px;color:#2271b1;margin:8px 0 0;">Admins always have full access — no grant needed.</p>
					<?php elseif ( $already_granted ) : ?>
						<p style="font-size:12px;color:#0a3622;margin:8px 0 4px;">✓ Already has access.</p>
						<form method="post" action="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) ); ?>">
							<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
							<input type="hidden" name="sft_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="sft_revoke_user" value="Revoke Access" class="button sft-danger" style="width:100%;margin-top:4px;">
						</form>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) ); ?>" style="margin-top:8px;">
							<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
							<input type="hidden" name="sft_user_id" value="<?php echo (int) $search_result->ID; ?>">
							<input type="submit" name="sft_grant_user" value="Grant Access" class="button button-primary" style="width:100%;">
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- ── Right: current access list ───────────────────────────────────── -->
		<div style="flex:1; min-width:300px;">
			<h3 style="margin-top:0;">Users with Vault Access (<?php echo count( $granted_users ); ?>)</h3>

			<?php if ( ! $granted_users ) : ?>
				<p style="color:#888;font-size:13px;">No users have been granted access yet.</p>
			<?php else : ?>
				<table class="sft-table widefat striped">
					<thead><tr>
						<th>User</th>
						<th>Email</th>
						<th>Role</th>
						<th>Vaults</th>
						<th>Action</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $granted_users as $u ) :
						global $wpdb;
						$vault_count = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}sft_vaults WHERE owner_id = %d",
							$u->ID
						) );
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $u->display_name ); ?></strong><br>
								<span style="font-size:11px;color:#888;"><?php echo esc_html( $u->user_login ); ?></span>
							</td>
							<td style="font-size:13px;"><?php echo esc_html( $u->user_email ); ?></td>
							<td style="font-size:13px;"><?php echo esc_html( implode( ', ', $u->roles ) ); ?></td>
							<td style="font-size:13px;"><?php echo $vault_count; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( add_query_arg( [ 'page' => 'sft-pro', 'tab' => 'users' ], admin_url( 'admin.php' ) ) ); ?>"
								      onsubmit="return confirm('Revoke vault access for <?php echo esc_js( $u->display_name ); ?>?');">
									<?php wp_nonce_field( 'sft_admin_action', 'sft_nonce' ); ?>
									<input type="hidden" name="sft_user_id" value="<?php echo (int) $u->ID; ?>">
									<input type="submit" name="sft_revoke_user" value="Revoke" class="sft-btn sft-danger">
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
