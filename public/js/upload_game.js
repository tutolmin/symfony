let uploadGameModule = (function() {
    let $resetButton = $('input[name="reset"]');

    let $uploadGameForm = $('form[name="upload_game"]');
    let $regular = $('#upload_game_regular');
    let $file = $('#upload_game_file');
    let $messageBlock = $('#upload_game_message');

    let init = function() {
        $resetButton.on('click', resetForm);
    }

    /**
     * Handle Submit event.
     */
    let handleSubmitEvent = function() {
        hideMessage();

        $.ajax({
            url: APP_HOST + '/uploadGame',
            type: 'POST',
            data: new FormData($uploadGameForm),
            timeout: 60000,
            success: function (response) {
                showMessage(response);
                resetForm();
            },
            error: function(jqXHR) {
                let errorMessage = 'An error occurred while trying to process the request.';

                if ('timeout' === jqXHR.statusText) {
                    errorMessage = 'Connection timed out.';
                } else if (jqXHR.responseJSON) {
                    errorMessage = jqXHR.responseJSON;
                } else if (jqXHR.statusText) {
                    errorMessage = jqXHR.statusText;
                }

                showMessage(errorMessage);
            },
            cache: false,
            contentType: false,
            processData: false
        });
    };

    /**
     * Show message in error block.
     */
    let showMessage = function(message) {
        $messageBlock.show().html(message);
    };

    /**
     * Hide and reset error block.
     */
    let hideMessage = function() {
        $messageBlock.hide().html('');
    };

    /**
     * Reset values in form.
     */
    let resetForm = function() {
        $regular.val('');
        $file.val('');
    };

    return {
        init: init,
        showMessage: showMessage,
        handleSubmitEvent: handleSubmitEvent
    };
})();

$(document).ready(function() {
    uploadGameModule.init();
});
