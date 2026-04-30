/**
 * CB Send Metabox — Handles the "Send via API" logic.
 */
( function () {
    'use strict';

    // Use event delegation on the document to handle the button click.
    // This is more reliable in Gutenberg where metaboxes may load dynamically.
    console.log('CB Send Metabox script loaded.');

    document.addEventListener( 'click', function( e ) {
        const button = e.target.closest( '.cb-send-button' );
        if ( ! button ) return;

        console.log('CB Send button clicked.');
        e.preventDefault();

        const form = document.getElementById( 'cb_send_form' );
        if ( ! form ) {
            console.error('CB Error: Send form not found.');
            return;
        }

        const resultsDiv = form.querySelector( '.cb-results' );
        const spinner = form.querySelector( '.cb-spinner' );

        const checked = form.querySelectorAll( 'input[name="cb_target_env[]"]:checked' );
        if ( checked.length === 0 ) {
            resultsDiv.innerHTML = '<div class="cb-result-item">Please select at least one environment</div>';
            resultsDiv.classList.add( 'show', 'error' );
            resultsDiv.classList.remove( 'success' );
            return;
        }

        // Check if localized args exist
        if ( typeof cbSendArgs === 'undefined' ) {
            console.error('CB Error: cbSendArgs is missing.');
            resultsDiv.innerHTML = '<strong>✗ Error</strong><br>Security configuration missing. Please refresh.';
            resultsDiv.classList.add( 'show', 'error' );
            return;
        }

        spinner.classList.add( 'show' );
        button.disabled = true;
        resultsDiv.classList.remove('show'); // Clear previous results

        const formData = new FormData();
        formData.append( 'action', 'cb_send_post' );
        formData.append( 'nonce', cbSendArgs.nonce );

        // Manually append the post_id
        const postIdInput = form.querySelector('input[name="post_id"]');
        if (postIdInput) {
            formData.append('post_id', postIdInput.value);
        }

        // Manually append selected environments
        checked.forEach(cb => {
            formData.append('cb_target_env[]', cb.value);
        });

        console.log('Sending AJAX request...');

        fetch( cbSendArgs.ajaxurl, {
            method: 'POST',
            body: formData
        } )
        .then( response => response.json() )
        .then( data => {
            console.log('Response received:', data);
            spinner.classList.remove( 'show' );
            button.disabled = false;

            if ( data.success ) {
                resultsDiv.classList.add( 'show', 'success' );
                resultsDiv.classList.remove( 'error' );
                resultsDiv.innerHTML = '<strong>✓ Success</strong><br>' + data.data.join( '<br>' );
            } else {
                resultsDiv.classList.add( 'show', 'error' );
                resultsDiv.classList.remove( 'success' );
                resultsDiv.innerHTML = '<strong>✗ Error</strong><br>' + ( Array.isArray(data.data) ? data.data.join('<br>') : data.data );
            }
        } )
        .catch( error => {
            console.error('Fetch error:', error);
            spinner.classList.remove( 'show' );
            button.disabled = false;
            resultsDiv.classList.add( 'show', 'error' );
            resultsDiv.classList.remove( 'success' );
            resultsDiv.innerHTML = '<strong>✗ Network Error</strong><br>' + error.message;
        } );
    } );

} )();
