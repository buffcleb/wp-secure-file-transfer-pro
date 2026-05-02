<?php
/**
 * Plugin Name: WP Secure File Transfer Pro
 * Description: Encrypted file vaults with two-factor external sharing, comprehensive audit logging, lifecycle management, and super-admin vault oversight.
 * Version:     1.0.1
 * Requires PHP: 7.4
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WPSecureFileTransferPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Plugin constants ─────────────────────────────────────────────────────────
define( 'SFT_VERSION',   '1.0.1' );
define( 'SFT_DIR',       plugin_dir_path( __FILE__ ) );
define( 'SFT_BASENAME',  plugin_basename( __FILE__ ) );
define( 'SFT_VAULT_DIR', WP_CONTENT_DIR . '/uploads/sft-vaults/' );

// Administrators can define SFT_MASTER_KEY as 64 hex chars in wp-config.php
// to keep the encryption master key out of the database entirely.

// ─── Load core modules ────────────────────────────────────────────────────────
require_once SFT_DIR . 'includes/class-sft-db.php';
require_once SFT_DIR . 'includes/class-sft-crypto.php';
require_once SFT_DIR . 'includes/class-sft-audit.php';
require_once SFT_DIR . 'includes/class-sft-vault.php';
require_once SFT_DIR . 'includes/class-sft-share.php';
require_once SFT_DIR . 'includes/class-sft-lifecycle.php';
require_once SFT_DIR . 'includes/class-sft-frontend.php';

if ( is_admin() ) {
	require_once SFT_DIR . 'admin/class-sft-admin.php';
	require_once SFT_DIR . 'admin/class-sft-user-dashboard.php';
}
