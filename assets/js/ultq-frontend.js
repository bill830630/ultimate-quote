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

	// ─── 用 LINE 詢問：手機點擊當下依已選規格與數量重組預填訊息 ──────────────
	// oaMessage 只有手機 LINE App 接得住，電腦會被轉到 line.me 首頁，所以電腦維持 PHP 給的官方帳號頁連結

	function isMobileDevice() {
		var ua = navigator.userAgent || '';
		// iPadOS 13+ 的 Safari 偽裝成 Macintosh，用觸控點數判斷
		return /Android|iPhone|iPad|iPod|Mobile/i.test( ua ) || ( /Macintosh/.test( ua ) && navigator.maxTouchPoints > 1 );
	}

	$( document ).on( 'click', '.ultq-line__btn', function () {
		if ( ! isMobileDevice() ) {
			return;
		}
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
				if ( $input.prop( 'disabled' ) ) {
					return;
				}
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

	// 兩個表單共用戶別規則；放在詢價清單的 early return 前，會員申請頁也要執行。
	$( document ).on( 'change', '.ultq-customer-fields input[name="customer_type"]', function () {
		var $fields = $( this ).closest( '.ultq-customer-fields' );
		var rules = $fields.data( 'rules' )[ this.value ] || {};
		$fields.find( '[data-customer-field]' ).each( function () {
			var $row = $( this );
			var rule = rules[ $row.data( 'customer-field' ) ] || 'hidden';
			var hidden = rule === 'hidden';
			var required = rule === 'required';
			$row.prop( 'hidden', hidden );
			$row.find( 'input, textarea' ).prop( 'disabled', hidden ).prop( 'required', required ).attr( 'aria-required', String( required ) );
			$row.find( 'abbr.required' ).prop( 'hidden', ! required );
		} );
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

	// ─── 附件：可累加選取／拖放，保留 File 物件，移除後不會隨 FormData 送出 ───
	function uploadFiles( input ) {
		return input ? ( input.ultqFiles || Array.prototype.slice.call( input.files || [] ) ) : [];
	}

	function uploadSize( bytes ) {
		if ( bytes < 1024 ) { return bytes + ' B'; }
		if ( bytes < 1024 * 1024 ) { return ( bytes / 1024 ).toFixed( 1 ) + ' KB'; }
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	function mergeUploadFiles( current, added, accept, maxFiles, maxBytes ) {
		var next = current.slice();
		var allowed = accept.toLowerCase().split( ',' );
		for ( var i = 0; i < added.length; i++ ) {
			var file = added[ i ];
			var dot = file.name.lastIndexOf( '.' );
			if ( dot < 0 || allowed.indexOf( file.name.slice( dot ).toLowerCase() ) === -1 ) {
				return { error: '「' + file.name + '」的格式不支援，請查看可上傳的檔案格式。' };
			}
			if ( maxBytes && file.size > maxBytes ) {
				return { error: '「' + file.name + '」超過單檔上限 ' + uploadSize( maxBytes ) + '。' };
			}
			var duplicate = next.some( function ( saved ) {
				return saved.name === file.name && saved.size === file.size && saved.lastModified === file.lastModified;
			} );
			if ( ! duplicate ) { next.push( file ); }
		}
		if ( maxFiles && next.length > maxFiles ) {
			return { error: '最多可加入 ' + maxFiles + ' 個附件，請先移除不需要的檔案。' };
		}
		return { files: next };
	}

	function renderUploads( input ) {
		var $upload = $( input ).closest( '.ultq-upload' );
		var files = uploadFiles( input );
		var $list = $upload.find( '.ultq-upload__list' ).empty().prop( 'hidden', ! files.length );
		var total = 0;
		files.forEach( function ( file, index ) {
			total += file.size;
			var $row = $( '<li class="ultq-upload__file"></li>' );
			var $info = $( '<span class="ultq-upload__file-info"></span>' );
			$( '<span class="ultq-upload__name"></span>' ).text( file.name ).appendTo( $info );
			$( '<small></small>' ).text( uploadSize( file.size ) ).appendTo( $info );
			$info.appendTo( $row );
			$( '<button type="button" class="ultq-upload__remove">移除</button>' ).attr( { 'data-index': index, 'aria-label': '移除附件：' + file.name } ).appendTo( $row );
			$row.appendTo( $list );
		} );
		$upload.find( '.ultq-upload__status' ).text( files.length ? '已選 ' + files.length + ' 個附件 · 共 ' + uploadSize( total ) : '尚未選擇附件' );
		$upload.find( '.ultq-upload__prompt' ).text( files.length ? '點選或拖曳，繼續加入檔案' : '點選或拖曳檔案至此' );
	}

	function addUploads( input, added ) {
		var $upload = $( input ).closest( '.ultq-upload' );
		var result = mergeUploadFiles( input.ultqFiles || [], Array.prototype.slice.call( added ), input.accept, parseInt( $( input ).data( 'max-files' ), 10 ) || 0, parseInt( $( input ).data( 'max-bytes' ), 10 ) || 0 );
		$upload.find( '.ultq-upload__error' ).text( result.error || '' ).prop( 'hidden', ! result.error );
		if ( ! result.error ) { input.ultqFiles = result.files; }
		// 清空原生選取，允許移除後再次選同一檔；實際送出使用上方累積的 File 清單。
		input.value = '';
		renderUploads( input );
	}

	$wrap.on( 'change', '.ultq-upload__input', function () { addUploads( this, this.files ); } );
	$wrap.on( 'dragover', '.ultq-upload__dropzone', function ( e ) {
		e.preventDefault();
		e.originalEvent.dataTransfer.dropEffect = 'copy';
		$( this ).addClass( 'is-dragging' );
	} );
	$wrap.on( 'dragleave', '.ultq-upload__dropzone', function ( e ) {
		if ( ! this.contains( e.relatedTarget ) ) { $( this ).removeClass( 'is-dragging' ); }
	} );
	$wrap.on( 'drop', '.ultq-upload__dropzone', function ( e ) {
		e.preventDefault();
		$( this ).removeClass( 'is-dragging' );
		addUploads( $( this ).closest( '.ultq-upload' ).find( 'input[type="file"]' ).get( 0 ), e.originalEvent.dataTransfer.files );
	} );
	$wrap.on( 'click', '.ultq-upload__remove', function () {
		var $upload = $( this ).closest( '.ultq-upload' );
		var input = $upload.find( 'input[type="file"]' ).get( 0 );
		var index = Number( $( this ).attr( 'data-index' ) );
		input.ultqFiles = uploadFiles( input ).filter( function ( file, i ) { return i !== index; } );
		$upload.find( '.ultq-upload__error' ).empty().prop( 'hidden', true );
		renderUploads( input );
		$( input ).trigger( 'focus' );
	} );

	$wrap.on( 'submit', '.ultq-form', function ( e ) {
		e.preventDefault();
		var $form   = $( this );
		var $submit = $form.find( '.ultq-submit' );
		if ( $submit.prop( 'disabled' ) ) {
			return;
		}
		if ( ! this.reportValidity() ) {
			return;
		}

		// 有附件時改用 FormData（multipart）送出，先在前端擋掉張數／大小超過的
		var fileInput = $form.find( 'input[type="file"]' ).get( 0 );
		var files     = uploadFiles( fileInput );
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
			formData.delete( 'ultq_files[]' );
			files.forEach( function ( file ) { formData.append( 'ultq_files[]', file, file.name ); } );
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
