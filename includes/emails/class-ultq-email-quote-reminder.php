<?php
/**
 * 報價即將到期提醒（寄給顧客，每日排程 ULTQ_Cron 觸發）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_Quote_Reminder extends ULTQ_Email_Base {

	public function __construct() {
		$this->id             = 'ultq_quote_reminder';
		$this->title          = '終極詢價：報價即將到期';
		$this->description    = '已寄出的報價快到有效期限、顧客還沒回覆時提醒顧客（提前幾天在「終極詢價 → 設定 → 業務與追蹤」設定）。';
		$this->template_html  = 'emails/quote-reminder.php';
		$this->template_plain = 'emails/plain/quote-reminder.php';
		$this->placeholders   = array( '{quote_number}' => '', '{customer_name}' => '', '{company}' => '', '{valid_until}' => '' );
		$this->customer_email = true;
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】報價單 {quote_number} 將於 {valid_until} 到期';
	}

	public function get_default_heading() {
		return '您的報價即將到期';
	}

	public function get_default_additional_content() {
		return '如果需要調整數量或規格，直接回覆這封信即可，我們會重新為您報價。';
	}

	public function trigger( $post_id ) {
		$customer        = $this->setup_quote( $post_id );
		$quote           = ULTQ_Quote_Post::sent( $post_id )['quote'];
		$this->recipient = $customer['email'];
		$this->placeholders['{valid_until}'] = $quote['valid_until'];
		$this->extra     = array(
			'pdf_url'  => ULTQ_Quote_Post::public_pdf_url( $post_id ),
			'view_url' => ULTQ_Quote_Response::view_url( $post_id ),
		);
		return $this->dispatch();
	}
}
