let uploadGameModule = (function() {
    let $resetButton = $('input[name="reset"]');

    let $form = $('form[name="upload_game"]');
    let $regular = $('#upload_game_regular');
    let $file = $('#upload_game_file');
    let $messageBlock = $('#upload_game_message');

    let init = function() {
        $form.on('submit', function(e) {
            e.preventDefault();

            let regular = $regular.val().trim();
            let file = $file.val().trim();

            if (!regular && !file) {
                showMessage('One of the fields should be sent');
                return;
            }

            hideMessage();

            $.ajax({
                url: APP_HOST + '/uploadGame',
                type: 'POST',
                data: new FormData(this),
                success: function (response) {
                    showMessage(response);
                    resetForm();
                },
                error: function(jqXHR) {
                    showMessage(jqXHR.responseJSON);
                },
                cache: false,
                contentType: false,
                processData: false
            });
        });

        $resetButton.on('click', resetForm);
    }

    /**
     * Show message in error block.
     */
    let showMessage = function(message) {
        $messageBlock.show().html(message);
    }

    /**
     * Hide and reset error block.
     */
    let hideMessage = function() {
        $messageBlock.hide().html('');
    }

    /**
     * Reset values in form.
     */
    let resetForm = function() {
        $regular.val('');
        $file.val('');
    };

    return {
        init: init
    };
})();

$(document).ready(function() {
    uploadGameModule.init();
});
