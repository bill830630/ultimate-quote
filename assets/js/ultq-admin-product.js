/**
 * 商品編輯頁：「詢價／B2B」頁籤
 * - 只有詢價商品（勾「僅限詢價」、全站詢價、或屬於設定頁指定的分類）而且是簡單／可變商品時才顯示，跟著畫面上的勾選即時切換
 * - B2B 階梯價新增／移除列
 */
jQuery( function ( $ ) {
	var cfg = window.ultqProduct || { quoteAll: false, categories: [] };

	function isQuoteProduct() {
		if ( cfg.quoteAll || $( '#_ultq_quote_only' ).is( ':checked' ) ) {
			return true;
		}
		var inCategory = false;
		$( '#product_catchecklist input:checked' ).each( function () {
			if ( cfg.categories.indexOf( parseInt( this.value, 10 ) ) !== -1 ) {
				inCategory = true;
			}
		} );
		return inCategory;
	}

	function toggleTab() {
		var type = $( '#product-type' ).val();
		var show = ( type === 'simple' || type === 'variable' ) && isQuoteProduct();
		var $tab = $( '.product_data_tabs .ultq_options' );
		$tab.toggle( show );
		// 正在看這個頁籤時被隱藏，切回第一個看得到的頁籤
		if ( ! show && $tab.hasClass( 'active' ) ) {
			$( '.product_data_tabs li:visible:first a' ).trigger( 'click' );
		}
	}

	$( document ).on( 'change', '#_ultq_quote_only, #product-type, #product_catchecklist input', toggleTab );
	$( document.body ).on( 'woocommerce-product-type-change', toggleTab );
	toggleTab();

	$( document ).on( 'click', '.ultq-tiers__add', function () {
		var $wrap = $( this ).closest( '.ultq-tiers' );
		var name  = $wrap.data( 'name' );
		$wrap.find( 'tbody' ).append(
			'<tr>' +
				'<td><input type="number" min="1" step="1" name="' + name + '[min][]"></td>' +
				'<td><input type="text" class="wc_input_price" name="' + name + '[price][]"></td>' +
				'<td><button type="button" class="button-link ultq-tiers__remove" aria-label="移除">&times;</button></td>' +
			'</tr>'
		);
	} );

	$( document ).on( 'click', '.ultq-tiers__remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} );
