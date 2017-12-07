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
use Stanford\Utility\ActionTagHelper;

class ImageViewer extends \ExternalModules\AbstractExternalModule
{
    private $tag = "@IMAGEVIEW";

    function __construct()
    {
        parent::__construct();

        // ADD SOME CONTEXT TO THE GLOBALS FOR THIS MODULE:
        $GLOBALS['external_module_prefix'] = $this->PREFIX;
        $GLOBALS['external_module_log_path'] = $this->getSystemSetting('log-path');
    }


    function hook_data_entry_form_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
        self::renderImageView($instrument);
    }


    function hook_survey_page_top($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
        self::renderImageView($instrument);
    }


    function getFields() {
        // Fields can come from either the external modules configuration or from custom action-tags
        $config_fields = $this->getProjectSetting('fields');
        $config_params = $this->getProjectSetting('field-params');

        // Array where values are fields and keys are numerical in order
        if (!class_exists('\Stanford\Utility\ActionTagHelper')) include_once('classes/ActionTagHelper.php');
        $action_tag_fields = array();
        $action_tag_params = array();
        $action_tag_results = ActionTagHelper::getActionTags($this->tag);
        if (isset($action_tag_results[$this->tag])) {
            foreach ($action_tag_results[$this->tag] as $field => $param_array) {
                $action_tag_fields[] = $field;
                $action_tag_params[] = $param_array['params'];
            }
        }
        Util::log("Config Fields", $config_fields, $config_params, "Action Tag Fields", $action_tag_fields, $action_tag_params);

        return array_unique(array_merge($config_fields,$action_tag_fields));
    }


    function hook_every_page_top($project_id = null) {
        // When on the online designer, let's highlight the fields tagged for this em
        if (PAGE == "Design/online_designer.php") {
            $active_fields = $this->getFields();
            Util::log("On " . PAGE . " with:", $active_fields);
            ?>
            <script src="<?php print $this->getUrl('js/imageViewer.js'); ?>"></script>
            <script>
                IVEM.fields = <?php print json_encode($active_fields) ?>;
                IVEM.interval = window.setInterval(IVEM.highlightFields, 1000);
            </script>
            <?php
        }
        if (PAGE == "ProjectSetup/index.php") {
            // When on the project setup page, let's highlight that this em is active
            $active_fields = $this->getFields();
            ?>
            <script src="<?php print $this->getUrl('js/imageViewer.js'); ?>"></script>
            <style>.em-label { padding: 5px; }</style>
            <script>
                IVEM.fields = <?php print json_encode($active_fields) ?>;
                IVEM.projectSetup();
            </script>
            <?php
        }
    }


    function renderImageView($instrument) {
        $active_fields = $this->getFields();

        // Check that active fields are present on the current instrument
        $instrument_fields = REDCap::getFieldNames($instrument);
        $fields = array_intersect($active_fields, $instrument_fields);
        if (count($fields) == 0) {
            Util::log("There are no active fields on instrument $instrument", "DEBUG");
            return;
        }
        ?>
            <script>
                <?php print file_get_contents($this->getModulePath() . 'js/imageViewer.js') ?>
                IVEM.fields = <?php print json_encode($fields) ?>;
                IVEM.init();
            </script>
        <?php
    }
}