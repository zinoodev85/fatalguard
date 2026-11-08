/**
 * FatalGuard delivery-failure notice — persist the dismissal when the admin clicks
 * the native notice "X" (hash of the current failure set; a new failure brings the
 * notice back). The dismiss URL (nonced) arrives via fatalguardDeliveryFailL10n.
 */
( function () {
	var url = ( window.fatalguardDeliveryFailL10n || {} ).dismissUrl;
	if ( ! url ) {
		return;
	}
	// Delegated so it works regardless of WHEN WordPress core injects the ".notice-dismiss"
	// X button — core wires it on jQuery(ready), which fires after this footer script, so a
	// direct bind would miss the button and the notice would return next page.
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.fg-delivery-fail .notice-dismiss' ) ) {
			return;
		}
		fetch( url, { method: 'GET', credentials: 'same-origin' } );
	} );
} )();
