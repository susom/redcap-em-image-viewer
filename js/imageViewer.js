var IVEM = IVEM || {};

IVEM.init = function() {

    $(document).ready(function() {

        // Hijack the proxy function for display of images immediately after image-uploads
        IVEM.setupProxy();

        // Process each field on the page when previously uploaded files are rendered on a page
        $.each(IVEM.fields, function(i, field) {
            IVEM.insertImage(field);
        });

    });
};


// On the designer page, let's highlight those fields that are configured in the module setup
IVEM.highlightFields = function() {
        $(IVEM.fields).each( function(i,e){
            var tr = $('tr[sq_id="' + e + '"]').not('.IVEM');
            if (tr.length) {
                var icon_div = $('.frmedit_icons', tr);
                var label = $('<span>ImageViewer External Module</span>')
                    .addClass("label label-primary em-label pull-right")
                    .attr("data-toggle", "tooltip")
                    .attr("title", "The content of this field is customized by the ImageViewer External Module")
                    .on('click', function() {
                        event.stopPropagation();
                    })
                    .appendTo(icon_div);
                tr.addClass('IVEM');
            }
        });
};


IVEM.insertImage = function(field) {
    // Get parent tr for table
    var tr = $('tr[sq_id="' + field + '"]');
    if (! tr.length) return;

    // Get the hyperlink element
    var a = $('a[name="' + field + '"]', tr);

    // Get the href
    var href = a.attr('href');

    // Append the response hash if needed
    if (href.indexOf('__response_hash__') === -1) {
        href += '&__response_hash__='+$('#form :input[name=__response_hash__]').val();
    }

    // Determine the width of the parent TD
    var td_width = a.closest('td').width();

    // Create a new image element
    var img = $('<img>').attr('src', href).css('max-width',td_width + 'px').css({"margin-left":"auto","margin-right":"auto","display":"block"});

    img.prependTo(a);
};


// This proxy allows the EM to update an image as soon as it is finished uploading the image without leaving the page.
IVEM.setupProxy = function() {

    // Allows us to validate the modal dialog after it opens (could be done differently)
    (function () {
        var proxied = stopUpload;
        stopUpload = function () {
            // first do the standard stopUpload
            $result = proxied.apply(this, arguments);

            // After a successful upload, the download url is attached to the page - let's use it to download a preview image
            // TODO - verify that it is an 'image'
            // function stopUpload(success,this_field,doc_id,doc_name,study_id,doc_size,event_id,download_page,delete_page,doc_id_hash,instance)
            var success = arguments[0];
            var field = arguments[1];
            if (success) {
                IVEM.insertImage(field);
            }
            return $result;
        };
    })();
};
