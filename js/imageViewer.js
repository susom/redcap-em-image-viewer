var IVEM = IVEM || {};


/**
 * On the online designer page, let's highlight those fields that are configured in the module setup
 */
IVEM.highlightFields = function() {
    $.each(IVEM.field_params, function(field, params){
        var tr = $('tr[sq_id="' + field + '"]').not('.IVEM');
        if (tr.length) {
            var icon_div = $('.frmedit_icons', tr);
            var label = $('<span>ImageViewer External Module</span>')
                .addClass("label label-primary em-label pull-right")
                .attr("data-toggle", "tooltip")
                .attr("title", "The content of this field is customized by the ImageViewer External Module" + ( params ? ":\n" + JSON.stringify(params) : ""))
                .on('click', function() {
                    event.stopPropagation();
                })
                .appendTo(icon_div);
            tr.addClass('IVEM');
        }
    });
};


/**
 * Set up module on survey / data entry pages
 */
IVEM.init = function() {
    $(document).ready(function() {

        // Hijack the proxy function for preview of images immediately after uploads
        IVEM.setupProxy();

        // Process each field on the page that already contains data when the page is loaded
        $.each(IVEM.preview_fields, function(field, params) {
            IVEM.insertPreview(field, params.params, params.suffix);
        });
    });
};


/**
 * Preview the file attached to the given field
 * This is called both on existing uploads when rendered and after new uploads are attached to fields
 * It relies on there being IVEM.field_params and IVEM.file_details
 * @param field
 */
IVEM.insertPreview = function(field, params, suffix, preview_hash) {

    // Get parent tr for table
    var tr = $('tr[sq_id="' + field + '"]');
    if (! tr.length) return;

    // Get the hyperlink element
    var a = $('a[name="' + field + '"]', tr);
    if (! a.length) return;

    // Get the href
    var src = a.attr('href');
    if (! src) return;

    // Append the response hash if needed (only for surveys)
    var hash = $('#form :input[name=__response_hash__]').val();
    if (src.indexOf('__response_hash__') === -1 && hash) {
        src += '&__response_hash__=' + hash;
    }

    // Determine the width of the parent TD
    var td_width = a.closest('td').width();


    // var params = IVEM.field_params[field];
    // console.log("Processing" , field, params);


    // // Check the details array
    // if (file_detail = IVEM.file_details[field]) {

        // // Get the file suffix
        // var suffix = file_detail.suffix;

        // A Preview hash indicates that the file was just uploaded and must be previewed using the every_page_before_render hook
        // We will add the ivem_preview tag to the query string to distinguish this request
        if (preview_hash) {
            src += "&ivem_preview=" + preview_hash;
        }

        // Handle Valid Images
        if (IVEM.valid_image_suffixes.indexOf(suffix) !== -1)
        {
            // Create a new image element and shrink to fit wd_width
            var img = $('<img/>').attr('src', src).css('max-width',td_width + 'px').css({"margin-left":"auto","margin-right":"auto","display":"block"});

            // Append custom CSS if specified for the field
            $.each(params, function(k,v) {
                img.css(k,v);
            });

            img.prependTo(a);
        }

        // Handle Valid PDF Files - https://github.com/pipwerks/PDFObject
        else if (IVEM.valid_pdf_suffixes.indexOf(suffix) !== -1)
        {
            src = src + '&stream=1';
            //console.log('Creating pdf with ' + src);

            var pdf = $('<div/>').attr('id', field + '_pdfobject');
            pdf.prependTo(a);

            //console.log("pdf_src",src);

            // Set default pdf options and load any custom options from the params
            var options = { "fallbackLink": "This browser does not support inline PDFs" };
            $.extend(options, params);

            // Create object
            IVEM[field + '_pdf'] = PDFObject.embed(src, pdf, options);
        }
    // }
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
        var first_box = $('#setupChklist-modify_project');
        if (first_box.length) {
            var element = $('#em_summary_box');
            if (!element.length) {
                element = $('<div id="em_summary_box" class="round chklist col-xs-12"><strong>External Modules: </strong></div>');
            }

            var label = $('<span>ImageViewer</span>')
                .addClass("label label-primary label-lg em-label")
                .attr("title", "The content of this project is customized by the ImageViewer External Module");

            var badge = $('<span></span>')
                .text(IVEM.field_params.length)
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
            // console.log(arguments);

            var success = arguments[0];
            var field = arguments[1];
            var doc_name = arguments[3];
            var suffix =  IVEM.getExtension(doc_name).toLowerCase();

            // This is file part of an active field
            if (success && IVEM.field_params[field]) {
                // console.log("Upload to " + field + " with " + doc_name + " and " + suffix);

                // IVEM.file_details[field] = {
                //     "doc_name": doc_name,
                //     "suffix": suffix,
                //     "preview_hash": arguments[9]
                // };
                // console.log("About to insert " + field);
                var params = IVEM.field_params[field];
                var hash = arguments[9];
                IVEM.insertPreview(field, params, suffix, hash);
            }
            return $result;
        };
    })();
};
