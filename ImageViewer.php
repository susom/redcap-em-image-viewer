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

class ImageViewer extends \ExternalModules\AbstractExternalModule
{
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


    function hook_every_page_top($project_id = null) {
        if (PAGE != "Design/online_designer.php") return;
        Util::log("On " . PAGE);
        $active_fields = $this->getProjectSetting('fields');

        ?>
            <script src="<?php print $this->getUrl('js/imageViewer.js'); ?>"></script>
            <script>
                IVEM.fields = <?php print json_encode($active_fields) ?>;
                IVEM.interval = window.setInterval(IVEM.highlightFields, 1000);
            </script>
        <?php
    }


    function renderImageView($instrument) {
        $active_fields = $this->getProjectSetting('fields');

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