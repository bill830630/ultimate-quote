<?php
/**
 * 顧客線上回覆報價（接受／婉拒）通知（寄給管理員）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_Quote_Response extends ULTQ_Email_Base {

	public function __construct() {
		$this->id             = 'ultq_quote_response';
		$this->title          = '終極詢價：顧客回覆報價';
		$this->description    = '顧客在網站上接受或婉拒報價時通知管理員。';
		$this->template_html  = 'emails/quote-response.php';
		$this->template_plain = 'emails/plain/quote-response.php';
		$this->placeholders   = array( '{quote_number}' => '', '{customer_name}' => '', '{company}' => '', '{result}' => '' );
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】{result}：{quote_number}（{customer_name}）';
	}

	public function get_default_heading() {
		return '{result}';
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
		$response = ULTQ_Quote_Post::get_response( $post_id );
		$this->placeholders['{result}'] = 'accept' === $response['action'] ? '顧客已接受報價' : '顧客婉拒報價';
		$this->extra = array( 'response' => $response );
		return $this->dispatch();
	}
}
