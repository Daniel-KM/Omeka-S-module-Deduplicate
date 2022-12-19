// TODO Remove dead code.

// Kept as long as pull request #1260 is not passed.
Omeka.deduplicateManageSelectedActions = function() {
    const selectedOptions = $('[value="update-selected"], [value="delete-selected"], #batch-form .batch-inputs .batch-selected');
    if ($('.batch-edit td input[type="checkbox"]:checked').length > 0) {
        selectedOptions.removeAttr('disabled');
    } else {
        selectedOptions.prop('disabled', true);
        $('.batch-actions-select').val('default');
        $('.batch-actions .active').removeClass('active');
        $('.batch-actions .default').addClass('active');
    }
};

(function($, window, document) {

    // Browse batch actions.
    $(function() {

        const batchSelect = $('#batch-form .batch-actions-select');
        batchSelect.attr('name', 'batch_action');

        batchSelect.append(
            $('<option class="batch-selected" disabled></option>').val('deduplicate_selected').html(Omeka.jsTranslate('Deduplicate selected resources'))
        );
        batchSelect.append(
            $('<option></option>').val('deduplicate_all').html(Omeka.jsTranslate('Deduplicate all resources'))
        );

        const batchActions = $('#batch-form .batch-actions');
        batchActions.append(
            $('<input type="submit" class="deduplicate_selected" formaction="deduplicate">').val(Omeka.jsTranslate('Go'))
        );
        batchActions.append(
            $('<input type="submit" class="deduplicate_all" formaction="deduplicate">').val(Omeka.jsTranslate('Go'))
        );

        const resourceType = window.location.pathname.split('/').pop();
        batchActions.append(
            $('<input type="hidden" name="resource_type">').val(resourceType)
        );

        // Kept as long as pull request #1260 is not passed.
        $('.select-all').change(function() {
            Omeka.deduplicateManageSelectedActions();
        });
        $('.batch-edit td input[type="checkbox"]').change(function() {
            Omeka.deduplicateManageSelectedActions();
        });

        $('.batch-edit td input[type="checkbox"]').change(function() {
            Omeka.deduplicateManageSelectedActions();
        });

        // Prepare the form for the deduplication process.

        batchSelect.on('change', function() {
            const val = $(this).find(':selected').val();
            if (val === 'deduplicate_selected' || val === 'deduplicate_all') {
                if (!$('#deduplicate').length) {
                    batchSelect.after(`<div id="deduplicate">
    <span>${Omeka.jsTranslate('Deduplicate on:')}</span>
    <input type="number" name="deduplicate_property" id="deduplicate-property" required="required" class="required" placeholder="${Omeka.jsTranslate('Property id')}"/>
    <input type="text" name="deduplicate_value" id="deduplicate-value" required="required" class="required" placeholder="${Omeka.jsTranslate('Value')}"/>
</div>`);
                }
            } else {
                $('#deduplicate').remove();
            }
        });

    });

}(window.jQuery, window, document));
