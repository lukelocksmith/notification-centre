jQuery(document).ready(function ($) {
    // Initialize color picker
    $('.nc-color-field').wpColorPicker();

    // Custom Radius Toggle Logic
    const radios = document.getElementsByName('nc_radius_type');
    const customRow = document.getElementById('nc_radius_custom_row');

    if (radios.length > 0 && customRow) {
        for (let i = 0; i < radios.length; i++) {
            radios[i].addEventListener('change', function () {
                if (this.value === 'custom') {
                    customRow.style.display = 'table-row';
                } else {
                    customRow.style.display = 'none';
                }
            });
        }
    }

    // Image Upload Logic
    $('.nc-upload-icon-btn').click(function (e) {
        e.preventDefault();
        var image = wp.media({
            title: 'Wybierz Ikonę',
            library: { type: 'image' },
            button: { text: 'Użyj tej ikony' },
            multiple: false
        }).open()
            .on('select', function (e) {
                var uploaded_image = image.state().get('selection').first();
                var image_url = uploaded_image.toJSON().url;
                $('#nc_icon_field').val(image_url);
            });
    });
});
