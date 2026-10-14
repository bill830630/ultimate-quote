<?php
/**
 * 終極詢價 Email 共用底層：樣板路徑、管理員信的收件人欄位、樣板參數
 *
 * 樣板可被主題覆寫：yourtheme/ultimate-quote/emails/xxx.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ULTQ_Email_Base extends WC_Email {

	public $quote_id = 0;
	public $user     = null;
	protected $extra = array();

	public function __construct() {
		$this->template_base = ULTQ_PLUGIN_DIR . 'templates/';
		parent::__construct();
		if ( ! $this->is_customer_email() ) {
			$this->recipient = $this->get_option( 'recipient' ) ?: get_option( 'admin_email' );
		}
	}

	public function init_form_fields() {
		parent::init_form_fields();
		if ( ! $this->customer_email ) {
			$this->form_fields = array_merge(
				array_slice( $this->form_fields, 0, 1, true ),
				array(
					'recipient' => array(
						'title'       => '收件人',
						'type'        => 'text',
						/* translators: %s: admin email */
						'description' => sprintf( '多個收件人用逗號分隔，預設為 %s。', esc_html( get_option( 'admin_email' ) ) ),
						'placeholder' => '',
						'default'     => '',
						'desc_tip'    => true,
					),
				),
				array_slice( $this->form_fields, 1, null, true )
			);
		}
	}

	protected function template_args( $plain_text ) {
		return array_merge(
			array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => ! $this->is_customer_email(),
				'plain_text'         => $plain_text,
				'email'              => $this,
				'quote_id'           => $this->quote_id,
				'user'               => $this->user,
			),
			$this->extra
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), 'ultimate-quote/', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), 'ultimate-quote/', $this->template_base );
	}

	/** 管理員信的收件人，加上負責業務（設定有開「通知負責業務」時），重複的會去掉 */
	protected function admin_recipient( $post_id ) {
		$list  = array_map( 'trim', explode( ',', (string) ( $this->get_option( 'recipient' ) ?: get_option( 'admin_email' ) ) ) );
		$owner = ULTQ_Sales::owner_email( $post_id );
		if ( $owner ) {
			$list[] = $owner;
		}
		return implode( ',', array_unique( array_filter( $list ) ) );
	}

	/** 詢價單相關信件共用的佔位符 */
	protected function setup_quote( $post_id ) {
		$this->quote_id = (int) $post_id;
		$this->object   = get_post( $post_id );
		$customer       = ULTQ_Quote_Post::get_customer( $post_id );
		$this->placeholders['{quote_number}']  = ULTQ_Quote_Post::get_number( $post_id );
		$this->placeholders['{customer_name}'] = $customer['name'];
		$this->placeholders['{company}']       = $customer['company'];
		return $customer;
	}

	protected function dispatch() {
		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}
		$this->setup_locale();
		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		$this->restore_locale();
		return $sent;
	}
}
