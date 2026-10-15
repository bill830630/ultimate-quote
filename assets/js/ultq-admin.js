/**
 * 終極詢價後台：詢價單編輯頁（即時試算、商品搜尋加入、折讓、會員帶入、統編查詢、寄出確認）、設定頁（詢價範圍、選擇圖片）
 */
jQuery( function ( $ ) {
	var cfg = window.ultqAdmin || {};

	// ─── 詢價單編輯頁 ─────────────────────────────────────────────────────

	var $items = $( '.ultq-items' );
	if ( $items.length ) {
		var decimals = parseInt( cfg.decimals, 10 ) || 0;

		var parseNum = function ( value ) {
			var n = parseFloat( String( value || '' ).replace( /,/g, '' ) );
			return isNaN( n ) ? null : n;
		};
		var round = function ( n ) {
			var f = Math.pow( 10, decimals );
			return Math.round( n * f ) / f;
		};
		var format = function ( n ) {
			return cfg.currency + round( n ).toLocaleString( undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals } );
		};

		// 同 ULTQ_Quote_Post::totals()：品項折後小計 → 整單折讓 → 運費 → 稅
		var recalc = function () {
			var subtotal = 0;
			$items.find( 'tbody .ultq-item' ).each( function () {
				var $row     = $( this );
				var qty      = parseNum( $row.find( '.ultq-qty' ).val() ) || 0;
				var price    = parseNum( $row.find( '.ultq-unit-price' ).val() );
				var discount = Math.min( 100, Math.max( 0, parseNum( $row.find( '.ultq-discount-pct' ).val() ) || 0 ) );
				if ( price === null ) {
					$row.find( '.ultq-line-total' ).text( '—' );
					return;
				}
				var line = round( qty * price * ( 100 - discount ) / 100 );
				subtotal += line;
				$row.find( '.ultq-line-total' ).text( format( line ) );
			} );

			var discountTotal = 0;
			$( '.ultq-totals .ultq-discount' ).each( function () {
				var $row   = $( this );
				var value  = Math.abs( parseNum( $row.find( '.ultq-discount__value' ).val() ) || 0 );
				var amount = $row.find( '.ultq-discount__type' ).val() === 'percent' ? round( subtotal * Math.min( 100, value ) / 100 ) : round( value );
				amount = Math.min( amount, Math.max( 0, subtotal - discountTotal ) );
				discountTotal += amount;
				$row.find( '.ultq-discount__amount' ).text( amount ? '−' + format( amount ) : '' );
			} );

			var shipping = parseNum( $( '.ultq-shipping' ).val() ) || 0;
			var mode     = $( '.ultq-tax-mode' ).val();
			var rate     = parseNum( $( '.ultq-tax-rate' ).val() ) || 0;
			var base     = subtotal - discountTotal + shipping;
			var tax      = 0;
			var total    = base;

			if ( mode === 'incl' ) {
				tax = round( base - base / ( 1 + rate / 100 ) );
			} else if ( mode === 'excl' ) {
				tax   = round( base * rate / 100 );
				total = base + tax;
			}

			$( '.ultq-totals__subtotal' ).text( format( subtotal ) );
			$( '.ultq-totals__tax' ).text( format( tax ) );
			$( '.ultq-totals__tax-label' ).text( mode === 'incl' ? '內含營業稅' : '營業稅' );
			// 用 readonly 不用 disabled：disabled 欄位不會送出，存檔後稅率會被洗掉
			$( '.ultq-tax-rate' ).prop( 'readonly', mode === 'none' );
			$( '.ultq-totals__total' ).text( format( total ) );
		};

		$( document ).on( 'input change', '.ultq-qty, .ultq-unit-price, .ultq-discount-pct, .ultq-shipping, .ultq-tax-mode, .ultq-tax-rate, .ultq-discount__value, .ultq-discount__type', recalc );

		var nextIndex = $items.find( 'tbody .ultq-item' ).length + 1000;
		$( '.ultq-add-line' ).on( 'click', function () {
			var html = $( '#tmpl-ultq-line' ).html().replace( /\{\{i\}\}/g, String( nextIndex++ ) );
			$items.find( 'tbody' ).append( html );
			$items.find( 'tbody .ultq-item:last input[type="text"]:first' ).trigger( 'focus' );
		} );

		$items.on( 'click', '.ultq-remove-line', function () {
			$( this ).closest( '.ultq-item' ).remove();
			recalc();
		} );

		// 商品搜尋選單：選了就加一列，選單清空等下一個
		$( '.ultq-product-search' ).on( 'change', function () {
			var $select   = $( this );
			var productId = $select.val();
			if ( ! productId ) {
				return;
			}
			$.post( cfg.ajaxUrl, {
				action: 'ultq_admin_item',
				nonce: cfg.nonce,
				product_id: productId,
				user_id: $( '#ultq_user_id' ).val() || 0,
				index: nextIndex++
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$items.find( 'tbody' ).append( res.data.html );
					recalc();
				} else {
					window.alert( ( res && res.data && res.data.message ) || cfg.loadFailed ); // eslint-disable-line no-alert
				}
			} ).fail( function () {
				window.alert( cfg.loadFailed ); // eslint-disable-line no-alert
			} ).always( function () {
				$select.val( null ).trigger( 'change.select2' );
			} );
		} );

		// 整單折讓
		var discountIndex = $( '.ultq-totals .ultq-discount' ).length + 1000;
		$( '.ultq-add-discount' ).on( 'click', function () {
			var html = $( '#tmpl-ultq-discount' ).html().replace( /\{\{i\}\}/g, String( discountIndex++ ) );
			$( this ).closest( 'tr' ).before( html );
			$( '.ultq-totals .ultq-discount:last .ultq-discount__value' ).trigger( 'focus' );
		} );
		$( '.ultq-totals' ).on( 'click', '.ultq-remove-discount', function () {
			$( this ).closest( '.ultq-discount' ).remove();
			recalc();
		} );

		// 會員選單：帶入空白的聯絡資料
		$( '#ultq_user_id' ).on( 'change', function () {
			var userId = $( this ).val();
			if ( ! userId ) {
				return;
			}
			$.post( cfg.ajaxUrl, { action: 'ultq_admin_customer', nonce: cfg.nonce, user_id: userId } ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				$.each( res.data, function ( key, value ) {
					var $field = $( '#ultq_c_' + key );
					if ( value && $field.length && ! $field.val() ) {
						$field.val( value ).trigger( 'input' );
					}
				} );
			} );
		} );

		// 有填正確 Email 才能寄出
		var $send = $( '.ultq-btn-send' );
		$( $send.data( 'email-field' ) ).on( 'input change', function () {
			$send.prop( 'disabled', ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( $( this ).val() || '' ).trim() ) );
		} );

		$send.on( 'click', function ( e ) {
			if ( ! window.confirm( cfg.confirmSend ) ) { // eslint-disable-line no-alert
				e.preventDefault();
			}
		} );

		// 統編自動帶入公司名稱、地址（只填空白欄位）
		$( 'input[data-ultq-tax-lookup]' ).on( 'change', function () {
			var $input = $( this );
			var taxId  = String( $input.val() || '' ).replace( /\D/g, '' );
			if ( ! cfg.taxLookup || taxId.length !== 8 ) {
				return;
			}
			$.post( cfg.ajaxUrl, { action: 'ultq_tax_lookup', nonce: cfg.nonce, tax_id: taxId } ).done( function ( res ) {
				var $hint = $input.siblings( '.ultq-tax-hint' );
				if ( ! $hint.length ) {
					$hint = $( '<span class="description ultq-tax-hint"></span>' ).insertAfter( $input );
				}
				if ( ! res || ! res.success ) {
					$hint.text( ( res && res.data && res.data.message ) || '' );
					return;
				}
				$.each( { company: res.data.name, address: res.data.address }, function ( key, value ) {
					var $field = $( $input.data( key ) );
					if ( value && $field.length && ! $field.val() ) {
						$field.val( value );
					}
				} );
				$hint.text( res.data.name + ( res.data.status ? '（' + res.data.status + '）' : '' ) );
			} );
		} );

		recalc();
	}

	// ─── 設定頁：自訂欄位 ─────────────────────────────────────────────────

	var $cf = $( '.ultq-cf-table' );
	if ( $cf.length ) {
		var cfIndex = $cf.find( 'tbody .ultq-cf-row' ).length + 1000;
		$( '.ultq-cf-add' ).on( 'click', function () {
			var html = $( '#tmpl-ultq-cf-row' ).html().replace( /\{\{i\}\}/g, String( cfIndex++ ) );
			$cf.find( 'tbody' ).append( html );
			$cf.find( 'tbody .ultq-cf-row:last input[type="text"]:first' ).trigger( 'focus' );
		} );
		$cf.on( 'click', '.ultq-cf-remove', function () {
			$( this ).closest( '.ultq-cf-row' ).remove();
		} );
	}

	// ─── 設定頁：詢價商品範圍（全站時隱藏分類）───────────────────────────

	var $scope = $( '.ultq-quote-scope' );
	if ( $scope.length ) {
		var toggleScope = function () {
			$scope.find( '.ultq-quote-scope__cats' ).toggle( '1' !== $scope.find( 'input[type=radio]:checked' ).val() );
		};
		$scope.on( 'change', 'input[type=radio]', toggleScope );
		toggleScope();
	}

	// ─── 設定頁：報價單欄位排序 ───────────────────────────────────────────

	if ( $.fn.sortable ) {
		$( '.ultq-layout-sortable' ).sortable( {
			handle: '.ultq-layout__handle',
			axis: 'y',
			containment: 'parent',
			tolerance: 'pointer'
		} );
	}

	// ─── 設定頁：選擇圖片 ─────────────────────────────────────────────────

	$( '.ultq-media-select' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.ultq-media-field' );
		var frame  = wp.media( {
			title: cfg.mediaTitle,
			button: { text: cfg.mediaButton },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
			$field.find( 'input[type="hidden"]' ).val( att.id );
			$field.find( '.ultq-media-preview' ).html( '<img src="' + url + '" style="max-height:80px;width:auto;">' );
			$field.find( '.ultq-media-remove' ).show();
		} );
		frame.open();
	} );

	$( '.ultq-media-remove' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $field = $( this ).closest( '.ultq-media-field' );
		$field.find( 'input[type="hidden"]' ).val( '' );
		$field.find( '.ultq-media-preview' ).empty();
		$( this ).hide();
	} );
} );
