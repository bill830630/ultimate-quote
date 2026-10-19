<?php
/**
 * Plugin Name: Ultimate Quote
 * Description: B2B 詢價系統：詢價清單、隱藏價格改詢價按鈕、B2B 會員申請審核、B2B 專屬價／階梯價、後台報價並寄出中文報價單 PDF，Email＋LINE 通知。
 * Version:     1.3.29
 * Author:      快捷鍵Ctrl+A
 * Text Domain: ultimate-quote
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ULTQ_VERSION', '1.3.29' );
define( 'ULTQ_PLUGIN_FILE', __FILE__ );
define( 'ULTQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ULTQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-install.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-form.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-settings.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-helper.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-quote-post.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-b2b-member.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-b2b-pricing.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-rules.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-list.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-frontend.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-ajax.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-admin.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-pdf.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-notifications.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-my-account.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-quote-response.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-sales.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-files.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-quantity.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-cron.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-tax-lookup.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-excel.php';
require_once ULTQ_PLUGIN_DIR . 'includes/class-ultq-updater.php';

register_activation_hook( __FILE__, array( 'ULTQ_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ULTQ_Cron', 'unschedule' ) );

add_action( 'plugins_loaded', function () {
	// 更新檢查不依賴 WooCommerce，沒啟用 WooCommerce 也能更新
	ULTQ_Updater::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>「終極詢價」外掛需要啟用 WooCommerce 才能運作。</p></div>';
		} );
		return;
	}

	ULTQ_Install::maybe_upgrade();
	ULTQ_Settings::init();
	ULTQ_Quote_Post::init();
	ULTQ_B2B_Member::init();
	ULTQ_B2B_Pricing::init();
	ULTQ_List::init();
	ULTQ_Frontend::init();
	ULTQ_Ajax::init();
	ULTQ_Admin::init();
	ULTQ_PDF::init();
	ULTQ_Notifications::init();
	ULTQ_My_Account::init();
	ULTQ_Quote_Response::init();
	ULTQ_Sales::init();
	ULTQ_Files::init();
	ULTQ_Quantity::init();
	ULTQ_Cron::init();
	ULTQ_Tax_Lookup::init();
	ULTQ_Excel::init();
} );

// 宣告支援 HPOS（本外掛不讀寫訂單，純宣告避免 WooCommerce 顯示不相容警告）
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
