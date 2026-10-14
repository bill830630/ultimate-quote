<?php
/**
 * 已收到詢價（寄給顧客）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_Quote_Received extends ULTQ_Email_Base {

	public function __construct() {
		$this->id             = 'ultq_quote_received';
		$this->title          = '終極詢價：已收到詢價';
		$this->description    = '顧客送出詢價後，寄一封確認信給顧客。';
		$this->template_html  = 'emails/quote-received.php';
		$this->template_plain = 'emails/plain/quote-received.php';
		$this->placeholders   = array( '{quote_number}' => '', '{customer_name}' => '', '{company}' => '' );
		$this->customer_email = true;
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】已收到您的詢價 {quote_number}';
	}

	public function get_default_heading() {
		return '感謝您的詢價';
	}

	public function get_default_additional_content() {
		return '我們會盡快為您報價，報價單將以 Email 寄送給您。';
	}

	public function trigger( $post_id ) {
		$customer        = $this->setup_quote( $post_id );
		$this->recipient = $customer['email'];
		return $this->dispatch();
	}
}
