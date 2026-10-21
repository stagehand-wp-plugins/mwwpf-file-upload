/**
 * File Upload for MW WP Form: asynchronous upload for [mwform_file] / [mwform_image].
 *
 * No jQuery. The file input has no name; on change the file is sent to admin-ajax.php,
 * and the original name and the token returned by the server are set in hidden fields.
 */
( function () {
	'use strict';

	var config = window.mwwpfConfig || {};
	var i18n = config.i18n || {};
	var SUBMIT_SELECTOR = 'input[type="submit"], button[type="submit"], button:not([type])';

	// Number of uploads in progress per form element.
	var pending = typeof WeakMap === 'function' ? new WeakMap() : null;

	function getPending( form ) {
		return ( pending && form && pending.get( form ) ) || 0;
	}

	function lockForm( form ) {
		if ( ! form || ! pending ) {
			return;
		}
		var count = getPending( form ) + 1;
		pending.set( form, count );
		if ( 1 === count ) {
			form.querySelectorAll( SUBMIT_SELECTOR ).forEach( function ( button ) {
				button.setAttribute( 'data-mwwpf-was-disabled', button.disabled ? '1' : '0' );
				button.disabled = true;
			} );
		}
	}

	function unlockForm( form ) {
		if ( ! form || ! pending ) {
			return;
		}
		var count = Math.max( 0, getPending( form ) - 1 );
		pending.set( form, count );
		if ( 0 === count ) {
			form.querySelectorAll( '[data-mwwpf-was-disabled]' ).forEach( function ( button ) {
				button.disabled = '1' === button.getAttribute( 'data-mwwpf-was-disabled' );
				button.removeAttribute( 'data-mwwpf-was-disabled' );
			} );
		}
	}

	function formatSize( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var size = bytes;
		var unit = 0;
		while ( size >= 1024 && unit < units.length - 1 ) {
			size = size / 1024;
			unit++;
		}
		return ( unit === 0 ? size : size.toFixed( 1 ) ) + ' ' + units[ unit ];
	}

	function extensionOf( name ) {
		var dot = name.lastIndexOf( '.' );
		return dot > 0 ? name.slice( dot + 1 ).toLowerCase() : '';
	}

	function post( data, onProgress ) {
		return new Promise( function ( resolve ) {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', config.ajaxUrl, true );
			xhr.responseType = 'json';
			if ( onProgress && xhr.upload ) {
				xhr.upload.addEventListener( 'progress', function ( event ) {
					if ( event.lengthComputable ) {
						onProgress( Math.round( ( event.loaded / event.total ) * 100 ) );
					}
				} );
			}
			xhr.addEventListener( 'load', function () {
				var body = xhr.response;
				if ( ! body || 'object' !== typeof body ) {
					body = { success: false, data: {} };
				}
				resolve( body );
			} );
			xhr.addEventListener( 'error', function () {
				resolve( { success: false, data: { code: 'network' } } );
			} );
			xhr.send( data );
		} );
	}

	function refreshNonce() {
		var data = new FormData();
		data.append( 'action', 'mwwpf_nonce' );
		return post( data ).then( function ( body ) {
			if ( body.success && body.data && body.data.nonce ) {
				config.nonce = body.data.nonce;
				return true;
			}
			return false;
		} );
	}

	function Field( root ) {
		this.root = root;
		this.form = root.closest( 'form' );
		this.formId = root.getAttribute( 'data-mwwpf-form' );
		this.field = root.getAttribute( 'data-mwwpf-field' );
		this.type = root.getAttribute( 'data-mwwpf-type' );
		this.maxBytes = parseInt( root.getAttribute( 'data-mwwpf-max' ), 10 ) || 0;
		this.exts = ( root.getAttribute( 'data-mwwpf-ext' ) || '' ).split( ',' ).filter( Boolean );

		this.input = root.querySelector( '.mwwpf__input' );
		this.value = root.querySelector( '.mwwpf__value' );
		this.token = root.querySelector( '.mwwpf__token' );
		this.fileBox = root.querySelector( '.mwwpf__file' );
		this.nameEl = root.querySelector( '.mwwpf__name' );
		this.sizeEl = root.querySelector( '.mwwpf__size' );
		this.removeButton = root.querySelector( '.mwwpf__remove' );
		this.progress = root.querySelector( '.mwwpf__progress' );
		this.error = root.querySelector( '.mwwpf__error' );

		this.busy = false;
		this.bind();
	}

	Field.prototype.bind = function () {
		var self = this;
		this.input.addEventListener( 'change', function () {
			var file = self.input.files && self.input.files[ 0 ];
			if ( file ) {
				self.select( file );
			}
		} );
		this.removeButton.addEventListener( 'click', function () {
			self.remove();
		} );
	};

	Field.prototype.showError = function ( message ) {
		this.error.textContent = message || i18n.failed || '';
	};

	Field.prototype.clearError = function () {
		this.error.textContent = '';
	};

	Field.prototype.select = function ( file ) {
		this.clearError();

		// Client-side pre-checks. The server checks everything again.
		var ext = extensionOf( file.name );
		if ( this.exts.length && this.exts.indexOf( ext ) === -1 ) {
			this.input.value = '';
			this.showError( i18n.invalidType );
			return;
		}
		if ( this.maxBytes && file.size > this.maxBytes ) {
			this.input.value = '';
			this.showError( i18n.tooBig );
			return;
		}

		this.upload( file, false );
	};

	Field.prototype.upload = function ( file, isRetry ) {
		var self = this;
		var data = new FormData();
		data.append( 'action', 'mwwpf_upload' );
		data.append( 'nonce', config.nonce || '' );
		data.append( 'form_id', this.formId );
		data.append( 'field', this.field );
		data.append( 'file', file, file.name );

		if ( ! isRetry ) {
			this.busy = true;
			this.root.classList.add( 'is-uploading' );
			this.progress.value = 0;
			this.progress.hidden = false;
			lockForm( this.form );
		}

		post( data, function ( percent ) {
			self.progress.value = percent;
		} ).then( function ( body ) {
			if ( ! body.success && body.data && 'invalid_nonce' === body.data.code && ! isRetry ) {
				return refreshNonce().then( function ( refreshed ) {
					if ( refreshed ) {
						return self.upload( file, true );
					}
					self.finish( body );
				} );
			}
			self.finish( body );
		} );
	};

	Field.prototype.finish = function ( body ) {
		this.busy = false;
		this.root.classList.remove( 'is-uploading' );
		this.progress.hidden = true;
		this.input.value = '';
		unlockForm( this.form );

		if ( body.success && body.data && body.data.token ) {
			// Replace a previous upload of this field.
			if ( this.token.value ) {
				this.deleteToken( this.token.value );
			}
			this.setUploaded( body.data );
			return;
		}

		this.showError( body.data && body.data.message );
	};

	Field.prototype.setUploaded = function ( data ) {
		this.value.value = data.name;
		this.token.value = data.token;
		this.nameEl.textContent = data.name;
		this.sizeEl.textContent = data.size_label || formatSize( data.size );

		var thumb = this.fileBox.querySelector( '.mwwpf__thumb' );
		if ( thumb ) {
			thumb.parentNode.removeChild( thumb );
		}
		if ( 'image' === this.type && data.preview_url ) {
			thumb = document.createElement( 'img' );
			thumb.className = 'mwwpf__thumb';
			thumb.alt = '';
			thumb.src = data.preview_url;
			this.fileBox.insertBefore( thumb, this.fileBox.firstChild );
		}

		this.fileBox.hidden = false;
		this.root.classList.add( 'is-uploaded' );
	};

	Field.prototype.deleteToken = function ( token ) {
		var data = new FormData();
		data.append( 'action', 'mwwpf_delete' );
		data.append( 'nonce', config.nonce || '' );
		data.append( 'form_id', this.formId );
		data.append( 'field', this.field );
		data.append( 'token', token );
		return post( data );
	};

	Field.prototype.remove = function () {
		if ( this.busy ) {
			return;
		}
		var token = this.token.value;

		// Reset first; deleting on the server is best effort (the cleanup removes leftovers).
		this.value.value = '';
		this.token.value = '';
		this.nameEl.textContent = '';
		this.sizeEl.textContent = '';
		var thumb = this.fileBox.querySelector( '.mwwpf__thumb' );
		if ( thumb ) {
			thumb.parentNode.removeChild( thumb );
		}
		this.fileBox.hidden = true;
		this.root.classList.remove( 'is-uploaded' );
		this.clearError();
		this.input.focus();

		if ( token ) {
			this.deleteToken( token );
		}
	};

	function init() {
		document.querySelectorAll( '.mwwpf' ).forEach( function ( root ) {
			if ( root.mwwpfField || ! root.querySelector( '.mwwpf__input' ) ) {
				return;
			}
			root.mwwpfField = new Field( root );
		} );

		// Block submission (e.g. with the Enter key) while an upload is in progress.
		document.addEventListener(
			'submit',
			function ( event ) {
				if ( getPending( event.target ) > 0 ) {
					event.preventDefault();
				}
			},
			true
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
