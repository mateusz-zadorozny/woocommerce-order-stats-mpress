jQuery(document).ready(function ($) {
    $('#wc_order_stats_generate_key').on('click', function () {
        $.ajax({
            url: wcOrderStats.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_order_stats_api_key',
                nonce: wcOrderStats.nonce
            },
            success: function (response) {
                $('#wc_order_stats_api_key').val(response);
            }
        });
    });
});