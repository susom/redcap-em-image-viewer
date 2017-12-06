var IVEM = IVEM || {};

IVEM.valid_image_suffixes = ['jpeg','jpg','jpe','gif','png','tif','bmp'];


/**
 * Set up module on survey / data entry pages
 */
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


/**
 * On the designer page, let's highlight those fields that are configured in the module setup
 */
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


/**
 * add the image to a file-upload field
 * @param field
 */
IVEM.insertImage = function(field) {
    // Get parent tr for table
    var tr = $('tr[sq_id="' + field + '"]');
    if (! tr.length) return;

    // Get the hyperlink element
    var a = $('a[name="' + field + '"]', tr);
    if (! a.length) return;

    // Get the href
    var src = a.attr('href');
    if (! src) return;

    // Append the response hash if needed
    if (src.indexOf('__response_hash__') === -1) {
        src += '&__response_hash__=' + $('#form :input[name=__response_hash__]').val();
    }

    // Determine the width of the parent TD
    var td_width = a.closest('td').width();

    // Create a new image element and shrink to fit wd_width
    var img = $('<img>').attr('src', src).css('max-width',td_width + 'px').css({"margin-left":"auto","margin-right":"auto","display":"block"});

    img.prependTo(a);
};


/**
 * Extract the file extension from a string or return empty
 * @param path
 * @returns {string}
 */
IVEM.getExtension = function (path) {
    var basename = path.split(/[\\/]/).pop(),  // extract file name from full path ...
        // (supports `\\` and `/` separators)
        pos = basename.lastIndexOf(".");       // get last position of `.`

    if (basename === "" || pos < 1)            // if file name is empty or ...
        return "";                             //  `.` not found (-1) or comes first (0)

    return basename.slice(pos + 1);            // extract extension ignoring `.`
};


IVEM.projectSetup = function () {
    $(document).ready(function () {
        console.log("Here");
        var first_box = $('#setupChklist-modify_project');
        console.log(first_box);
        console.log(IVEM.fields);
        if (first_box.length) {
            var element = $('#em_summary_box');
            if (!element.length) {
                element = $('<div id="em_summary_box" class="round chklist col-xs-12"><strong>External Modules: </strong></div>');
            }

            var label = $('<span>ImageViewer</span>')
                .addClass("label label-primary label-lg em-label")
                .attr("title", "The content of this project is customized by the ImageViewer External Module");

            var badge = $('<span></span>')
                .text(IVEM.fields.length)
                .addClass("badge")
                .appendTo(label);

            element.append(label);  //.appendTo(first_box.parent);

           first_box.parent().append(element);
        }
    });
};

/**
 * This proxy allows the EM to update an image as soon as it is finished uploading the image without leaving the page.
 */
IVEM.setupProxy = function() {

    // Allows us to validate the modal dialog after it opens (could be done differently)
    (function () {
        var proxied = stopUpload;
        stopUpload = function () {
            // first do the standard stopUpload
            $result = proxied.apply(this, arguments);

            // After a successful upload, the download url is attached to the page - let's use it to download a preview image
            // function stopUpload(success,this_field,doc_id,doc_name,study_id,doc_size,event_id,download_page,delete_page,doc_id_hash,instance)
            var success = arguments[0];
            var field = arguments[1];

            // This is file part of an active field
            if (success && IVEM.fields.indexOf(field) !== -1) {
                // Is file an image
                var doc_name = arguments[3];
                var suffix =  IVEM.getExtension(doc_name).toLowerCase();
                if (IVEM.valid_image_suffixes.indexOf(suffix) !== -1) {
                    // Generate a preview
                    IVEM.insertImage(field);
                }
            }
            return $result;
        };
    })();
};
