<?php
/**
 * 報價單（寄給顧客，附 PDF）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_Quote_Sent extends ULTQ_Email_Base {

	private $pdf_path = '';

	public function __construct() {
		$this->id             = 'ultq_quote_sent';
		$this->title          = '終極詢價：報價單';
		$this->description    = '管理員在詢價單按「寄出報價」時寄給顧客，附上報價單 PDF。';
		$this->template_html  = 'emails/quote-sent.php';
		$this->template_plain = 'emails/plain/quote-sent.php';
		$this->placeholders   = array( '{quote_number}' => '', '{customer_name}' => '', '{company}' => '', '{revision}' => '' );
		$this->customer_email = true;
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】報價單 {quote_number}{revision}';
	}

	public function get_default_heading() {
		return '您的報價單';
	}

	public function get_default_additional_content() {
		return '如需下單或有任何問題，直接回覆這封信或來電與我們聯繫即可。';
	}

	public function get_attachments() {
		$attachments = parent::get_attachments();
		if ( $this->pdf_path && file_exists( $this->pdf_path ) ) {
			$attachments[] = $this->pdf_path;
		}
		return $attachments;
	}

	/**
	 * @param string $pdf_path ULTQ_PDF::save_temp() 產生的暫存檔，寄完由呼叫端刪除
	 */
	public function trigger( $post_id, $pdf_path = '' ) {
		$customer        = $this->setup_quote( $post_id );
		// 信是在存快照之前寄的，所以這封信對應的是「下一版」
		$this->placeholders['{revision}'] = ULTQ_Quote_Post::revision_label( ULTQ_Quote_Post::revision( $post_id ) + 1 );
		$this->recipient = $customer['email'];
		$this->pdf_path  = $pdf_path;
		$this->extra     = array(
			'pdf_url'  => ULTQ_Quote_Post::public_pdf_url( $post_id ),
			'view_url' => ULTQ_Quote_Response::view_url( $post_id ),
			'xlsx_url' => ULTQ_Excel::customer_enabled() ? ULTQ_Excel::customer_url( $post_id, ULTQ_Quote_Post::get_token( $post_id, true ) ) : '',
		);
		$sent           = $this->dispatch();
		$this->pdf_path = '';
		return $sent;
	}
}
