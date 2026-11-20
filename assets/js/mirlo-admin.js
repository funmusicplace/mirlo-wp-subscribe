/* global mirloAdmin */
jQuery( document ).ready( function ( $ ) {
    // Colour picker
    $( '.mirlo-color-picker' ).wpColorPicker();

    function escapeHtml( value ) {
        return $( '<div>' ).text( value == null ? '' : String( value ) ).html();
    }

    function errorNotice( message ) {
        return '<div class="notice notice-error inline"><p>' + escapeHtml( message ) + '</p></div>';
    }

    // The tools sit inside the settings form, so Enter in their fields would
    // save the settings. Run the tool instead.
    function runOnEnter( input, button ) {
        $( input ).on( 'keydown', function ( e ) {
            if ( 13 === e.which ) {
                e.preventDefault();
                $( button ).trigger( 'click' );
            }
        } );
    }
    runOnEnter( '#mirlo-check-slug', '#mirlo-check-btn' );
    runOnEnter( '#mirlo-subscribers-artist', '#mirlo-subscribers-btn' );

    // Check an artist
    function itemList( items, label ) {
        return '<ul class="mirlo-id-list">' + items.map( function ( item ) {
            return '<li>' + escapeHtml( item[ label ] ) + ' <code>' + escapeHtml( item.id ) + '</code></li>';
        } ).join( '' ) + '</ul>';
    }

    $( '#mirlo-check-btn' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#mirlo-check-result' );
        var slug    = $( '#mirlo-check-slug' ).val().trim();

        if ( ! slug ) {
            $result.html( errorNotice( 'Enter an artist\'s URL slug.' ) );
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Checking…' );
        $result.html( '' );

        $.ajax( {
            url:  mirloAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mirlo_check_artist',
                slug:   slug,
                nonce:  mirloAdmin.nonce,
            },
            success: function ( response ) {
                if ( ! response.success ) {
                    $result.html( errorNotice( response.data ) );
                    return;
                }
                var a    = response.data;
                var html = '<div class="notice notice-success inline"><p>Found <strong>' + escapeHtml( a.name ) + '</strong> on '
                    + escapeHtml( a.instance ) + '.</p></div>';

                html += '<h4>Subscription tiers</h4>';
                html += a.tiers.length ? itemList( a.tiers, 'name' ) : '<p>None.</p>';

                html += '<h4>Merch</h4>';
                if ( null === a.merch ) {
                    html += '<p>Couldn\'t list the merch.</p>';
                } else {
                    html += a.merch.length ? itemList( a.merch, 'title' ) : '<p>None.</p>';
                }

                html += '<h4>Try</h4><p>'
                    + '<code>[mirlo_pop_up artist="' + escapeHtml( a.slug ) + '"]</code><br>'
                    + '<code>[mirlo_tier_list artist="' + escapeHtml( a.slug ) + '"]</code><br>'
                    + '<code>[mirlo_catalog artist="' + escapeHtml( a.slug ) + '"]</code></p>';

                $result.html( html );
            },
            error: function () {
                $result.html( errorNotice( 'Network error.' ) );
            },
            complete: function () {
                $btn.prop( 'disabled', false ).text( 'Check' );
            },
        } );
    } );

    // Preview subscribers
    $( '#mirlo-subscribers-btn' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#mirlo-subscribers-result' );
        var artist  = $( '#mirlo-subscribers-artist' ).val().trim();

        if ( ! artist ) {
            $result.html( errorNotice( 'Enter an artist\'s URL slug.' ) );
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
                    $result.html( errorNotice( response.data ) );
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
                    + '<table class="widefat striped">'
                    + '<thead><tr><th>Name</th><th>Email</th><th>Tier</th><th>Status</th><th>Current period ends</th></tr></thead>'
                    + '<tbody>' + rows + '</tbody></table>'
                );
            },
            error: function () {
                $result.html( errorNotice( 'Network error.' ) );
            },
            complete: function () {
                $btn.prop( 'disabled', false ).text( 'Preview' );
            },
        } );
    } );
} );
