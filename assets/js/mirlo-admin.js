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
} );
