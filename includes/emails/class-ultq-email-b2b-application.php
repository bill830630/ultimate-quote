<?php
/**
 * 新的 B2B 會員申請（寄給管理員）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_B2B_Application extends ULTQ_Email_Base {

	public function __construct() {
		$this->id             = 'ultq_b2b_application';
		$this->title          = '終極詢價：新 B2B 會員申請';
		$this->description    = '會員送出 B2B 會員申請時通知管理員。';
		$this->template_html  = 'emails/b2b-application.php';
		$this->template_plain = 'emails/plain/b2b-application.php';
		$this->placeholders   = array( '{company}' => '' );
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】新的 B2B 會員申請：{company}';
	}

	public function get_default_heading() {
		return '新的 B2B 會員申請';
	}

	public function trigger( $user_id ) {
		$this->user   = get_userdata( $user_id );
		$this->object = $this->user;
		if ( ! $this->user ) {
			return false;
		}
		$data                             = ULTQ_B2B_Member::get_data( $user_id );
		$this->placeholders['{company}']  = $data['company'] ?: $data['contact'];
		$this->extra                      = array(
			'data'         => $data,
			'auto_approve' => (bool) ULTQ_Settings::get( 'b2b_auto_approve' ),
			'review_url'   => admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#ultq-b2b' ),
		);
		return $this->dispatch();
	}
}
