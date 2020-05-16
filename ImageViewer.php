<?php namespace Stanford\ImageViewer;

if (!class_exists("Util")) include_once("classes/Util.php");

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

    function __construct() {
        parent::__construct();

        // Add some context to $GLOBALS (used by Utli::log)
        $GLOBALS['external_module_prefix'] = $this->PREFIX;
        $GLOBALS['external_module_log_path'] = $this->getSystemSetting('log-path');
    }

    #region Hooks -----------------------------------------------------------------------------------------------------------

    // Capture normal data-entry
    function hook_data_entry_form_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        self::renderPreview($project_id, $instrument,$record, $event_id, $repeat_instance);
    }

    // Capture surveys
    function hook_survey_page_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
        self::renderPreview($project_id, $instrument, $record, $event_id, $repeat_instance, $survey_hash);
    }

    // Designer and Project Setup cosmetics
    function hook_every_page_top($project_id = null)
    {
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
            $hack = ! method_exists($this, "exitAfterHook");

            $field_name = filter_input(INPUT_GET, 'field_name', FILTER_SANITIZE_STRING);
            $active_field_params = $this->getFieldParams();

            // Make sure the field is tagged for this module
            if (!array_key_exists($field_name, $active_field_params)) return;

            // Verify this file_id has the right hash
            $doc_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
            $doc_id_hash = Files::docIdHash($doc_id);
            if ($doc_id_hash !== $_GET['doc_id_hash']) return;

            // Get file attributes and contents
            list($mime_type, $doc_name, $contents) = Files::getEdocContentsAttributes($doc_id);
            $suffix = strtolower(pathinfo($doc_name, PATHINFO_EXTENSION));
            if(!in_array($suffix, array_merge($this->valid_pdf_suffixes, $this->valid_image_suffixes))) {
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
        Util::log(__FUNCTION__, $field_params);
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

        $field_params = array();
        $pds = $this->getProjectDataStructure($project_id);

        $action_tag_results = ActionTagHelper::getActionTags($this->imagePipeTag);
        if (isset($action_tag_results[$this->imagePipeTag])) {
            foreach ($action_tag_results[$this->imagePipeTag] as $field => $param_array) {
                $params = $param_array["params"];
                // Need to create correct context for the piping of special tags (instance, event smart variables)
                $raw_params = json_decode($params);
                if (is_string($raw_params)) {
                    $raw_params = json_decode("{\"field\":\"$params\"}");
                }
                $field_instrument = $pds["fields"][$raw_params->field]["form"];
                $raw_params->event = Piping::pipeSpecialTags($raw_params->event, $project_id, $record, $event_id, $instance, null, false, null, $field_instrument, false, false);
                $ctx_event_id = Event::getEventIdByName($project_id, $raw_params->event);
                $raw_params->instance = Piping::pipeSpecialTags($raw_params->instance, $project_id, $record, $ctx_event_id, null, null, false, null, $field_instrument, false, false);
                $field_params[$field] = $raw_params;
            }
        }
        Util::log(__FUNCTION__, $field_params);
        return $field_params;
    }

    /**
     * Include JavaScript files and output basic JavaScript setup
     */
    function renderJavascriptSetup() {
        $field_params = $this->getFieldParams();
        $debug = $this->getSystemSetting("javascript-debug") == true;
        ?>
            <script src="<?php print $this->getUrl('js/pdfobject.min.js'); ?>"></script>
            <script src="<?php print $this->getUrl('js/imageViewer.js'); ?>"></script>
            <script>
                IVEM.valid_image_suffixes = <?php print json_encode($this->valid_image_suffixes) ?>;
                IVEM.valid_pdf_suffixes = <?php print json_encode($this->valid_pdf_suffixes) ?>;
                IVEM.field_params = <?php print json_encode($field_params) ?>;
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
        $active_field_params = $this->getFieldParams();
        $active_piped_fields = $this->getPipedFields($project_id, $instrument, $record, $event_id, $instance);

        // Filter the configured fields to only those on the current instrument
        $instrument_fields = REDCap::getFieldNames($instrument);
        $fields = array_intersect_key($active_field_params, array_flip($instrument_fields));
        $piped_fields = array_intersect_key($active_piped_fields, array_flip($instrument_fields));
        Util::log("Fields on $instrument", $fields);
        Util::log("Piped fields on $instrument", $piped_fields);

        // Merge in piped fields
        $source_fields = array_merge($fields);
        foreach ($piped_fields as $field => $source) {
            if (!isset($source_fields[$source->field])) {
                $source_fields[$source->field] = @$active_field_params[$field];
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
                "event_id" => $event_id * 1, 
                "instance" => $instance * 1
            );
        }
        foreach ($piped_fields as $field => $source) {
            $query_fields[$field] = array (
                "field" => $source->field, 
                "event_id" => $source->event ? Event::getEventIdByName($project_id, $source->event) : $event_id * 1,
                "instance" => $source->instance * 1 ?: 1
            );
        }
        // Get field data - how to get this depends on the data structure of the project (repeating forms/events)
        $field_data = array();
        $pds = $this->getProjectDataStructure($project_id);
        foreach ($query_fields as $field => $source) {
            $q = REDCap::getData('json',$record, $source["field"], $source["event_id"]);
            $results = json_decode($q, true);
            if ($pds["fields"][$source["field"]]["repeating_form"]) {
                $result = $results[$source["instance"]];
            }
            else {
                $result = $results[0];
            }
            //Util::log($result);
            $field_meta = $Proj->metadata[$source["field"]];
            $field_type = $field_meta['element_type'];
            if ($field_type == 'descriptive' && !empty($field_meta['edoc_id'])) {
                $doc_id = $field_meta['edoc_id'];
            } 
            elseif ($field_type == 'file') {
                $doc_id = $result[$source["field"]];
            } 
            else {
                // invalid field type!
            }
            if ($doc_id > 0) {
                list($mime_type, $doc_name) = Files::getEdocContentsAttributes($doc_id);
                $field_data[$field] = array(
                    'suffix'      => pathinfo($doc_name, PATHINFO_EXTENSION),
                    'params'      => $source_fields[$source["field"]],
                    'mime_type'   => $mime_type,
                    'doc_name'    => $doc_name,
                    'doc_id'      => $doc_id,
                    'hash'        => Files::docIdHash($doc_id),
                    'page'        => $instrument,
                    'field_name'  => $source["field"],
                    'record'      => $record,
                    'event_id'    => $source["event_id"],
                    'instance'    => $source["instance"],
                    'survey_hash' => $survey_hash, 
                );
            }
        }

        $preview_fields = array();
        foreach ($fields as $field => $_) {
            $preview_fields[$field] = $field_data[$field];
            $preview_fields[$field]["piped"] = false;
        }
        foreach ($piped_fields as $into => $from) {
            $preview_fields[$into] = $field_data[$into];
            $preview_fields[$into]["piped"] = true;
            $preview_fields[$into]["params"] = isset($active_field_params[$into]) ? $active_field_params[$into] : @$active_field_params[$from];
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

    #endregion --------------------------------------------------------------------------------------------------------------


    #region Project Data Structure Helper -----------------------------------------------------------------------------------

    /**
     * Gets the repeating forms and events in the current or specified project.
     * 
     * The returned array is structured like so:
     * [
     *   "forms" => [
     *      event_id => [
     *         "form name", "form name", ...
     *      ],
     *      ...
     *   ],
     *   "events" => [
     *      event_id => [
     *        "form name", "form name", ...
     *      ],
     *      ...
     *   ] 
     * ]
     * 
     * @param int|string|null $pid The project id (optional).
     * @return array An associative array listing the repeating forms and events.
     * @throws Exception From requireProjectId if no project id can be found.
     */
    function getRepeatingFormsEvents($pid = null) {
        $pid = $this->requireProjectId($pid);
        
        $result = $this->query('
            select event_id, form_name 
            from redcap_events_repeat 
            where event_id in (
                select m.event_id 
                from redcap_events_arms a
                join redcap_events_metadata m
                on a.arm_id = m.arm_id and a.project_id = ?
            )', $pid);

        $forms = array(
            "forms" => array(),
            "events" => array()
        );
        while ($row = $result->fetch_assoc()) {
            $event_id = $row["event_id"];
            $form_name = $row["form_name"];
            if ($form_name === null) {
                // Entire repeating event. Add all forms in it.
                $forms["events"][$event_id] = $this->getEventForms($event_id);
            }
            else {
                $forms["forms"][$event_id][] = $form_name;
            }
        }
        return $forms;
    }

    /**
     * Gets the names of the forms in the current or specified event.
     * 
     * @param int|null $event_id The event id (optional)
     * @return array An array of form names.
     * @throws Exception From requireProjectId or ExternalModules::getEventId if event_id, project_id cannot be deduced or multiple event ids are in a project.
     */
    function getEventForms($event_id = null) {
        if($event_id === null){
            $event_id = $this->getEventId();
        }
        $forms = array();
        $result = $this->query('
            select form_name
            from redcap_events_forms
            where event_id = ?
        ', $event_id);
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row["form_name"];
        }
        return $forms;
    }


    /**
     * Gets the project structure (arms, events, forms, fields) of the current or specified project.
     * 
     * The returned array is structured like so:
     * [
     *   "forms" => [
     *      "form name" => [
     *          "name" => "form name",
     *          "repeating" => true|false,
     *          "repeating_event" => true|false,
     *          "arms" => [
     *              arm_id => [ 
     *                  "id" => arm_id 
     *              ], ...
     *          ],
     *          "events" => [
     *              event_id => [
     *                  "id" => event_id,
     *                  "name" => "event name",
     *                  "repeating" => true|false
     *              ], ...
     *          ],
     *          "fields" => [
     *              "field name", "field name", ...
     *          ]
     *      ], ...
     *   ],
     *   "events" => [
     *      event_id => [
     *          "id" => event_id,
     *          "name" => "event name",
     *          "repeating" => true|false,
     *          "arm" => arm_id,
     *          "forms" => [
     *              "form_name" => [
     *                  "name" => "form_name",
     *                  "repeating" => true|false
     *              ], ...
     *          ]
     *      ], ...
     *   ],
     *   "arms" => [
     *      arm_id => [
     *          "id" => arm_id
     *          "events" => [
     *              event_id => [
     *                  "id" => event_id,
     *                  "name" => "event name"
     *              ], ...
     *          ],
     *          "forms" => [
     *              "form name" => [
     *                  "name" => "form name"
     *              ], ...
     *          ]
     *      ], ...
     *   ],
     *   "fields" => [
     *      "field name" => [
     *          "name" => "field name",
     *          "form" => "form name",
     *          "repeating_form" => true|false,
     *          "repeating_event" => true|false,
     *          "events" => [
     *              event_id => [ 
     *                  (same as "events" => event_id -- see above)
     *              ], ...
     *          ],
     *          "metadata" => [
     *              (same as in $Proj)
     *          ]
     *      ], ...
     *   ]
     * ] 
     * @param int|string|null $pid The project id (optional).
     * @return array An array containing information about the project's data structure.
     */
    function getProjectDataStructure($pid = null) {
        $pid = $this->requireProjectId($pid);

        // Check cache.
        if (array_key_exists($pid, self::$ProjectDataStructureCache)) return self::$ProjectDataStructureCache[$pid];

        // Use REDCap's Project class to get some of the data. Specifically, unique event names are not in the backend database.
        $proj = new \Project($pid);
        $proj->getUniqueEventNames();

        // Prepare return data structure.
        $ps = array(
            "pid" => $pid,
            "forms" => array(),
            "events" => array(),
            "arms" => array(),
            "fields" => array(),
        );

        // Gather data - arms, events, forms.
        // Some of this might be extractable from $proj, but this is just easier.
        $result = $this->query('
            select a.arm_id, m.event_id, f.form_name
            from redcap_events_arms a
            join redcap_events_metadata m
            on a.arm_id = m.arm_id and a.project_id = ?
            join redcap_events_forms f
            on f.event_id = m.event_id
        ', $pid);
        while ($row = $result->fetch_assoc()) {
            $ps["arms"][$row["arm_id"]]["id"] = $row["arm_id"];
            $ps["arms"][$row["arm_id"]]["events"][$row["event_id"]] = array(
                "id" => $row["event_id"],
                "name" => $proj->uniqueEventNames[$row["event_id"]]
            );
            $ps["arms"][$row["arm_id"]]["forms"][$row["form_name"]] = array(
                "name" => $row["form_name"]
            );
            $ps["events"][$row["event_id"]]["id"] = $row["event_id"];
            $ps["events"][$row["event_id"]]["name"] = $proj->uniqueEventNames[$row["event_id"]];
            $ps["events"][$row["event_id"]]["repeating"] = false;
            $ps["events"][$row["event_id"]]["arm"] = $row["arm_id"];
            $ps["events"][$row["event_id"]]["forms"][$row["form_name"]] = array(
                "name" => $row["form_name"],
                "repeating" => false
            );
            $ps["forms"][$row["form_name"]]["name"] = $row["form_name"];
            $ps["forms"][$row["form_name"]]["repeating"] = false;
            $ps["forms"][$row["form_name"]]["repeating_event"] = false;
            $ps["forms"][$row["form_name"]]["arms"][$row["arm_id"]] = array(
                "id" => $row["arm_id"]
            );
            $ps["forms"][$row["form_name"]]["events"][$row["event_id"]] = array(
                "id" => $row["event_id"],
                "name" => $proj->uniqueEventNames[$row["event_id"]],
                "repeating" => false
            );
        }
        // Gather data - fields. Again, this could be got from $proj, but this is more straightforward to process.
        $result = $this->query('
            select field_name, form_name
            from redcap_metadata
            where project_id = ?
            order by field_order asc
        ', $pid);
        while ($row = $result->fetch_assoc()) {
            $ps["fields"][$row["field_name"]] = array(
                "name" => $row["field_name"],
                "form" => $row["form_name"],
                "repeating_form" => false,
                "repeating_event" => false,
            );
            $ps["forms"][$row["form_name"]]["fields"][] = $row["field_name"];
        }
        // Gather data - repeating forms, events.
        $repeating = $this->getRepeatingFormsEvents($pid);
        foreach ($repeating["forms"] as $eventId => $forms) {
            foreach ($forms as $form) {
                $ps["events"][$eventId]["forms"][$form]["repeating"]= true;
                $ps["forms"][$form]["repeating"] = true;
                // Augment fields.
                foreach ($ps["fields"] as $field => &$field_info) {
                    if ($field_info["form"] == $form) {
                        $field_info["repeating_form"] = true;
                    }
                }
            }
        }
        foreach ($repeating["events"] as $eventId => $forms) {
            $ps["events"][$eventId]["repeating"] = true;
            foreach ($forms as $form) {
                $ps["forms"][$form]["repeating_event"] = true;
                $ps["forms"][$form]["events"][$eventId]["repeating"] = true;
                // Augment fields.
                foreach ($ps["fields"] as $field => &$field_info) {
                    if ($field_info["form"] == $form) {
                        $field_info["repeating_event"] = true;
                    }
                }
            }
        }
        // Augment fields with events.
        foreach ($ps["forms"] as $formName => $formInfo) {
            foreach ($formInfo["fields"] as $field) {
                foreach ($formInfo["events"] as $eventId => $_) {
                    $ps["fields"][$field]["events"][$eventId] = $ps["events"][$eventId];
                }
            }
        }
        // Augment fields with field metadata.
        foreach ($ps["fields"] as $field => &$field_data) {
            $field_data["metadata"] = $proj->metadata[$field];
        }

        // Add to cache.
        self::$ProjectDataStructureCache[$pid] = $ps;

        return $ps;
    }

    private static $ProjectDataStructureCache = array();

    #endregion --------------------------------------------------------------------------------------------------------------
}