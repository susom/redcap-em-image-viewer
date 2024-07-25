<?php namespace Stanford\ImageViewer;

if (!class_exists("Util")) include_once("classes/Util.php");

use DE\RUB\ImageViewerExternalModule\Project;
use \REDCap as REDCap;
use \Files as Files;
use \Piping as Piping;
use \Event as Event;

use Stanford\Utility\ActionTagHelper;

class ImageViewer extends \ExternalModules\AbstractExternalModule {

    private $imageViewTag = "@IMAGEVIEW";
    private $imagePipeTag = "@IMAGEPIPE";
    private $valid_image_suffixes = array('jpeg','jpg','jpe','gif','png','tif','bmp');
    private $valid_pdf_suffixes = array('pdf');
    private $valid_dicom_suffixes = array('dcm');
    private $logger_initialized = false;

    private function initLogger() {
        if (!$this->logger_initialized) return;
        // Add some context to $GLOBALS (used by Utli::log)
        $GLOBALS['external_module_prefix'] = $this->PREFIX;
        $GLOBALS['external_module_log_path'] = $this->getSystemSetting('log-path');
        $this->logger_initialized = true;
    }

    #region Hooks -----------------------------------------------------------------------------------------------------------

    // Capture normal data-entry
    function hook_data_entry_form_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        $this->initLogger();
        $this->renderPreview($project_id, $instrument,$record, $event_id, $repeat_instance);
    }

    // Capture surveys
    function hook_survey_page_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
        $this->initLogger();
        $this->renderPreview($project_id, $instrument, $record, $event_id, $repeat_instance, $survey_hash);
    }

    // Designer and Project Setup cosmetics
    function hook_every_page_top($project_id = null)
    {
        $this->initLogger();
        // When on the online designer, let's highlight the fields tagged for this EM
        if (PAGE == "Design/online_designer.php") {
            $this->renderJavascriptSetup();
            ?>
                <script>IVEM.interval = window.setInterval(IVEM.highlightFields, 1000);</script>
            <?php
        }
        // Announce that this project is using the Image Viewer EM
        if (PAGE == "ProjectSetup/index.php") {
            $this->renderJavascriptSetup();
            ?>
                <style>.em-label { padding: 5px; }</style>
                <script>IVEM.projectSetup();</script>
            <?php
        }
    }

    // Renders the preview after a fresh upload
    function hook_every_page_before_render($project_id = null)
    {
        $this->initLogger();
        $project_id = $project_id === null ? -1 : $project_id * 1;
        // Handle survey call-backs for the file after upload
        if ((PAGE == "surveys/index.php" || PAGE == "DataEntry/file_download.php") && isset($_GET["ivem_preview"])) {

            // Verify payload
            if (!class_exists("\DE\RUB\CryptoHelper")) include_once("classes/CryptoHelper.php");
            $crypto = \DE\RUB\CryptoHelper\Crypto::init();
            $payload = $_GET["ivem_preview"];
            $payload = $crypto->decrypt($payload);
            if (!is_array($payload) || $payload["pid"] !== $project_id) {
                return;
            }

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
            $hack = ! method_exists($this, "exitAfterHook");

            $field_name = filter_input(INPUT_GET, 'field_name', FILTER_SANITIZE_STRING);
            $active_field_params = $this->getFieldParams();
            // Make sure the field is tagged for this module and that download is allowed
            if (!array_key_exists($field_name, $active_field_params)) return;
            if (!in_array($field_name, $payload["allowed"], true)) return;

            // Verify this file_id has the right hash
            $doc_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
            $doc_id_hash = Files::docIdHash($doc_id);
            if ($doc_id_hash !== $_GET['doc_id_hash']) return;

            // Get file attributes and contents
            list($mime_type, $doc_name, $contents) = Files::getEdocContentsAttributes($doc_id);
            $suffix = strtolower(pathinfo($doc_name, PATHINFO_EXTENSION));
            if(!in_array($suffix, array_merge($this->valid_pdf_suffixes, $this->valid_image_suffixes,
                $this->valid_dicom_suffixes))) {
                // Invalid suffix - skip
                Util::log("Invalid Suffix", $doc_name, "ERROR");
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
                // Return to the EM handler and hope the rest of the page doesn't trigger any problems.
                $_GET[] = array();
                return;
            }
            else {
                // Use the new method to cleanly exist from this method
                $this->exitAfterHook();
            }
        }
    }

    #endregion --------------------------------------------------------------------------------------------------------------


    #region Setup and Rendering ---------------------------------------------------------------------------------------------

    /**
     * Returns an array containing active fields and parameters for each field
     * @return array
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
        if (!class_exists("\Stanford\Utility\ActionTagHelper")) include_once("classes/ActionTagHelper.php");

        $action_tag_results = ActionTagHelper::getActionTags($this->imageViewTag);
        if (isset($action_tag_results[$this->imageViewTag])) {
            foreach ($action_tag_results[$this->imageViewTag] as $field => $param_array) {
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
        Util::log(__FUNCTION__, $field_params, "DEBUG");
        return $field_params;
    }

    /**
     * Returns an array containing piped fields (@IMAGEPIPE action-tag). This needs context in order to find the correct
     * source field.
     * @param $project_id
     * @param $instrument
     * @param $record
     * @param $event_id
     * @param $instance
     * @return array
     */
    function getPipedFields($project_id = null, $instrument = null, $record = null, $event_id = null, $instance = 1) {
        // Get from action tags (and only take if not specified in external module settings)
        if (!class_exists("\Stanford\Utility\ActionTagHelper")) include_once("classes/ActionTagHelper.php");

        if (!class_exists("\DE\RUB\ImageViewerExternalModule\Project")) include_once ("classes/Project.php");
        $project = new Project($this->framework, $project_id ?: $this->framework->getProjectId());

        $field_params = array();

        $action_tag_results = ActionTagHelper::getActionTags($this->imagePipeTag);
        if (isset($action_tag_results[$this->imagePipeTag])) {
            foreach ($action_tag_results[$this->imagePipeTag] as $field => $param_array) {
                $params = $param_array["params"];
                // Need to create correct context for the piping of special tags (instance, event smart variables)
                $raw_params = json_decode($params, true);
                if (is_string($raw_params)) {
                    $raw_params = json_decode("{\"field\":\"$raw_params\",\"event\":\"[event-name]\",\"instance\":\"[current-instance]\"}", true);
                }
                if (!isset($raw_params["event"])) $raw_params["event"] = "[event-name]";
                if (!isset($raw_params["instance"])) $raw_params["instance"] = "[current-instance]";
                $field_instrument = $project->getFormByField($raw_params["field"]);
                $raw_params["event"] = Piping::pipeSpecialTags($raw_params["event"] ?: "[event-name]", $project_id, $record, $event_id, $instance, null, false, null, $field_instrument, false, false);
                $ctx_event_id = is_numeric($raw_params["event"]) ? $raw_params["event"] * 1 : Event::getEventIdByName($project_id, $raw_params["event"]);
                $ctx_instance = $ctx_event_id == $event_id ? $instance : 1;
                $raw_params["instance"] = Piping::pipeSpecialTags($raw_params["instance"] ?: "[current-instance]", $project_id, $record, $ctx_event_id, $ctx_instance, null, false, null, $field_instrument, false, false);
                $field_params[$field] = $raw_params;
            }
        }
        Util::log(__FUNCTION__, $field_params, "DEBUG");
        return $field_params;
    }

    /**
     * Include JavaScript files and output basic JavaScript setup
     */
    function renderJavascriptSetup($project_id = null) {
        $field_params = $this->getFieldParams();
        // Make a list of all fields that may be downloaded
        $allowed = array_values(array_map(function($e) {
            return $e->field;
        }, $this->getPipedFields()));
        $allowed = array_unique(array_merge($allowed, array_keys($field_params)));
        $debug = $this->getProjectSetting("javascript-debug") == true;
        // Security token - needed to perform safe piping
        if ($project_id) {
            if (!class_exists("\DE\RUB\CryptoHelper")) include_once("classes/CryptoHelper.php");
            $crypto = \DE\RUB\CryptoHelper\Crypto::init();
            $payload = $crypto->encrypt(array(
                "pid" => $project_id * 1,
                "allowed" => $allowed
            ));
        }
        else {
            $payload = "nop";
        }
        $payload = urlencode($payload);
        ?>
            <script src="<?php print $this->getUrl('js/pdfobject.min.js'); ?>"></script>
            <script src="<?php print $this->getUrl('js/dwv.min.js'); ?>"></script>
            <script src="<?php print $this->getUrl('js/imageViewer.js'); ?>"></script>
            <script>
                IVEM.valid_image_suffixes = <?php print json_encode($this->valid_image_suffixes) ?>;
                IVEM.valid_pdf_suffixes = <?php print json_encode($this->valid_pdf_suffixes) ?>;
                IVEM.valid_dicom_suffixes = <?php print json_encode($this->valid_dicom_suffixes) ?>;
                IVEM.field_params = <?php print json_encode($field_params) ?>;
                IVEM.payload = <?php print json_encode($payload) ?>;
                IVEM.debug = <?php print json_encode($debug) ?>;
                IVEM.log("Initialized IVEM", IVEM);
            </script>
        <?php
    }

    /**
     * This function passess along details about existing uploaded files so they can be previewed immediately after the
     * page is rendered or displayed when piped with the @IMAGEPIPE action-tag
     * @param $project_id
     * @param $instrument
     * @param $record
     * @param $event_id
     * @param $instance
     * @param @survey_hash
     * @throws \Exception
     */
    function renderPreview($project_id, $instrument, $record, $event_id, $instance, $survey_hash = null) {

        if (!class_exists("\DE\RUB\ImageViewerExternalModule\Project")) include_once ("classes/Project.php");
        $project = new Project($this->framework, $project_id);

        $active_field_params = $this->getFieldParams();
        $active_piped_fields = $this->getPipedFields($project_id, $instrument, $record, $event_id, $instance);

        // Filter the configured fields to only those on the current instrument
        $instrument_fields = REDCap::getFieldNames($instrument);
        $fields = array_intersect_key($active_field_params, array_flip($instrument_fields));
        $piped_fields = array_intersect_key($active_piped_fields, array_flip($instrument_fields));
        Util::log("Fields on $instrument", $fields, "DEBUG");
        Util::log("Piped fields on $instrument", $piped_fields, "DEBUG");

        // Merge in piped fields
        $source_fields = array_merge($fields);
        foreach ($piped_fields as $field => $source) {
            if (!isset($source_fields[$source["field"]])) {
                $source_fields[$source["field"]] = @$active_field_params[$field];
            }
        }
        // Anything to do?
        if (count($fields) + count($piped_fields) == 0) {
            Util::log("There are no active fields or piped fields on instrument $instrument", "DEBUG");
            return;
        }

        // We need to know the filetype to validate when the file has been previously uploaded...
        // Get type of field
        global $Proj;
        $query_fields = array();
        foreach (array_keys($fields) as $field) {
            $query_fields[$field] = array(
                "field" => $field,
                "event_id" => intval($event_id),
                "instance" => intval($instance ?? "1")
            );
        }
        foreach ($piped_fields as $field => $source) {
            $source_event = $source["event"] === null ? $event_id : $source["event"];
            $query_fields[$field] = array (
                "field" => $source["field"],
                "event_id" => is_numeric($source_event) ? intval($source_event) : Event::getEventIdByName($project_id, $source_event),
                "instance" => max(1, intval($source["instance"]))
            );
        }
        // Get field data - how to get this depends on the data structure of the project (repeating forms/events)
        $field_data = array();
        foreach ($query_fields as $field => $source) {
            $sourceField = $source["field"];
            $sourceForm = $project->getFormByField($sourceField);
            $sourceEventId = $source["event_id"];
            $sourceInstance = $source["instance"];
            $data = REDCap::getData('array',$record, $sourceField);
            if ($project->isFieldOnRepeatingForm($sourceField, $sourceEventId)) {
                $result = $data[$record]["repeat_instances"][$sourceEventId][$sourceForm][$sourceInstance];
            }
            else if ($project->isEventRepeating($sourceEventId)) {
                $result = $data[$record]["repeat_instances"][$sourceEventId][null][$sourceInstance];
            }
            else {
                $result = $data[$record][$sourceEventId];
            }
            //Util::log($result);
            $field_meta = $Proj->metadata[$sourceField];
            $field_type = $field_meta['element_type'];
            if ($field_type == 'descriptive' && !empty($field_meta['edoc_id'])) {
                $doc_id = $field_meta['edoc_id'];
            }
            elseif ($field_type == 'file') {
                $doc_id = $result[$sourceField];
            }
            else {
                // invalid field type!
            }
            $field_data[$field] = array (
                'container_id' => "ivem-$field-$event_id-$instance",
                'params'       => $source_fields[$source["field"]],
                'page'         => $instrument,
                'field_name'   => $sourceField,
                'record'       => $record,
                'event_id'     => $sourceEventId,
                'instance'     => $sourceInstance,
                'survey_hash'  => $survey_hash,
                'pipe_source'  => "$sourceField-$sourceEventId-$sourceInstance",
            );
            if ($doc_id > 0) {
                list($mime_type, $doc_name) = Files::getEdocContentsAttributes($doc_id);
                $field_data[$field]["suffix"]    = htmlspecialchars(strtolower(pathinfo($doc_name, PATHINFO_EXTENSION)), ENT_QUOTES);
                $field_data[$field]["mime_type"] = htmlspecialchars($mime_type, ENT_QUOTES);
                $field_data[$field]["doc_name"]  = htmlspecialchars($doc_name, ENT_QUOTES);
                $field_data[$field]["doc_id"]    = htmlspecialchars($doc_id, ENT_QUOTES);
                $field_data[$field]["hash"]      = htmlspecialchars(Files::docIdHash($doc_id), ENT_QUOTES);
            }
        }

        $preview_fields = array();
        foreach ($fields as $field => $_) {
            $preview_fields[$field] = $field_data[$field];
            $preview_fields[$field]["piped"] = false;
        }
        $pipe_sources = array();
        foreach ($piped_fields as $into => $from) {
            $pipe_sources[$from["field"]] = true;
            $preview_fields[$into] = $field_data[$into];
            $preview_fields[$into]["piped"] = true;
            $preview_fields[$into]["params"] = isset($active_field_params[$into]) ? $active_field_params[$into] : ($active_field_params[$from["field"]] ?? null);
        }

        Util::log("Previewing existing files", $preview_fields, "DEBUG");

        $this->renderJavascriptSetup($project_id);
        ?>
            <script>
                // Load the fields and parameters and start it up
                IVEM.preview_fields = <?php print json_encode($preview_fields) ?>;
                IVEM.pipe_sources = <?php print json_encode($pipe_sources) ?>;
                IVEM.init();
            </script>
        <?php
    }

    #endregion --------------------------------------------------------------------------------------------------------------

}
