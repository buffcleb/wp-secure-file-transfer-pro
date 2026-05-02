<?php
/**
 * User dashboard — vault detail view.
 *
 * Shows the selected vault's files, shares, and audit log (scoped to this
 * vault and this owner). Provides forms to upload files, create shares, and
 * revoke shares. File deletion and vault deletion are also available.
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sft_render_user_vault_detail( int $vault_id ): void {
	$user_id  = get_current_user_id();
	$vault    = sft_get_vault( $vault_id );
	$back_url = add_query_arg( [ 'page' => 'sft-my-vaults' ], admin_url( 'admin.php' ) );

	if ( ! $vault || ( (int) $vault->owner_id !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
		echo '<p><a href="' . esc_url( $back_url ) . '">← Back to My Vaults</a></p>';
		echo '<p style="color:#d63638;">Vault not found or access denied.</p>';
		return;
	}

	$files   = sft_get_vault_files( $vault_id );
	$shares  = sft_get_vault_shares( $vault_id );
	$audit   = sft_get_audit_logs( [ 'vault_id' => $vault_id, 'per_page' => 20 ] );

	$form_url   = add_query_arg( [ 'page' => 'sft-my-vaults', 'vault_id' => $vault_id ], admin_url( 'admin.php' ) );
	$is_active  = $vault->status === 'active';
	$max_file_mb = (int) get_option( 'sft_max_file_mb', 50 );

	// Share form global limits (non-admin users are subject to these).
	$is_admin_user          = current_user_can( 'manage_options' );
	$allow_unlimited_dl     = get_option( 'sft_allow_unlimited_downloads', '1' ) === '1';
	$default_max_downloads  = (int) get_option( 'sft_default_max_downloads', 0 );
	$max_download_ceiling   = (int) get_option( 'sft_max_download_limit', 0 );
	$allow_no_expiry        = get_option( 'sft_allow_no_expiry', '1' ) === '1';
	$default_expiry_days    = (int) get_option( 'sft_default_expiry_days', 0 );
	$max_expiry_days        = (int) get_option( 'sft_max_expiry_days', 0 );

	// Pre-fill values for the share form.
	$share_default_dl       = $default_max_downloads;
	$share_dl_max_attr      = ( ! $is_admin_user && $max_download_ceiling > 0 ) ? $max_download_ceiling : '';
	$share_dl_min           = ( ! $is_admin_user && ! $allow_unlimited_dl ) ? 1 : 0;
	$share_expiry_default   = '';
	if ( $default_expiry_days > 0 ) {
		$share_expiry_default = gmdate( 'Y-m-d\TH:i', strtotime( "+{$default_expiry_days} days" ) );
	}
	$share_expiry_max_attr  = ( ! $is_admin_user && $max_expiry_days > 0 )
		? gmdate( 'Y-m-d\TH:i', strtotime( "+{$max_expiry_days} days" ) )
		: '';
	$share_expiry_required  = ( ! $is_admin_user && ! $allow_no_expiry );
	?>

	<p style="margin-top:16px;"><a href="<?php echo esc_url( $back_url ); ?>">← Back to My Vaults</a></p>

	<!-- Vault header -->
	<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-top:4px;">
		<div>
			<h2 style="margin:0 0 4px; display:flex; align-items:center; gap:10px;">
				<?php echo esc_html( $vault->name ); ?>
				<span class="sft-badge sft-badge-<?php echo esc_attr( $vault->status ); ?>"><?php echo esc_html( $vault->status ); ?></span>
			</h2>
			<p style="color:#888; font-size:13px; margin:0;">
				Created <?php echo esc_html( gmdate( 'M j, Y', strtotime( $vault->created_at ) ) ); ?>
				<?php if ( $vault->expires_at ) : ?>
					&bull; Expires <?php echo esc_html( gmdate( 'M j, Y g:i A', strtotime( $vault->expires_at ) ) ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $vault->description ) : ?>
				<p style="color:#555; font-size:13px; margin:4px 0 0;"><?php echo esc_html( $vault->description ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $is_active ) : ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>"
		      onsubmit="return confirm('Permanently delete vault &quot;<?php echo esc_js( $vault->name ); ?>&quot; and all its files?');">
			<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
			<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
			<input type="submit" name="sft_ud_delete_vault" value="Delete Vault" class="button sft-danger">
		</form>
		<?php endif; ?>
	</div>

	<!-- ── Files ──────────────────────────────────────────────────────────── -->
	<div class="sft-card">
		<h3 style="margin-top:0;">Files (<?php echo count( $files ); ?>)</h3>

		<?php if ( $files ) : ?>
			<table class="sft-table widefat" style="margin-bottom:20px;">
				<thead><tr>
					<th>Filename</th><th>Size</th><th>Uploaded</th><th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $files as $f ) : ?>
					<tr>
						<td><?php echo esc_html( $f->original_name ); ?></td>
						<td style="color:#888;"><?php echo esc_html( size_format( $f->file_size ) ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo esc_html( gmdate( 'M j, Y g:i A', strtotime( $f->uploaded_at ) ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
							      onsubmit="return confirm('Delete <?php echo esc_js( $f->original_name ); ?>?');">
								<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
								<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
								<input type="hidden" name="file_id"  value="<?php echo (int) $f->id; ?>">
								<input type="submit" name="sft_ud_delete_file" value="Delete" class="sft-btn sft-danger">
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="color:#888; font-size:13px; margin-bottom:16px;">No files uploaded yet.</p>
		<?php endif; ?>

		<?php if ( $is_active ) : ?>
		<hr style="margin:0 0 16px;">
		<h4 style="margin:0 0 10px; font-size:13px; font-weight:700; text-transform:uppercase; color:#888; letter-spacing:.5px;">Upload File</h4>
		<div>
			<div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
				<div style="flex:1; min-width:200px;">
					<label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
						File <span style="font-weight:400; color:#888;">(max <?php echo $max_file_mb; ?> MB)</span>
					</label>
					<input type="file" id="sft-ud-file-input" style="width:100%; padding:6px;">
				</div>
				<div>
					<button type="button" id="sft-ud-upload-btn" class="button button-primary" onclick="sftUdUpload()">
						Encrypt &amp; Upload
					</button>
				</div>
			</div>
			<div id="sft-ud-progress-wrap" style="display:none; margin-top:10px;">
				<div style="background:#f0f2f5; border-radius:4px; overflow:hidden; height:16px;">
					<div id="sft-ud-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width .2s;"></div>
				</div>
				<p id="sft-ud-progress-label" style="font-size:12px; color:#888; margin:4px 0 0;">Uploading…</p>
			</div>
			<p id="sft-ud-upload-error" style="display:none; color:#d63638; font-size:13px; margin:8px 0 0;"></p>
		</div>
		<script>
		var sftUd = {
			ajaxUrl:  <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce:    <?php echo wp_json_encode( wp_create_nonce( 'sft_user_nonce' ) ); ?>,
			vaultId:  <?php echo (int) $vault_id; ?>,
			chunkSize:<?php echo sft_chunk_size_bytes(); ?>
		};
		function sftUdGenId() {
			return Array.from(crypto.getRandomValues(new Uint8Array(16)))
				.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
		}
		async function sftUdUpload() {
			var input = document.getElementById('sft-ud-file-input');
			var errEl = document.getElementById('sft-ud-upload-error');
			errEl.style.display = 'none';
			if (!input.files.length) {
				errEl.textContent = 'Please select a file.';
				errEl.style.display = '';
				return;
			}
			var file = input.files[0];
			var btn  = document.getElementById('sft-ud-upload-btn');
			var wrap = document.getElementById('sft-ud-progress-wrap');
			var bar  = document.getElementById('sft-ud-progress-bar');
			var lbl  = document.getElementById('sft-ud-progress-label');
			btn.disabled = true;
			wrap.style.display = '';
			var CHUNK = sftUd.chunkSize;
			var total = Math.ceil(file.size / CHUNK) || 1;
			var uid   = sftUdGenId();
			try {
				for (var i = 0; i < total; i++) {
					var start = i * CHUNK;
					var fd = new FormData();
					fd.append('action',       'sft_upload_chunk');
					fd.append('_wpnonce',     sftUd.nonce);
					fd.append('vault_id',     sftUd.vaultId);
					fd.append('upload_id',    uid);
					fd.append('chunk_index',  i);
					fd.append('total_chunks', total);
					fd.append('file_name',    file.name);
					fd.append('total_size',   file.size);
					fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);
					var r = await fetch(sftUd.ajaxUrl, {method:'POST', body:fd});
					var j = await r.json();
					if (!j.success) throw new Error(j.data || 'Upload failed.');
					var pct = Math.round((i + 1) / total * 100);
					bar.style.width = pct + '%';
					lbl.textContent = j.data.complete ? 'Encrypting & saving…' : 'Uploading ' + pct + '%…';
				}
				window.location.reload();
			} catch(e) {
				btn.disabled = false;
				wrap.style.display = 'none';
				errEl.textContent = e.message;
				errEl.style.display = '';
			}
		}
		</script>
		<?php endif; ?>
	</div>

	<!-- ── Shares ─────────────────────────────────────────────────────────── -->
	<div class="sft-card">
		<h3 style="margin-top:0;">Shares (<?php echo count( $shares ); ?>)</h3>

		<?php if ( $shares ) : ?>
			<table class="sft-table widefat" style="margin-bottom:20px;">
				<thead><tr>
					<th>Recipient</th><th>Status</th><th>Downloads</th><th>Expires</th><th>Last Access</th><th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $shares as $s ) :
					$dl_info = $s->max_downloads > 0
						? (int) $s->download_count . ' / ' . (int) $s->max_downloads
						: (int) $s->download_count . ' / ∞';
				?>
					<tr>
						<td><?php echo esc_html( $s->recipient_email ); ?></td>
						<td><span class="sft-badge sft-badge-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $s->status ); ?></span></td>
						<td style="font-size:13px;"><?php echo esc_html( $dl_info ); ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $s->expires_at ? esc_html( gmdate( 'M j, Y', strtotime( $s->expires_at ) ) ) : '—'; ?></td>
						<td style="color:#888; font-size:12px;"><?php echo $s->last_accessed ? esc_html( gmdate( 'M j, Y g:i A', strtotime( $s->last_accessed ) ) ) : 'Never'; ?></td>
						<td>
							<?php if ( in_array( $s->status, [ 'pending', 'active' ], true ) ) : ?>
								<form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;"
								      onsubmit="return confirm('Revoke access for <?php echo esc_js( $s->recipient_email ); ?>?');">
									<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
									<input type="hidden" name="vault_id"  value="<?php echo $vault_id; ?>">
									<input type="hidden" name="share_id"  value="<?php echo (int) $s->id; ?>">
									<input type="submit" name="sft_ud_revoke_share" value="Revoke" class="sft-btn sft-danger">
								</form>
							<?php else : ?>
								<span style="color:#aaa; font-size:12px;"><?php echo ucfirst( $s->status ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="color:#888; font-size:13px; margin-bottom:16px;">No shares created yet.</p>
		<?php endif; ?>

		<?php if ( $is_active ) : ?>
		<hr style="margin:0 0 16px;">
		<h4 style="margin:0 0 10px; font-size:13px; font-weight:700; text-transform:uppercase; color:#888; letter-spacing:.5px;">Create New Share</h4>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php wp_nonce_field( 'sft_user_dashboard_action', 'sft_user_nonce' ); ?>
			<input type="hidden" name="vault_id" value="<?php echo $vault_id; ?>">
			<div style="display:flex; gap:16px; flex-wrap:wrap;">
				<div style="flex:2; min-width:200px;">
					<div class="sft-form-row">
						<label for="sft-share-email">Recipient Email <span style="color:#d63638;">*</span></label>
						<input type="email" id="sft-share-email" name="share_email" placeholder="recipient@example.com" required>
					</div>
				</div>
				<div style="flex:1; min-width:130px;">
					<div class="sft-form-row">
						<label for="sft-share-maxdl">
							Download Limit
							<?php if ( $is_admin_user || $allow_unlimited_dl ) : ?>
								<span style="font-weight:400;color:#888;">(0 = ∞)</span>
							<?php endif; ?>
						</label>
						<input type="number" id="sft-share-maxdl" name="share_max_downloads"
						       value="<?php echo $share_default_dl; ?>"
						       min="<?php echo $share_dl_min; ?>"
						       <?php if ( $share_dl_max_attr !== '' ) : ?>max="<?php echo $share_dl_max_attr; ?>"<?php endif; ?>>
					</div>
				</div>
				<div style="flex:1; min-width:160px;">
					<div class="sft-form-row">
						<label for="sft-share-expires">
							Link Expires
							<?php if ( ! $share_expiry_required ) : ?>
								<span style="font-weight:400;color:#888;">(optional)</span>
							<?php else : ?>
								<span style="color:#d63638;">*</span>
							<?php endif; ?>
						</label>
						<input type="datetime-local" id="sft-share-expires" name="share_expires"
						       value="<?php echo esc_attr( $share_expiry_default ); ?>"
						       <?php if ( $share_expiry_max_attr !== '' ) : ?>max="<?php echo esc_attr( $share_expiry_max_attr ); ?>"<?php endif; ?>
						       <?php if ( $share_expiry_required ) : ?>required<?php endif; ?>>
					</div>
				</div>
			</div>
			<div class="sft-form-actions">
				<input type="submit" name="sft_ud_create_share" value="Send Invite" class="button button-primary">
			</div>
		</form>
		<?php endif; ?>
	</div>

	<!-- ── Vault Audit Log ────────────────────────────────────────────────── -->
	<div class="sft-card">
		<h3 style="margin-top:0;">Activity Log <span style="font-size:13px; font-weight:400; color:#888;">(last 20 events)</span></h3>
		<?php if ( ! $audit ) : ?>
			<p style="color:#888; font-size:13px;">No activity recorded for this vault yet.</p>
		<?php else : ?>
			<table class="sft-table widefat striped">
				<thead><tr>
					<th>Event</th><th>Details</th><th>IP</th><th>Date/Time (UTC)</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $audit as $row ) :
					$detail     = $row->details ? json_decode( $row->details, true ) : [];
					$detail_str = $detail
						? implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $detail ), $detail ) )
						: '';
				?>
					<tr>
						<td><strong><?php echo esc_html( sft_audit_event_label( $row->event_type ) ); ?></strong></td>
						<td style="font-size:12px; color:#666; max-width:280px; word-break:break-word;"><?php echo esc_html( $detail_str ); ?></td>
						<td style="font-size:11px; color:#aaa;"><?php echo esc_html( $row->ip_address ); ?></td>
						<td style="color:#888; white-space:nowrap; font-size:12px;"><?php echo esc_html( gmdate( 'M j, Y g:i A', strtotime( $row->created_at ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
