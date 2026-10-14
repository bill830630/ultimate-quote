/**
 * 終極詢價前台：商品頁／商品列表加入詢價清單、詢價清單頁數量／移除／送出
 */
jQuery( function ( $ ) {
	var data = window.ultqData;
	if ( ! data ) {
		return;
	}

	function updateFloat() {
		if ( typeof window.ultqUpdateFloat === 'function' ) {
			window.ultqUpdateFloat();
		}
	}

	function post( action, payload ) {
		return $.post( data.ajaxUrl, $.extend( { action: action, nonce: data.nonce }, payload ) );
	}

	function errorMessage( res ) {
		return ( res && res.data && res.data.message ) || data.i18n.error;
	}

	function showNotice( $after, html, type ) {
		var $wrap = $after.siblings( '.ultq-inline-notice' );
		if ( ! $wrap.length ) {
			$wrap = $( '<div class="ultq-inline-notice"></div>' ).insertAfter( $after );
		}
		$wrap.html( '<div class="woocommerce-' + ( type === 'error' ? 'error' : 'message' ) + '" role="alert">' + html + '</div>' );
	}

	function escapeHtml( text ) {
		return $( '<div>' ).text( String( text || '' ) ).html();
	}

	function addedHtml() {
		return data.i18n.added + ' <a href="' + data.listUrl + '" class="button wc-forward wp-element-button">' + data.i18n.viewList + '</a>';
	}

	// ─── 單一商品頁：capture 階段攔下送出（比 WooCommerce／Blocksy 的 AJAX 加入購物車更早） ───

	function handleSingle( e ) {
		var target = e.target;
		var button = null;
		var form   = null;

		if ( e.type === 'click' ) {
			button = target.closest ? target.closest( '.single_add_to_cart_button' ) : null;
			form   = button ? button.form || button.closest( 'form' ) : null;
		} else {
			form = target;
		}
		if ( ! form || ! form.querySelector || ! form.querySelector( 'input[name="ultq_quote"]' ) ) {
			return;
		}
		button = button || form.querySelector( '.single_add_to_cart_button' );
		// 規格還沒選完時按鈕是 disabled，交給 WooCommerce 原本的提示處理
		if ( button && ( button.classList.contains( 'disabled' ) || button.classList.contains( 'loading' ) ) ) {
			if ( button.classList.contains( 'loading' ) ) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		var payload = {};
		$.each( $( form ).serializeArray(), function ( i, field ) {
			payload[ field.name ] = field.value;
		} );
		var idField = form.querySelector( '[name="add-to-cart"]' );
		payload.product_id = payload.product_id || ( idField ? idField.value : '' ) || ( button ? button.value : '' );
		delete payload[ 'add-to-cart' ];
		delete payload.ultq_quote;

		var $button = $( button );
		$button.addClass( 'loading' );

		post( 'ultq_add', payload )
			.done( function ( res ) {
				if ( res && res.success ) {
					showNotice( $( form ), addedHtml() + ( res.data.adjusted ? '<br>' + escapeHtml( res.data.adjusted ) : '' ), 'success' );
					updateFloat();
					$( document.body ).trigger( 'ultq_added', [ res.data ] );
				} else {
					showNotice( $( form ), errorMessage( res ), 'error' );
				}
			} )
			.fail( function ( xhr ) {
				showNotice( $( form ), errorMessage( xhr.responseJSON ), 'error' );
			} )
			.always( function () {
				$button.removeClass( 'loading' );
			} );
	}

	document.addEventListener( 'click', handleSingle, true );
	document.addEventListener( 'submit', handleSingle, true );

	// ─── 商品列表按鈕 ─────────────────────────────────────────────────────

	$( document.body ).on( 'click', '.ultq-add-to-quote', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( $btn.hasClass( 'loading' ) ) {
			return;
		}
		$btn.removeClass( 'added' ).addClass( 'loading' );
		post( 'ultq_add', { product_id: $btn.data( 'product_id' ), quantity: $btn.data( 'quantity' ) || 1 } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$btn.addClass( 'added' );
					if ( ! $btn.next( '.ultq-view-list' ).length ) {
						$btn.after( ' <a href="' + data.listUrl + '" class="added_to_cart wc-forward ultq-view-list">' + data.i18n.viewList + '</a>' );
					}
					if ( res.data.adjusted && ! $btn.siblings( '.ultq-adjusted' ).length ) {
						$btn.parent().append( '<small class="ultq-adjusted">' + escapeHtml( res.data.adjusted ) + '</small>' );
					}
					updateFloat();
					$( document.body ).trigger( 'ultq_added', [ res.data ] );
				} else {
					window.alert( errorMessage( res ) ); // eslint-disable-line no-alert
				}
			} )
			.fail( function ( xhr ) {
				window.alert( errorMessage( xhr.responseJSON ) ); // eslint-disable-line no-alert
			} )
			.always( function () {
				$btn.removeClass( 'loading' );
			} );
	} );

	// ─── 用 LINE 詢問：點擊當下依已選規格與數量重組預填訊息 ────────────────

	$( document ).on( 'click', '.ultq-line__btn', function () {
		var $btn  = $( this );
		var $form = $btn.closest( '.product, .summary, body' ).find( 'form.cart' ).first();
		var name  = String( $btn.data( 'name' ) || '' );
		var specs = [];
		$form.find( '.variations select' ).each( function () {
			if ( this.value ) {
				specs.push( $( this ).find( 'option:selected' ).text().trim() );
			}
		} );
		if ( specs.length ) {
			name += '（' + specs.join( '、' ) + '）';
		}
		var qty  = parseInt( $form.find( 'input.qty' ).val(), 10 ) || 1;
		var text = String( $btn.data( 'template' ) || '' )
			.split( '{product}' ).join( name )
			.split( '{qty}' ).join( String( qty ) )
			.split( '{url}' ).join( String( $btn.data( 'url' ) || '' ) );
		$btn.attr( 'href', 'https://line.me/R/oaMessage/' + encodeURIComponent( String( $btn.data( 'oa' ) ) ) + '/?' + encodeURIComponent( text ) );
	} );

	// ─── 統編自動帶入公司名稱（詢價表單、B2B 申請） ─────────────────────────
	// 只填空白欄位，或之前由這裡自動帶入、顧客沒改過的欄位

	var taxTimer = null;
	$( document ).on( 'input change', 'input[data-ultq-tax-lookup]', function () {
		if ( ! data.taxLookup ) {
			return;
		}
		var $input = $( this );
		var taxId  = String( $input.val() || '' ).replace( /\D/g, '' );
		clearTimeout( taxTimer );
		if ( taxId.length !== 8 || taxId === $input.data( 'ultqLooked' ) ) {
			return;
		}
		taxTimer = setTimeout( function () {
			$input.data( 'ultqLooked', taxId );
			post( 'ultq_tax_lookup', { tax_id: taxId } ).done( function ( res ) {
				var $hint = $input.siblings( '.ultq-tax-hint' );
				if ( ! $hint.length ) {
					$hint = $( '<small class="ultq-tax-hint"></small>' ).insertAfter( $input );
				}
				if ( ! res || ! res.success ) {
					$hint.text( errorMessage( res ) );
					return;
				}
				$.each( { company: res.data.name, address: res.data.address }, function ( key, value ) {
					var $field = $( $input.data( key ) );
					if ( value && $field.length && ( ! $field.val() || $field.data( 'ultqAuto' ) === $field.val() ) ) {
						$field.val( value ).data( 'ultqAuto', value ).trigger( 'change' );
					}
				} );
				$hint.text( data.i18n.taxFound + res.data.name + ( res.data.status ? '（' + res.data.status + '）' : '' ) );
			} );
		}, 300 );
	} );

	// ─── 詢價清單頁 ───────────────────────────────────────────────────────

	var $wrap = $( '.ultq-quote-list-wrap' );
	if ( ! $wrap.length ) {
		return;
	}

	function listNotice( html, type ) {
		var $n = $wrap.find( '.ultq-notices' );
		$n.html( html ? '<div class="woocommerce-' + ( type === 'error' ? 'error' : 'message' ) + '">' + html + '</div>' : '' );
		if ( html && $n.length ) {
			$( 'html, body' ).animate( { scrollTop: $n.offset().top - 120 }, 200 );
		}
	}

	// 只換掉品項表，不動下方已經填一半的聯絡資料；清單變空時才整塊換掉
	function applyList( res ) {
		if ( ! res || ! res.success ) {
			listNotice( errorMessage( res ), 'error' );
			return;
		}
		var $html  = $( '<div>' ).html( res.data.html );
		var $table = $html.find( '.ultq-list__table' );
		if ( $table.length && $wrap.find( '.ultq-list__table' ).length ) {
			$wrap.find( '.ultq-list__table' ).replaceWith( $table );
		} else {
			$wrap.html( res.data.html );
		}
		// 數量因最低訂購量／包裝倍數被調整時告訴顧客
		listNotice( res.data.adjusted ? escapeHtml( res.data.adjusted ) : '', 'message' );
		// 重繪後的數量欄要請主題重新綁定（Blocksy 的＋／－按鈕只綁在初始化時已存在的元素上）
		if ( window.ctEvents && typeof window.ctEvents.trigger === 'function' ) {
			window.ctEvents.trigger( 'blocksy:frontend:init' );
		}
		updateFloat();
	}

	function rowPayload( $row ) {
		return {
			key: $row.data( 'key' ),
			qty: $row.find( '.ultq-qty' ).val(),
			note: $row.find( '.ultq-note' ).val()
		};
	}

	var timer = null;
	$wrap.on( 'change', '.ultq-qty, .ultq-note', function () {
		var $row = $( this ).closest( '.ultq-list__item' );
		clearTimeout( timer );
		timer = setTimeout( function () {
			$wrap.addClass( 'is-loading' );
			post( 'ultq_update', rowPayload( $row ) )
				.done( applyList )
				.fail( function ( xhr ) {
					listNotice( errorMessage( xhr.responseJSON ), 'error' );
				} )
				.always( function () {
					$wrap.removeClass( 'is-loading' );
				} );
		}, 300 );
	} );

	$wrap.on( 'click', '.ultq-remove', function () {
		if ( ! window.confirm( data.i18n.confirmDel ) ) { // eslint-disable-line no-alert
			return;
		}
		var $row = $( this ).closest( '.ultq-list__item' );
		$wrap.addClass( 'is-loading' );
		post( 'ultq_remove', { key: $row.data( 'key' ) } )
			.done( applyList )
			.fail( function ( xhr ) {
				listNotice( errorMessage( xhr.responseJSON ), 'error' );
			} )
			.always( function () {
				$wrap.removeClass( 'is-loading' );
			} );
	} );

	$wrap.on( 'submit', '.ultq-form', function ( e ) {
		e.preventDefault();
		var $form   = $( this );
		var $submit = $form.find( '.ultq-submit' );
		if ( $submit.prop( 'disabled' ) ) {
			return;
		}

		// 有附件時改用 FormData（multipart）送出，先在前端擋掉張數／大小超過的
		var fileInput = $form.find( 'input[type="file"]' ).get( 0 );
		var files     = fileInput && fileInput.files ? fileInput.files : [];
		var maxFiles  = parseInt( $( fileInput ).data( 'max-files' ), 10 ) || 0;
		var maxBytes  = parseInt( $( fileInput ).data( 'max-bytes' ), 10 ) || 0;
		if ( maxFiles && files.length > maxFiles ) {
			listNotice( escapeHtml( data.i18n.tooMany.replace( '%d', maxFiles ) ), 'error' );
			return;
		}
		for ( var f = 0; f < files.length; f++ ) {
			if ( maxBytes && files[ f ].size > maxBytes ) {
				listNotice( escapeHtml( data.i18n.tooLarge.replace( '%s', files[ f ].name ) ), 'error' );
				return;
			}
		}

		var request;
		if ( files.length && window.FormData ) {
			var formData = new FormData( $form.get( 0 ) );
			formData.append( 'action', 'ultq_submit' );
			formData.append( 'nonce', data.nonce );
			request = $.ajax( { url: data.ajaxUrl, type: 'POST', data: formData, processData: false, contentType: false } );
		} else {
			var payload = {};
			$.each( $form.serializeArray(), function ( i, field ) {
				payload[ field.name ] = field.value;
			} );
			request = post( 'ultq_submit', payload );
		}

		var label = $submit.text();
		$submit.prop( 'disabled', true ).text( data.i18n.submitting );
		listNotice( '' );

		request
			.done( function ( res ) {
				if ( res && res.success ) {
					$wrap.html( res.data.html );
					updateFloat();
					$( 'html, body' ).animate( { scrollTop: $wrap.offset().top - 120 }, 200 );
				} else {
					listNotice( errorMessage( res ), 'error' );
				}
			} )
			.fail( function ( xhr ) {
				listNotice( errorMessage( xhr.responseJSON ), 'error' );
			} )
			.always( function () {
				$submit.prop( 'disabled', false ).text( label );
			} );
	} );
} );
