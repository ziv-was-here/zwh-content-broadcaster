/**
 * CB Settings — Repeatable Environments Table
 *
 * Handles three behaviours with zero external dependencies:
 *   1. Add Row    — clones the <template> and appends it to the tbody
 *   2. Remove Row — removes the clicked row; shows/hides the empty notice
 *   3. Show/Hide  — toggles each Application Password field visibility
 *
 * @package ContentBroadcaster
 * @since   1.0.0
 */

( function () {
    'use strict';

    const tbody      = document.getElementById( 'cb-env-tbody' );
    const addBtn     = document.getElementById( 'cb-add-env-row' );
    const template   = document.getElementById( 'cb-row-template' );
    const emptyNotice = document.getElementById( 'cb-empty-notice' );

    if ( ! tbody || ! addBtn || ! template ) {
        return;
    }

    let nextIndex = parseInt( addBtn.dataset.nextIndex, 10 ) || 0;

    function syncEmptyNotice() {
        if ( ! emptyNotice ) return;
        const hasRows = tbody.querySelectorAll( '.cb-env-row' ).length > 0;
        emptyNotice.style.display = hasRows ? 'none' : '';
    }

    function renumberRows() {
        const rows = tbody.querySelectorAll( '.cb-env-row' );
        rows.forEach( function ( row, i ) {
            const badge = row.querySelector( '.cb-row-index' );
            if ( badge ) badge.textContent = i + 1;
        } );
    }

    addBtn.addEventListener( 'click', function ( e ) {
        e.preventDefault();

        const clone = template.content.cloneNode( true );
        const tr    = clone.querySelector( 'tr' );

        if ( ! tr ) return;

        tr.dataset.index = nextIndex;
        tr.querySelectorAll( '[name], [data-index]' ).forEach( function ( el ) {
            if ( el.name ) {
                el.name = el.name.replace( /__INDEX__/g, nextIndex );
            }
            if ( el.dataset.index ) {
                el.dataset.index = nextIndex;
            }
        } );

        tr.querySelectorAll( 'input'  ).forEach( function ( el ) { el.value   = ''; } );
        tr.querySelectorAll( 'select' ).forEach( function ( el ) { el.selectedIndex = 0; } );

        tbody.appendChild( tr );

        nextIndex++;
        addBtn.dataset.nextIndex = nextIndex;

        syncEmptyNotice();
        renumberRows();

        const firstInput = tr.querySelector( 'input[type="text"]' );
        if ( firstInput ) firstInput.focus();
    } );

    tbody.addEventListener( 'click', function ( e ) {
        const btn = e.target.closest( '.cb-remove-row' );
        if ( ! btn ) return;

        e.preventDefault();
        const row = btn.closest( 'tr.cb-env-row' );
        if ( ! row ) return;

        row.style.transition = 'opacity 0.18s ease';
        row.style.opacity    = '0';

        setTimeout( function () {
            row.remove();
            syncEmptyNotice();
            renumberRows();
        }, 180 );
    } );

    const form = document.getElementById( 'cb-settings-form' );
    if ( form ) {
        form.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.cb-toggle-pw' );
            if ( ! btn ) return;

            e.preventDefault();

            const wrap  = btn.closest( '.cb-password-wrap' );
            const input = wrap ? wrap.querySelector( '.cb-api-key-input' ) : null;
            const icon  = btn.querySelector( '.cb-eye-icon' );

            if ( ! input ) return;

            const isHidden = input.type === 'password';
            input.type     = isHidden ? 'text' : 'password';

            if ( icon ) {
                icon.classList.toggle( 'dashicons-visibility',    ! isHidden );
                icon.classList.toggle( 'dashicons-hidden',         isHidden );
            }
        } );

        // Handle API key generation via delegation
        form.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.cb-generate-key' );
            if ( ! btn ) return;

            e.preventDefault();

            const cell = btn.closest( '.cb-col-key' );
            const input = cell ? cell.querySelector( '.cb-api-key-input' ) : null;

            if ( input ) {
                // Generate a random 48-character hex string (24 bytes)
                const bytes = new Uint8Array( 24 );
                window.crypto.getRandomValues( bytes );
                const hex = Array.from( bytes ).map( b => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
                const key = 'cb_' + hex;

                input.value = key;
                input.type = 'text'; // Reveal the key
                input.focus();

                // Simple visual feedback
                const originalText = btn.textContent;
                btn.textContent = '✓';
                setTimeout( () => { btn.textContent = originalText; }, 1500 );
            }
        } );

        // Handle Connection Test via delegation
        form.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.cb-test-connection-btn' );
            if ( ! btn ) return;

            e.preventDefault();

            const row    = btn.closest( '.cb-env-row' );
            const status = row ? row.querySelector( '.cb-test-status' ) : null;
            const index  = btn.dataset.index;

            // Get current values from row (useful if not saved yet)
            const siteUrlInput = row ? row.querySelector( '.cb-url-input' ) : null;
            const apiKeyInput  = row ? row.querySelector( '.cb-api-key-input' ) : null;

            const siteUrl = siteUrlInput ? siteUrlInput.value : '';
            const apiKey  = apiKeyInput  ? apiKeyInput.value  : '';

            if ( ! status ) return;

            // Safety check for localized data
            if ( typeof cbSettings === 'undefined' || ! cbSettings.nonce ) {
                status.innerHTML = '<span class="cb-status-err">Configuration error (missing nonce)</span>';
                console.error( 'CB Error: cbSettings is not defined. Asset enqueuing might be failing.' );
                return;
            }

            if ( ! siteUrl || ! apiKey ) {
                status.innerHTML = '<span class="cb-status-err">URL and Key required</span>';
                return;
            }

            btn.disabled = true;
            btn.classList.add( 'updating-message' );
            status.innerHTML = '<span class="spinner is-active" style="float:none; margin:0 5px;"></span> Testing...';

            // Use fetch instead of jQuery for zero-dependency consistency
            const formData = new FormData();
            formData.append( 'action', 'cb_test_connection' );
            formData.append( 'nonce',  cbSettings.nonce );
            formData.append( 'index',  index );
            formData.append( 'site_url', siteUrl );
            formData.append( 'api_key', apiKey );

            fetch( cbSettings.ajax_url, {
                method: 'POST',
                body: formData
            } )
            .then( response => response.json() )
            .then( response => {
                if ( response.success ) {
                    status.innerHTML = '<span class="cb-status-ok" title="' + response.data.message + '">✅ Connected</span>';
                } else {
                    status.innerHTML = '<span class="cb-status-err" title="' + response.data.message + '">❌ Failed</span>';
                }
            } )
            .catch( error => {
                status.innerHTML = '<span class="cb-status-err">❌ Error</span>';
                console.error( 'CB Test Connection Error:', error );
            } )
            .finally( () => {
                btn.disabled = false;
                btn.classList.remove( 'updating-message' );
            } );
        } );
    }

    syncEmptyNotice();
    renumberRows();

} )();
