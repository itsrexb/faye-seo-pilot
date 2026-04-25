/* Faye SEO Pilot — Admin JS */
/* global SeoPilot, jQuery */

( function ( $ ) {
	'use strict';

	const ajaxUrl = SeoPilot.ajax_url;
	const nonce   = SeoPilot.nonce;
	const str     = SeoPilot.strings;

	/* ─── Utility ──────────────────────────────────────────── */

	function showNotice( $el, type, message ) {
		$el
			.removeClass( 'seopilot-audit-notice--info seopilot-audit-notice--success seopilot-audit-notice--error' )
			.addClass( 'seopilot-audit-notice--' + type )
			.html( message )
			.show();

		if ( type === 'success' ) {
			$( 'html, body' ).animate( { scrollTop: $el.offset().top - 60 }, 300 );
		}
	}

	/* ─── Content Queue page ───────────────────────────────── */

	// Select-all checkbox
	$( '#seopilot-select-all' ).on( 'change', function () {
		$( '.seopilot-post-check' ).prop( 'checked', $( this ).prop( 'checked' ) );
		updateBulkBar();
	} );

	$( document ).on( 'change', '.seopilot-post-check', function () {
		updateBulkBar();
	} );

	function updateBulkBar() {
		const count = $( '.seopilot-post-check:checked' ).length;
		$( '#seopilot-bulk-audit' ).prop( 'disabled', count === 0 );
		$( '#seopilot-selected-count' ).text(
			count > 0 ? count + ' ' + ( count === 1 ? 'item' : 'items' ) + ' selected' : ''
		);
	}

	// Single audit button
	$( document ).on( 'click', '.seopilot-audit-btn', function () {
		const $btn    = $( this );
		const postId  = $btn.data( 'post-id' );
		const $notice = $( '#seopilot-audit-notice' );

		$btn.prop( 'disabled', true ).text( str.auditing );
		showNotice( $notice, 'info', str.auditing );

		$.post( ajaxUrl, {
			action:  'seopilot_run_audit',
			nonce:   nonce,
			post_id: postId,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				showNotice( $notice, 'success', str.audit_done );
				setTimeout( function () {
					window.location.href = res.data.review_url;
				}, 800 );
			} else {
				const msg = ( res.data && res.data.message ) ? res.data.message : 'Unknown error.';
				showNotice( $notice, 'error', str.audit_failed + msg );
				$btn.prop( 'disabled', false ).text( 'Audit' );
			}
		} )
		.fail( function () {
			showNotice( $notice, 'error', str.audit_failed + 'Request failed.' );
			$btn.prop( 'disabled', false ).text( 'Audit' );
		} );
	} );

	// Bulk audit — run sequentially
	$( '#seopilot-bulk-audit' ).on( 'click', function () {
		const $btn     = $( this );
		const $notice  = $( '#seopilot-audit-notice' );
		const postIds  = $( '.seopilot-post-check:checked' ).map( function () {
			return $( this ).val();
		} ).get();

		if ( ! postIds.length ) return;

		$btn.prop( 'disabled', true );

		let lastJobUrl = '';
		const queue    = [ ...postIds ];
		let done       = 0;

		function runNext() {
			if ( ! queue.length ) {
				showNotice( $notice, 'success', str.audit_done );
				if ( lastJobUrl ) {
					setTimeout( function () { window.location.href = lastJobUrl; }, 1000 );
				}
				return;
			}

			const postId = queue.shift();
			showNotice( $notice, 'info', str.auditing + ' (' + ( done + 1 ) + '/' + postIds.length + ')' );

			$.post( ajaxUrl, {
				action:  'seopilot_run_audit',
				nonce:   nonce,
				post_id: postId,
			} )
			.done( function ( res ) {
				done++;
				if ( res.success ) {
					lastJobUrl = res.data.review_url;
				}
				runNext();
			} )
			.fail( function () {
				done++;
				runNext();
			} );
		}

		runNext();
	} );

	/* ─── Review page ──────────────────────────────────────── */

	const approved = {};

	// Accept all fields at once
	$( document ).on( 'click', '#seopilot-accept-all-btn', function () {
		$( '.seopilot-accept-btn' ).each( function () {
			$( this ).trigger( 'click' );
		} );
	} );

	// Accept field
	$( document ).on( 'click', '.seopilot-accept-btn', function () {
		const field     = $( this ).data( 'field' );
		const $card     = $( this ).closest( '.seopilot-field-card' );
		const $editable = $card.find( '.seopilot-content-box--edit[data-field="' + field + '"]' );
		const $hidden   = $card.find( '.seopilot-field-value[name="approvals[' + field + ']"]' );

		let value;
		if ( $editable.length ) {
			// Text/HTML contenteditable field — use html() for HTML fields, text() for plain
			value = $editable.data( 'html' ) === 1
				? $editable.html()
				: $editable.text().trim();
		} else {
			// Accordion/JSON fields: no contenteditable — read the pre-filled hidden input
			value = $hidden.val();
		}

		approved[ field ] = value;
		$card
			.removeClass( 'seopilot-field-card--rejected seopilot-field-card--pending' )
			.addClass( 'seopilot-field-card--approved' );

		// Hide both action buttons once a decision is made
		$card.find( '.seopilot-field-actions' ).hide();

		// Sync hidden input
		$hidden.val( value );
	} );

	// Reject field
	$( document ).on( 'click', '.seopilot-reject-btn', function () {
		const field = $( this ).data( 'field' );
		const $card = $( this ).closest( '.seopilot-field-card' );

		delete approved[ field ];
		$card
			.removeClass( 'seopilot-field-card--approved seopilot-field-card--pending' )
			.addClass( 'seopilot-field-card--rejected' );

		// Hide both action buttons once a decision is made
		$card.find( '.seopilot-field-actions' ).hide();

		$card.find( '.seopilot-field-value' ).val( '' );
	} );

	// Apply approved
	$( document ).on( 'click', '#seopilot-apply-btn', function () {
		const jobId   = $( '#seopilot-review-form input[name="job_id"]' ).val();
		const $notice = $( '#seopilot-apply-notice' );

		if ( ! Object.keys( approved ).length ) {
			showNotice( $notice, 'error', 'Please accept at least one field before applying.' );
			return;
		}

		$( this ).prop( 'disabled', true ).text( str.applying );
		showNotice( $notice, 'info', str.applying );

		$.post( ajaxUrl, {
			action:    'seopilot_apply_proposal',
			nonce:     nonce,
			job_id:    jobId,
			approvals: approved,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				showNotice( $notice, 'success', res.data.message );
				setTimeout( function () {
					window.location.href = res.data.history_url;
				}, 1200 );
			} else {
				const msg = ( res.data && res.data.message ) ? res.data.message : 'Unknown error.';
				showNotice( $notice, 'error', str.apply_failed + msg );
				$( '#seopilot-apply-btn' ).prop( 'disabled', false ).text( 'Apply Approved Fields' );
			}
		} )
		.fail( function () {
			showNotice( $notice, 'error', str.apply_failed + 'Request failed.' );
			$( '#seopilot-apply-btn' ).prop( 'disabled', false ).text( 'Apply Approved Fields' );
		} );
	} );

	/* ─── Re-audit ─────────────────────────────────────────── */

	$( document ).on( 'click', '#seopilot-reaudit-btn', function () {
		const $btn    = $( this );
		const postId  = $btn.data( 'post-id' );
		const $notice = $( '#seopilot-apply-notice' );

		$btn.prop( 'disabled', true ).text( str.reauditing );
		showNotice( $notice, 'info', str.reauditing );

		$.post( ajaxUrl, {
			action:  'seopilot_run_audit',
			nonce:   nonce,
			post_id: postId,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				showNotice( $notice, 'success', str.audit_done );
				setTimeout( function () {
					window.location.href = res.data.review_url;
				}, 800 );
			} else {
				const msg = ( res.data && res.data.message ) ? res.data.message : 'Unknown error.';
				showNotice( $notice, 'error', str.audit_failed + msg );
				$btn.prop( 'disabled', false ).text( '\u21BB Re-audit' );
			}
		} )
		.fail( function () {
			showNotice( $notice, 'error', str.audit_failed + 'Request failed.' );
			$btn.prop( 'disabled', false ).text( '\u21BB Re-audit' );
		} );
	} );

	/* ─── Rollback ─────────────────────────────────────────── */

	$( document ).on( 'click', '.seopilot-rollback-btn', function () {
		if ( ! window.confirm( 'Roll back all changes made by this audit? The post will be restored to its state before Apply was clicked.' ) ) {
			return;
		}

		const $btn    = $( this );
		const jobId   = $btn.data( 'job-id' );
		const $notice = $( '#seopilot-apply-notice, #seopilot-history-notice' ).first();

		$btn.prop( 'disabled', true ).text( str.rolling_back );
		showNotice( $notice, 'info', str.rolling_back );

		$.post( ajaxUrl, {
			action: 'seopilot_rollback',
			nonce:  nonce,
			job_id: jobId,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				showNotice( $notice, 'success', str.rollback_done );
				setTimeout( function () { window.location.reload(); }, 1000 );
			} else {
				const msg = ( res.data && res.data.message ) ? res.data.message : 'Unknown error.';
				showNotice( $notice, 'error', msg );
				$btn.prop( 'disabled', false ).text( 'Roll Back' );
			}
		} )
		.fail( function () {
			showNotice( $notice, 'error', 'Rollback request failed.' );
			$btn.prop( 'disabled', false ).text( 'Roll Back' );
		} );
	} );

} )( jQuery );
