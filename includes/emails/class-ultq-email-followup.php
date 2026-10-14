<?php
/**
 * 報價待追蹤清單（寄給管理員／負責業務，每日排程 ULTQ_Cron 觸發）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Email_Followup extends ULTQ_Email_Base {

	public $quote_ids = array();

	public function __construct() {
		$this->id             = 'ultq_followup';
		$this->title          = '終極詢價：報價待追蹤';
		$this->description    = '報價寄出幾天後顧客還沒回覆，每天整理成一封清單通知管理員；有開「通知負責業務」時，業務也會收到自己負責的那幾張。';
		$this->template_html  = 'emails/followup.php';
		$this->template_plain = 'emails/plain/followup.php';
		$this->placeholders   = array( '{count}' => '' );
		parent::__construct();
	}

	public function get_default_subject() {
		return '【{site_title}】{count} 張報價還沒有回覆，請追蹤';
	}

	public function get_default_heading() {
		return '報價待追蹤';
	}

	/**
	 * @param int[]  $ids       詢價單 ID
	 * @param string $recipient 指定收件人（業務），空字串＝設定的管理員收件人
	 */
	public function trigger( array $ids, $recipient = '' ) {
		$this->quote_ids                = array_map( 'intval', $ids );
		$this->placeholders['{count}']  = (string) count( $ids );
		$this->recipient                = $recipient ?: ( $this->get_option( 'recipient' ) ?: get_option( 'admin_email' ) );
		$this->extra                    = array( 'quote_ids' => $this->quote_ids );
		return $this->dispatch();
	}
}
