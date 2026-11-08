/**
 * FatalGuard review-ask notice — persist the dismissal when the admin clicks the
 * native notice "X". Moved out of an inline <script> in review-ask.php for wp.org
 * enqueue compliance. The dismiss URL (nonced) arrives via fatalguardReviewL10n.
 */
( function () {
	var url = ( window.fatalguardReviewL10n || {} ).dismissUrl;
	if ( ! url ) {
		return;
	}
	// Delegated so it works regardless of WHEN WordPress core injects the ".notice-dismiss"
	// X button — core wires it on jQuery(ready), which fires after this footer script, so a
	// direct bind would miss the button and the nag would return next page.
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.fg-review .notice-dismiss' ) ) {
			return;
		}
		fetch( url, { method: 'GET', credentials: 'same-origin' } );
	} );
} )();
