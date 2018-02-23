<?php
/**
 * Created by PhpStorm.
 * User: andy123
 * Date: 12/5/17
 * Time: 2:49 PM
 */
namespace Stanford\ImageViewer;

if (!class_exists('Util')) include_once('classes/Util.php');

use \REDCap as REDCap;
use \Files as Files;
use Stanford\Utility\ActionTagHelper;

class ImageViewer extends \ExternalModules\AbstractExternalModule
{

    private $tag = "@IMAGEVIEW";
    private $valid_image_suffixes = array('jpeg','jpg','jpe','gif','png','tif','bmp');
    private $valid_pdf_suffixes = array('pdf');


    function __construct()
    {
        parent::__construct();

        // ADD SOME CONTEXT TO THE GLOBALS FOR THIS MODULE:
        $GLOBALS['external_module_prefix'] = $this->PREFIX;
        $GLOBALS['external_module_log_path'] = $this->getSystemSetting('log-path');
    }


    // Capture normal data-entry
    function hook_data_entry_form_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        self::renderPreview($instrument,$record, $event_id, $repeat_instance, $project_id);
    }


    // Capture surveys
    function hook_survey_page_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
        self::renderPreview($instrument, $record, $event_id, $repeat_instance, $project_id);
    }




    /**
     * Returns an array containing active fields and parameters for each field
     *
     * @return array
     * @throws \Exception
     */
    function getFieldParams() {

        // Fields can come from either the external modules configuration or from custom action-tags - external module settings will trump
        // Get config from External Module settings
        $config_fields = $this->getProjectSetting('fields');
        $config_params = $this->getProjectSetting('field-params');

        // Convert params into array where field is key
        $field_params = array();
        foreach ($config_fields as $i => $field) {
            $field_params[$field] = $config_params[$i];
        }

        // Get from action tags (and only take if not specified in external module settings)
        if (!class_exists('\Stanford\Utility\ActionTagHelper')) include_once('classes/ActionTagHelper.php');

        $action_tag_results = ActionTagHelper::getActionTags($this->tag);
        if (isset($action_tag_results[$this->tag])) {
            foreach ($action_tag_results[$this->tag] as $field => $param_array) {
                if(isset($field_params[$field])) {
                    // This field is already defined in the EM settings - skip Action Tag
                    continue;
                } else {
                    // Add this field to our arrays
                    $field_params[$field] = $param_array['params'];
                }
            }
        }

        // Verify the params are valid json by converting them back to objects
        foreach ($field_params as $field => &$params) {
            $params = json_decode($params);
        }

        Util::log(__FUNCTION__, $field_params);
        return $field_params;
    }


    function hook_every_page_top($project_id = null)
    {
        // When on the online designer, let's highlight the fields tagged for this em
        if (PAGE == "Design/online_designer.php") {
            $this->renderJavascriptSetup();
            ?>
                <script>IVEM.interval = window.setInterval(IVEM.highlightFields, 1000);</script>
            <?php
        }

        if (PAGE == "ProjectSetup/index.php") {
            $this->renderJavascriptSetup();
            ?>
                <style>.em-label { padding: 5px; }</style>
                <script>IVEM.projectSetup();</script>
            <?php
        }
    }


    function renderJavascriptSetup() {
        $active_field_params = $this->getFieldParams();
        ?>
            <script src="<?php print $this->getUrl('js/imageViewer.js'); ?>"></script>
            <script><?php print file_get_contents($this->getModulePath() . 'js/pdfobject.min.js'); ?></script>
            <script>
                IVEM.valid_image_suffixes = <?php print json_encode($this->valid_image_suffixes) ?>;
                IVEM.valid_pdf_suffixes = <?php print json_encode($this->valid_pdf_suffixes) ?>;
                IVEM.field_params = <?php print json_encode($active_field_params) ?>;
            </script>
        <?php
    }


    /**
     * Used to render the preview after a fresh upload
     * @param null $project_id
     */
    function hook_every_page_before_render($project_id = null)
    {
        // Handle survey call-backs for the file after upload
        if ( (PAGE == "surveys/index.php" || PAGE == "DataEntry/file_download.php") && isset($_GET['ivem_preview']) ) {

            /*
            [pid] => 12251
            [__passthru] => DataEntry/file_download.php
            [doc_id_hash] => 2356ab2a910fac5d3ae62a488e3d7499be78bd70
            [id] => 438252
            [s] => BMGtQL8uIz
            [record] => 9
            [page] => my_first_instrument
            [event_id] => 75998
            [field_name] => file_upload
            [instance] => 1
            [__response_hash__] => 7bc7e7d27f22e27252129dea5664723084df21d5e2340f1ba79185ac26dda168
            [pnid] => imageview_em_test
             */

            // This EM relies on a new method for external modules which allows them to quit without an error.  Until
            // that is released, we will just try to play with the buffer to suppress the output of the rest of the
            // script.
            $hack = ! method_exists($this, "exit");

            $field_name = filter_input(INPUT_GET, 'field_name', FILTER_SANITIZE_STRING );
            $active_field_params = $this->getFieldParams();

            // Make sure the field is tagged for this module
            if (!isset($active_field_params[$field_name])) return;

            // Verify this file_id has the right hash
            $doc_id = filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT );
            $doc_id_hash = Files::docIdHash( $doc_id );
            if ($doc_id_hash !== $_GET['doc_id_hash']) return;

            // Get file attributes and contents
            list($mime_type, $doc_name, $contents) = Files::getEdocContentsAttributes($doc_id);

            $suffix = strtolower( pathinfo($doc_name, PATHINFO_EXTENSION) );
            if(! in_array($suffix, array_merge($this->valid_pdf_suffixes, $this->valid_image_suffixes)) ) {
                // Invalid suffix - skip
                Util::log("Invalid Suffix", $doc_name);
            } else {
                // Get size of contents
                if (function_exists('mb_strlen')) {
                    $size = mb_strlen($contents, '8bit');
                } else {
                    $size = strlen($contents);
                }

                if (strlen($contents) > 0)
                {
                    // If we are hacking the buffers, then lets start clean:
                    if ($hack) {
                        ob_end_clean();
                        header("Connection: close");
                        ob_start();
                    }

                    header('Pragma: anytextexeptno-cache', true);
                    if (isset($_GET['stream'])) {
                        // Stream the file (e.g. audio)
                        header('Content-Type: '.$mime_type);
                        header('Content-Disposition: inline; filename="'.$doc_name.'"');
                        header('Content-Length: ' . $size);
                        header("Content-Transfer-Encoding: binary");
                        header('Accept-Ranges: bytes');
                        header('Connection: Keep-Alive');
                        header('X-Pad: avoid browser bug');
                        header('Content-Range: bytes 0-'.($size - 1).'/'.$size);
                    } else {
                        // Download
                        header('Content-Type: '.$mime_type.'; name="'.$doc_name.'"');
                        header('Content-Disposition: attachment; filename="'.$doc_name.'"');
                    }
                    print $contents;

                    // If we are hacking the buffer, let's flush everything now
                    if ($hack) {
                        ob_end_flush();
                        ob_flush();
                        flush();
                    }

                }
            }

            // THIS IS A TEMPORARY HACK UNTIL EXTERNAL MODS SUPPORT
            if ($hack) {
                // return to the EM handler and hope the rest of the page doesn't trigger any problems.
                $_GET[] = array();
                return;
            } else {
                // Use the new method to cleanly exist from this method
                $this->exit();
            }
        }
    }

    /**
     * This function retrieves ducument id from database
     * @param $record
     * @param $event_id
     * @param $field
     * @param $repeat_instance
     * @param $project_id
     */
    function getDocId($record, $event_id, $field, $repeat_instance, $project_id) {
        $sql= '';
        if ($repeat_instance == '1' || $repeat_instance == ''){
            //first instance does not have instance = 1, instead NULL value is stored
            $sql = "select value from redcap_data where project_id = '" . $project_id . "' and event_id = ". $event_id  ." ";
            $sql .= "and record = ". $record  ." and field_name = '". $field  ."' and instance is NULL";
        }
        else {
            $sql = "select value from redcap_data where project_id = '" . $project_id . "' and event_id = ". $event_id  ." ";
            $sql .= "and record = ". $record  ." and field_name = '". $field  ."' and instance = ". $repeat_instance  .""; 
        }
        Util::log("Using SQL", $sql);

        $result = db_query($sql);
        Util::log("SQL query result", $result);

        if ($result) {
            $res = db_fetch_array($result);
            return $res[0];
        }
        else{
            return 0;
        }
        
    }

    /**
     * This function passess along details about existing uploaded files so they can be previewed immediately after the page is rendered
     * @param $instrument
     * @param $record
     * @param $event_id
     * @param $repeat_instance
     * @param $project_id
     */
    function renderPreview($instrument, $record, $event_id, $repeat_instance, $project_id) {
        $active_field_params = $this->getFieldParams();

        // Filter the configured fields to only those on the current instrument
        $instrument_fields = REDCap::getFieldNames($instrument);

        $fields = array_intersect_key($active_field_params, array_flip($instrument_fields));
        Util::log("Fields on $instrument", $fields);

        if (count($fields) == 0) {
            Util::log("There are no active fields on instrument $instrument", "DEBUG");
            return;
        }

        // We need to know the filetype to validate when the file has been previously uploaded...
        /*$q = REDCap::getData('json',$record, array_keys($fields), $event_id);
        *$results = json_decode($q, true);
        *$result = $results[0];
        */
        $preview_fields = array();
        foreach ($fields as $field => $params) {
            //$doc_id = $result[$field];
            $doc_id = self::getDocId($record, $event_id, $field, $repeat_instance, $project_id);
            if ($doc_id > 0) {
                list($mime_type, $doc_name) = Files::getEdocContentsAttributes($doc_id);
                $preview_fields[$field] = array(
                    'suffix' => pathinfo($doc_name, PATHINFO_EXTENSION),
                    'params' => $params,
                    'mime_type' => $mime_type,
                    'doc_name' => $doc_name,
                );
            }
        }
        Util::log("Previewing existing files", $preview_fields);
        $this->renderJavascriptSetup();
        ?>
            <script>
                // Load the fields and parameters and start it up
                IVEM.preview_fields = <?php print json_encode($preview_fields) ?>;
                IVEM.init();
            </script>
        <?php
    }
}
