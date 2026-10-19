<?php
/**
 * Excel 報價單（.xlsx）：內容、欄位開關與排序都跟 PDF 一樣（共用 ULTQ_PDF::data()），金額是真正的數字可以直接加總
 *
 * 不靠外部函式庫，用 ZipArchive 寫最小的 OOXML（一張工作表、inline 字串、少量樣式），A4 直式、列印時縮成一頁寬。
 *
 * 下載入口（權限同 PDF）：
 * - 後台：admin-post.php?action=ultq_xlsx&post=ID[&rev=N]（nonce 同 PDF 預覽）
 * - 顧客：?ultq_xlsx=ID&token=xxx，或登入本人；設定「顧客可下載 Excel 報價單」關閉時只有後台能下載
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Excel {

	// 樣式（cellXfs 的順序，見 styles_xml()）
	const S_NORMAL   = 0;
	const S_BOLD     = 1;
	const S_TITLE    = 2;
	const S_COMPANY  = 3;
	const S_MUTED    = 4;
	const S_TH       = 5;
	const S_TD       = 6;
	const S_TD_NUM   = 7;
	const S_LABEL    = 8;
	const S_NUM      = 9;
	const S_NUM_BOLD = 10;
	const S_WRAP     = 11;
	const S_TD_C     = 12;
	const S_SECTION  = 13;
	const S_TD_QTY   = 14;
	const S_RIGHT    = 15;

	private $rows   = array();
	private $merges = array();
	private $widths = array();
	private $ncols  = 1;

	public static function init() {
		add_action( 'admin_post_ultq_xlsx', array( __CLASS__, 'handle_admin' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_customer' ) );
	}

	public static function customer_enabled() {
		return (bool) ULTQ_Settings::get( 'customer_excel', 1 );
	}

	public static function admin_url( $post_id, $rev = 0 ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ultq_xlsx&post=' . (int) $post_id . ( $rev ? '&rev=' . (int) $rev : '' ) ), 'ultq_pdf_' . (int) $post_id );
	}

	/** 顧客下載網址；$token 空字串時要登入本人才能下載 */
	public static function customer_url( $post_id, $token = '' ) {
		$args = array( 'ultq_xlsx' => (int) $post_id );
		if ( $token ) {
			$args['token'] = $token;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function filename( $post_id, $rev = 0 ) {
		return '報價單-' . ULTQ_Quote_Post::get_number( $post_id ) . ( $rev > 1 ? '-v' . $rev : '' ) . '.xlsx';
	}

	// ─── 下載 ──────────────────────────────────────────────────────────────

	public static function handle_admin() {
		$post_id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'ultq_pdf_' . $post_id );
		if ( ! ULTQ_Sales::can_manage_quote( $post_id ) ) {
			wp_die( '權限不足。', '', array( 'response' => 403 ) );
		}
		$rev = absint( $_GET['rev'] ?? 0 );
		if ( $rev && ULTQ_Quote_Post::get_version( $post_id, $rev ) ) {
			self::stream( $post_id, $rev );
		}
		ULTQ_PDF::$issue_today = true;
		self::stream( $post_id );
	}

	public static function handle_customer() {
		if ( empty( $_GET['ultq_xlsx'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post_id = absint( $_GET['ultq_xlsx'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$token   = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::customer_enabled() || ! ULTQ_PDF::customer_can_view( $post_id, $token ) ) {
			wp_die( '找不到這份報價單，或連結已失效。', '報價單', array( 'response' => 404 ) );
		}
		self::stream( $post_id, ULTQ_PDF::customer_rev( $post_id ) );
	}

	private static function stream( $post_id, $rev = 0 ) {
		$path = self::build( $post_id, $rev );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode( self::filename( $post_id, $rev ) ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $path );
		exit;
	}

	/**
	 * 產生 xlsx 暫存檔（呼叫端用完要刪）
	 *
	 * @return string|WP_Error 檔案路徑
	 */
	public static function build( $post_id, $rev = 0 ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ultq_xlsx', '主機沒有啟用 PHP 的 zip 擴充功能，無法產生 Excel 檔，請改用 PDF。' );
		}
		$dir = ULTQ_Helper::tmp_dir();
		ULTQ_Install::ensure_private_dir( $dir );
		$path = trailingslashit( $dir ) . 'quote-' . wp_generate_password( 12, false ) . '.xlsx';

		$sheet = new self();
		$sheet->fill( ULTQ_PDF::data( $post_id, $rev ) );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'ultq_xlsx', 'Excel 檔無法寫入暫存目錄。' );
		}
		foreach ( $sheet->package() as $name => $xml ) {
			$zip->addFromString( $name, $xml );
		}
		$zip->close();
		return $path;
	}

	// ─── 版面 ──────────────────────────────────────────────────────────────

	private function fill( array $d ) {
		$labels  = $d['labels'];
		$columns = $d['layout']['items'];
		$width   = array(
			'col_no'       => 9,
			'col_sku'      => 14,
			'col_desc'     => 42,
			'col_qty'      => 9,
			'col_unit'     => 7,
			'col_price'    => 13,
			'col_discount' => 9,
			'col_amount'   => 15,
		);
		$this->ncols = max( 3, count( $columns ) );
		foreach ( array_values( $columns ) as $i => $col ) {
			$this->widths[ $i ] = $width[ $col ] ?? 12;
		}
		for ( $i = count( $columns ); $i < $this->ncols; $i++ ) {
			$this->widths[ $i ] = 14;
		}

		// 自己公司
		$this->line( $d['company']['name'], self::S_COMPANY, 22 );
		$company = array_filter( array(
			$d['company']['tax_id'] ? $labels['tax_id'] . '：' . $d['company']['tax_id'] : '',
			$d['company']['address'],
			implode( '　', array_filter( array( $d['company']['phone'] ? $labels['phone'] . '：' . $d['company']['phone'] : '', $d['company']['email'] ) ) ),
		) );
		foreach ( $company as $text ) {
			$this->line( $text, self::S_MUTED );
		}
		$this->blank();
		$this->line( $labels['doc_title'], self::S_TITLE, 28 );

		// 報價資訊
		$custom = $d['custom'];
		$pairs  = function ( array $keys, array $values, $skip_empty ) use ( $labels, $custom ) {
			$rows = array();
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $values ) ) {
					if ( ! $skip_empty || '' !== trim( (string) $values[ $key ] ) ) {
						$rows[] = array( $labels[ $key ], (string) $values[ $key ] );
					}
				} elseif ( isset( $custom[ $key ] ) ) {
					$rows[] = $custom[ $key ];
				}
			}
			return $rows;
		};
		foreach ( $pairs( $d['layout']['header'], array(
			'quote_no'    => $d['number'],
			'quote_date'  => $d['date'],
			'valid_until' => $d['valid_until'],
			'sales_name'  => $d['sales']['name'],
			'sales_phone' => $d['sales']['phone'],
			'sales_email' => $d['sales']['email'],
		), true ) as $pair ) {
			$this->pair( $pair[0], $pair[1] );
		}

		// 客戶資料
		$customer = $d['customer'];
		$info     = $pairs( $d['layout']['customer'], array(
			'customer_name' => $customer['company'] ?: $customer['name'],
			'tax_id'        => $customer['tax_id'],
			'contact'       => $customer['name'],
			'phone'         => $customer['phone'],
			'email'         => $customer['email'],
			'address'       => $customer['address'],
		), false );
		if ( $info ) {
			$this->blank();
			$this->line( $labels['customer'], self::S_SECTION );
			foreach ( $info as $pair ) {
				$this->pair( $pair[0], $pair[1] );
			}
		}

		// 品項表
		$this->blank();
		$head = array();
		foreach ( $columns as $col ) {
			$head[] = array( $labels[ $col ], self::S_TH );
		}
		$this->row( $head, 20 );
		foreach ( $d['items'] as $i => $item ) {
			$cells = array();
			$lines = 1;
			foreach ( array_values( $columns ) as $c => $col ) {
				switch ( $col ) {
					case 'col_no':
						$cells[] = array( $i + 1, self::S_TD_C );
						break;
					case 'col_sku':
						$cells[] = array( $item['sku'], self::S_TD );
						break;
					case 'col_desc':
						$sub = array();
						if ( $d['options']['sku_inline'] && $item['sku'] ) {
							$sub[] = $labels['col_sku'] . ' ' . $item['sku'];
						}
						foreach ( $item['attributes'] as $label => $value ) {
							$sub[] = $label . '：' . $value;
						}
						if ( $item['line_note'] ) {
							$sub[] = $item['line_note'];
						}
						$text    = $item['name'] . ( $sub ? "\n" . implode( '｜', $sub ) : '' );
						$lines   = max( $lines, self::count_lines( $text, $this->widths[ $c ] ) );
						$cells[] = array( $text, self::S_TD );
						break;
					case 'col_qty':
						$cells[] = array( (float) $item['qty'], self::S_TD_QTY );
						break;
					case 'col_unit':
						$cells[] = array( $item['unit'], self::S_TD_C );
						break;
					case 'col_price':
						$cells[] = null === $item['unit_price'] ? array( '—', self::S_TD_C ) : array( (float) $item['unit_price'], self::S_TD_NUM );
						break;
					case 'col_discount':
						$cells[] = array( $item['discount'] > 0 ? ULTQ_Quote_Post::discount_label( $item['discount'] ) : '', self::S_TD_C );
						break;
					case 'col_amount':
						$line    = ULTQ_Quote_Post::line_total( $item );
						$cells[] = null === $line ? array( '—', self::S_TD_C ) : array( (float) $line, self::S_TD_NUM );
						break;
				}
			}
			$this->row( $cells, $lines > 1 ? 15 * $lines + 3 : null );
		}

		// 金額（靠右兩欄）
		$totals = $d['totals'];
		$quote  = $d['quote'];
		$this->total( $labels['subtotal'], $totals['subtotal'] );
		foreach ( $totals['discounts'] as $row ) {
			$this->total( $row['label'], -$row['amount'] );
		}
		if ( $totals['shipping'] ) {
			$this->total( $labels['shipping'], $totals['shipping'] );
		}
		if ( 'excl' === $quote['tax_mode'] || 'incl' === $quote['tax_mode'] ) {
			$this->total( ( 'incl' === $quote['tax_mode'] ? $labels['tax_incl'] : $labels['tax'] ) . '（' . wc_format_localized_decimal( $quote['tax_rate'] ) . '%）', $totals['tax'] );
		} else {
			$this->total( $labels['tax_none'], '免稅' );
		}
		$this->total( $labels['total'], $totals['total'], true );
		foreach ( $d['amount_words'] as $words ) {
			$this->right_line( $labels['amount_words'] . '　' . $words );
		}

		// 付款條件、匯款資訊、自訂欄位（依排序）
		$footer = array();
		foreach ( $d['layout']['footer'] as $key ) {
			if ( 'bank' === $key ) {
				foreach ( $d['bank'] as $row ) {
					$footer[] = array( $labels['bank'] . '－' . $row[0], $row[1] );
				}
				continue;
			}
			foreach ( $pairs( array( $key ), array( 'payment_terms' => $quote['payment_terms'] ), true ) as $pair ) {
				$footer[] = $pair;
			}
		}
		if ( $footer ) {
			$this->blank();
			foreach ( $footer as $pair ) {
				$this->pair( $pair[0], $pair[1] );
			}
		}

		if ( '' !== trim( (string) $quote['note'] ) ) {
			$this->blank();
			$this->line( $labels['note'], self::S_SECTION );
			$this->line( $quote['note'], self::S_WRAP, null, true );
		}
		if ( $d['accepted'] ) {
			$this->blank();
			$this->line( sprintf( '已於 %1$s 由 %2$s 線上確認接受', wp_date( 'Y-m-d H:i', (int) $d['accepted']['time'] ), $d['accepted']['name'] ), self::S_BOLD );
		}
		if ( '' !== trim( (string) $d['footer'] ) ) {
			$this->blank();
			$this->line( $d['footer'], self::S_MUTED, null, true );
		}
	}

	/** 粗估換行後的行數（欄寬以半形字元計，全形字算 2） */
	private static function count_lines( $text, $width ) {
		$lines = 0;
		foreach ( explode( "\n", (string) $text ) as $para ) {
			$len    = mb_strwidth( $para, 'UTF-8' );
			$lines += max( 1, (int) ceil( $len / max( 1, $width - 1 ) ) );
		}
		return $lines;
	}

	private function blank() {
		$this->rows[] = array( 'cells' => array(), 'height' => 8 );
	}

	/** 一整列合併的文字 */
	private function line( $text, $style, $height = null, $wrap = false ) {
		$row = count( $this->rows ) + 1;
		if ( $this->ncols > 1 ) {
			$this->merges[] = 'A' . $row . ':' . self::col( $this->ncols - 1 ) . $row;
		}
		if ( $wrap && null === $height ) {
			$lines  = self::count_lines( $text, array_sum( $this->widths ) );
			$height = $lines > 1 ? 15 * $lines + 3 : null;
		}
		$this->rows[] = array( 'cells' => array( array( (string) $text, $style ) ), 'height' => $height );
	}

	/** 靠右、跨最後三欄的一行字（大寫金額） */
	private function right_line( $text ) {
		$row   = count( $this->rows ) + 1;
		$first = max( 0, $this->ncols - 3 );
		$this->merges[] = self::col( $first ) . $row . ':' . self::col( $this->ncols - 1 ) . $row;
		$cells          = array_fill( 0, $first + 1, null );
		$cells[ $first ] = array( (string) $text, self::S_RIGHT );
		$this->rows[]   = array( 'cells' => $cells, 'height' => null );
	}

	/** 名稱（最前面夠寬的幾欄合併）＋值（其餘欄合併） */
	private function pair( $label, $value ) {
		$row   = count( $this->rows ) + 1;
		$split = 1;
		while ( $split < $this->ncols - 1 && array_sum( array_slice( $this->widths, 0, $split ) ) < 9 ) {
			$split++;
		}
		if ( $split > 1 ) {
			$this->merges[] = 'A' . $row . ':' . self::col( $split - 1 ) . $row;
		}
		if ( $this->ncols - $split > 1 ) {
			$this->merges[] = self::col( $split ) . $row . ':' . self::col( $this->ncols - 1 ) . $row;
		}
		$cells          = array_fill( 0, $split, null );
		$cells[0]       = array( (string) $label, self::S_LABEL );
		$cells[ $split ] = array( (string) $value, self::S_NORMAL );
		$this->rows[]   = array( 'cells' => $cells, 'height' => null );
	}

	/** 金額列：倒數第二欄名稱、最後一欄金額 */
	private function total( $label, $value, $grand = false ) {
		$cells                       = array_fill( 0, $this->ncols, null );
		$cells[ $this->ncols - 2 ]   = array( (string) $label, $grand ? self::S_BOLD : self::S_LABEL );
		$cells[ $this->ncols - 1 ]   = is_numeric( $value ) ? array( (float) $value, $grand ? self::S_NUM_BOLD : self::S_NUM ) : array( (string) $value, self::S_NORMAL );
		$this->rows[]                = array( 'cells' => $cells, 'height' => null );
	}

	private function row( array $cells, $height = null ) {
		$this->rows[] = array( 'cells' => $cells, 'height' => $height );
	}

	private static function col( $index ) {
		$name = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$name = chr( 65 + ( $n - 1 ) % 26 ) . $name;
		}
		return $name;
	}

	private static function esc( $text ) {
		// 去掉 XML 不允許的控制字元
		$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $text );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	// ─── OOXML ─────────────────────────────────────────────────────────────

	private function package() {
		return array(
			'[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
				'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
				'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
				'<Default Extension="xml" ContentType="application/xml"/>' .
				'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
				'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
				'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
				'</Types>',
			'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
				'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
				'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
				'</Relationships>',
			'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
				'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
				'<sheets><sheet name="報價單" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
				'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
				'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
				'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
				'</Relationships>',
			'xl/styles.xml'              => $this->styles_xml(),
			'xl/worksheets/sheet1.xml'   => $this->sheet_xml(),
		);
	}

	private function styles_xml() {
		$accent   = 'FF' . strtoupper( ltrim( ULTQ_Helper::accent_hex(), '#' ) );
		$decimals = wc_get_price_decimals();
		$format   = '#,##0' . ( $decimals > 0 ? '.' . str_repeat( '0', $decimals ) : '' );
		$font     = 'Microsoft JhengHei';
		$fonts    = array(
			'<font><sz val="11"/><name val="' . $font . '"/></font>',
			'<font><b/><sz val="11"/><name val="' . $font . '"/></font>',
			'<font><b/><sz val="18"/><color rgb="' . $accent . '"/><name val="' . $font . '"/></font>',
			'<font><b/><sz val="14"/><name val="' . $font . '"/></font>',
			'<font><sz val="9"/><color rgb="FF666666"/><name val="' . $font . '"/></font>',
			'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="' . $font . '"/></font>',
			'<font><sz val="10"/><color rgb="FF666666"/><name val="' . $font . '"/></font>',
			'<font><b/><sz val="11"/><color rgb="' . $accent . '"/><name val="' . $font . '"/></font>',
			'<font><sz val="10"/><name val="' . $font . '"/></font>',
		);
		$fills    = array(
			'<fill><patternFill patternType="none"/></fill>',
			'<fill><patternFill patternType="gray125"/></fill>',
			'<fill><patternFill patternType="solid"><fgColor rgb="' . $accent . '"/><bgColor indexed="64"/></patternFill></fill>',
		);
		$borders  = array(
			'<border><left/><right/><top/><bottom/><diagonal/></border>',
			'<border><left style="thin"><color rgb="FFDDDDDD"/></left><right style="thin"><color rgb="FFDDDDDD"/></right><top style="thin"><color rgb="FFDDDDDD"/></top><bottom style="thin"><color rgb="FFDDDDDD"/></bottom><diagonal/></border>',
			'<border><left/><right/><top style="medium"><color rgb="' . $accent . '"/></top><bottom/><diagonal/></border>',
		);
		$top      = '<alignment vertical="top" wrapText="1"/>';
		// fontId, fillId, borderId, numFmtId, alignment
		$xfs = array(
			self::S_NORMAL   => array( 0, 0, 0, 0, '<alignment vertical="top" wrapText="1"/>' ),
			self::S_BOLD     => array( 1, 0, 2, 0, '<alignment horizontal="right" vertical="top"/>' ),
			self::S_TITLE    => array( 2, 0, 0, 0, '<alignment vertical="center"/>' ),
			self::S_COMPANY  => array( 3, 0, 0, 0, '<alignment vertical="center"/>' ),
			self::S_MUTED    => array( 4, 0, 0, 0, $top ),
			self::S_TH       => array( 5, 2, 1, 0, '<alignment horizontal="center" vertical="center" wrapText="1"/>' ),
			self::S_TD       => array( 8, 0, 1, 0, $top ),
			self::S_TD_NUM   => array( 8, 0, 1, 164, '<alignment horizontal="right" vertical="top"/>' ),
			self::S_LABEL    => array( 6, 0, 0, 0, '<alignment horizontal="left" vertical="top"/>' ),
			self::S_NUM      => array( 0, 0, 0, 164, '<alignment horizontal="right" vertical="top"/>' ),
			self::S_NUM_BOLD => array( 1, 0, 2, 164, '<alignment horizontal="right" vertical="top"/>' ),
			self::S_WRAP     => array( 8, 0, 0, 0, $top ),
			self::S_TD_C     => array( 8, 0, 1, 0, '<alignment horizontal="center" vertical="top"/>' ),
			self::S_SECTION  => array( 7, 0, 0, 0, '<alignment vertical="center"/>' ),
			self::S_TD_QTY   => array( 8, 0, 1, 0, '<alignment horizontal="right" vertical="top"/>' ),
			self::S_RIGHT    => array( 6, 0, 0, 0, '<alignment horizontal="right" vertical="top"/>' ),
		);
		ksort( $xfs );
		$xf_xml = '';
		foreach ( $xfs as $xf ) {
			$xf_xml .= sprintf(
				'<xf numFmtId="%4$d" fontId="%1$d" fillId="%2$d" borderId="%3$d" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1">%5$s</xf>',
				$xf[0], $xf[1], $xf[2], $xf[3], $xf[4]
			);
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<numFmts count="1"><numFmt numFmtId="164" formatCode="' . self::esc( $format ) . '"/></numFmts>' .
			'<fonts count="' . count( $fonts ) . '">' . implode( '', $fonts ) . '</fonts>' .
			'<fills count="' . count( $fills ) . '">' . implode( '', $fills ) . '</fills>' .
			'<borders count="' . count( $borders ) . '">' . implode( '', $borders ) . '</borders>' .
			'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
			'<cellXfs count="' . count( $xfs ) . '">' . $xf_xml . '</cellXfs>' .
			'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
			'</styleSheet>';
	}

	private function sheet_xml() {
		$cols = '';
		foreach ( $this->widths as $i => $w ) {
			$cols .= sprintf( '<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $i + 1, $w );
		}
		$data = '';
		foreach ( $this->rows as $r => $row ) {
			$num   = $r + 1;
			$attrs = ' r="' . $num . '"' . ( $row['height'] ? ' ht="' . (float) $row['height'] . '" customHeight="1"' : '' );
			$cells = '';
			foreach ( $row['cells'] as $c => $cell ) {
				if ( null === $cell ) {
					continue;
				}
				$ref = self::col( $c ) . $num;
				if ( is_int( $cell[0] ) || is_float( $cell[0] ) ) {
					$cells .= sprintf( '<c r="%s" s="%d"><v>%s</v></c>', $ref, $cell[1], (string) ( 0 + $cell[0] ) );
				} elseif ( '' !== $cell[0] ) {
					$cells .= sprintf( '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $cell[1], self::esc( $cell[0] ) );
				} else {
					$cells .= sprintf( '<c r="%s" s="%d"/>', $ref, $cell[1] );
				}
			}
			$data .= '<row' . $attrs . '>' . $cells . '</row>';
		}
		$merges = '';
		if ( $this->merges ) {
			$merges = '<mergeCells count="' . count( $this->merges ) . '">';
			foreach ( $this->merges as $ref ) {
				$merges .= '<mergeCell ref="' . $ref . '"/>';
			}
			$merges .= '</mergeCells>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' .
			'<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>' .
			'<sheetFormatPr defaultRowHeight="16.5"/>' .
			'<cols>' . $cols . '</cols>' .
			'<sheetData>' . $data . '</sheetData>' .
			$merges .
			'<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>' .
			'<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>' .
			'</worksheet>';
	}
}
