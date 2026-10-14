<?php
/**
 * 新詢價通知（寄給管理員）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_New_Quote extends ULTQ_Email_Base {

	public function __construct() {
		$this->id             = 'ultq_new_quote';
		$this->title          = '終極詢價：新詢價';
		$this->description    = '顧客送出詢價清單時通知管理員。';
		$this->template_html  = 'emails/new-quote.php';
		$this->template_plain = 'emails/plain/new-quote.php';
		$this->placeholders   = array( '{quote_number}' => '', '{customer_name}' => '', '{company}' => '' );
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】新詢價 {quote_number}（{customer_name}）';
	}

	public function get_default_heading() {
		return '收到新的詢價';
	}

	public function get_headers() {
		$headers  = parent::get_headers();
		$customer = ULTQ_Quote_Post::get_customer( $this->quote_id );
		if ( is_email( $customer['email'] ) ) {
			$headers .= 'Reply-to: ' . $customer['name'] . ' <' . $customer['email'] . ">\r\n";
		}
		return $headers;
	}

	public function trigger( $post_id ) {
		$this->setup_quote( $post_id );
		$this->recipient = $this->admin_recipient( $post_id );
		return $this->dispatch();
	}
}
