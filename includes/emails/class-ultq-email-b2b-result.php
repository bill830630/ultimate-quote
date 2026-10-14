<?php
/**
 * B2B 會員審核結果（寄給顧客）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_B2B_Result extends ULTQ_Email_Base {

	public function __construct() {
		$this->id             = 'ultq_b2b_result';
		$this->title          = '終極詢價：B2B 審核結果';
		$this->description    = 'B2B 會員申請核准或未通過時通知顧客。';
		$this->template_html  = 'emails/b2b-result.php';
		$this->template_plain = 'emails/plain/b2b-result.php';
		$this->placeholders   = array( '{result}' => '' );
		$this->customer_email = true;
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】B2B 會員申請{result}';
	}

	public function get_default_heading() {
		return 'B2B 會員申請{result}';
	}

	public function trigger( $user_id, $status ) {
		$this->user   = get_userdata( $user_id );
		$this->object = $this->user;
		if ( ! $this->user ) {
			return false;
		}
		$approved                        = 'approved' === $status;
		$this->recipient                 = $this->user->user_email;
		$this->placeholders['{result}']  = $approved ? '已核准' : '結果通知';
		$this->extra                     = array(
			'approved' => $approved,
			'reason'   => (string) get_user_meta( $user_id, ULTQ_B2B_Member::META_REASON, true ),
			'data'     => ULTQ_B2B_Member::get_data( $user_id ),
			'shop_url' => wc_get_page_permalink( 'shop' ),
			'apply_url' => wc_get_account_endpoint_url( ULTQ_B2B_Member::ENDPOINT ),
		);
		return $this->dispatch();
	}
}
