jQuery(document).ready(function ($) {

    $(function () {
        $('.pp-color-picker').wpColorPicker();
    });

    $(document).on('change', '.wp-color-result', function(e) {
        $('[name="revision_editor_bg_color"]').val( $('.wp-color-result').val() );
    });
});
