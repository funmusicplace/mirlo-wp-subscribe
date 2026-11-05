/* global mirloAdmin */
jQuery( document ).ready( function ( $ ) {
    // Colour picker
    $( '.mirlo-color-picker' ).wpColorPicker();

    // Test connection
    $( '#mirlo-test-btn' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#mirlo-test-result' );
        var slug    = $( '#mirlo-test-slug' ).val().trim();

        if ( ! slug ) {
            $result.html( '<span style="color:#c0392b;">Please enter a slug.</span>' );
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Testing…' );
        $result.html( '' );

        $.ajax( {
            url:  mirloAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mirlo_test_connection',
                slug:   slug,
                nonce:  mirloAdmin.nonce,
            },
            success: function ( response ) {
                $result.html( '<pre>' + JSON.stringify( response.data, null, 2 ) + '</pre>' );
            },
            error: function () {
                $result.html( '<span style="color:#c0392b;">&#10007; Network error.</span>' );
            },
            complete: function () {
                $btn.prop( 'disabled', false ).text( 'Test' );
            },
        } );
    } );

    // Preview subscribers
    function escapeHtml( value ) {
        return $( '<div>' ).text( value == null ? '' : String( value ) ).html();
    }

    $( '#mirlo-subscribers-btn' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#mirlo-subscribers-result' );
        var artist  = $( '#mirlo-subscribers-artist' ).val().trim();

        if ( ! artist ) {
            $result.html( '<span style="color:#c0392b;">Please enter a slug.</span>' );
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Loading…' );
        $result.html( '' );

        $.ajax( {
            url:  mirloAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mirlo_preview_subscribers',
                artist: artist,
                nonce:  mirloAdmin.nonce,
            },
            success: function ( response ) {
                if ( ! response.success ) {
                    $result.html( '<span style="color:#c0392b;">&#10007; ' + escapeHtml( response.data ) + '</span>' );
                    return;
                }

                var rows = response.data.map( function ( sub ) {
                    return '<tr>'
                        + '<td>' + escapeHtml( sub.name ) + '</td>'
                        + '<td>' + escapeHtml( sub.email ) + '</td>'
                        + '<td>' + escapeHtml( sub.tierName ) + '</td>'
                        + '<td>' + escapeHtml( sub.status ) + '</td>'
                        + '<td>' + escapeHtml( sub.currentPeriodEnd ? sub.currentPeriodEnd.slice( 0, 10 ) : '' ) + '</td>'
                        + '</tr>';
                } ).join( '' );

                $result.html(
                    '<p>' + response.data.length + ' subscriber(s).</p>'
                    + '<table class="widefat striped" style="max-width:900px;">'
                    + '<thead><tr><th>Name</th><th>Email</th><th>Tier</th><th>Status</th><th>Current period ends</th></tr></thead>'
                    + '<tbody>' + rows + '</tbody></table>'
                );
            },
            error: function () {
                $result.html( '<span style="color:#c0392b;">&#10007; Network error.</span>' );
            },
            complete: function () {
                $btn.prop( 'disabled', false ).text( 'Preview' );
            },
        } );
    } );
} );
