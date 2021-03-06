<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Perform Scheduled Tasks.
 *
 * @package    plagiarism_turnitinsim
 * @author     John McGettrick <jmcgettrick@turnitin.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/plagiarism/turnitinsim/lib.php');
require_once($CFG->dirroot . '/plagiarism/turnitinsim/classes/callback.class.php');

use plagiarism_turnitinsim\message\new_eula;

/**
 * Scheduled tasks.
 */
class plagiarism_turnitinsim_task {

    public $tsrequest;
    public $tscallback;
    public $tssettings;

    public function __construct( $params = null ) {
        $this->tsrequest = (!empty($params->tsrequest)) ? $params->tsrequest : new plagiarism_turnitinsim_request();
        $this->tscallback = (!empty($params->tscallback)) ? $params->tscallback : new plagiarism_turnitinsim_callback($this->tsrequest);
        $this->tssettings = (!empty($params->tssettings)) ? $params->tssettings : new plagiarism_turnitinsim_settings($this->tsrequest);
        $this->tseula = (!empty($params->tseula)) ? $params->tseula : new plagiarism_turnitinsim_eula();
    }

    /**
     * Send Queued submissions to Turnitin.
     * @return boolean
     */
    public function send_queued_submissions() {
        global $DB;

        // Should this task be run?
        $taskname = get_string('tasksendqueuedsubmissions', 'plagiarism_turnitinsim');
        if (!$this->run_task($taskname)) {
            return true;
        }

        // Create webhook if necessary.
        $webhookid = get_config('plagiarism_turnitinsim', 'turnitin_webhook_id');
        if (empty($webhookid)) {
            $this->tscallback = new plagiarism_turnitinsim_callback($this->tsrequest);
            $this->tscallback->create_webhook();
        }

        // Get Submissions to send.
        // Joined with course_modules so that we don't send queued submissions for submissions belonging to deleted course_modules.
        $submissions = $DB->get_records_sql('SELECT s.id FROM {plagiarism_turnitinsim_sub} s
                                    JOIN {course_modules} c
                                    ON s.cm = c.id
                                    WHERE (status = ? OR status = ?) 
                                        AND c.deletioninprogress = ?
                                    LIMIT ?',
            array(TURNITINSIM_SUBMISSION_STATUS_QUEUED, TURNITINSIM_SUBMISSION_STATUS_CREATED, 0, TURNITINSIM_SUBMISSION_SEND_LIMIT)
        );

        // Create each submission in Turnitin and upload submission.
        foreach ($submissions as $submission) {

            // Reset headers.
            $this->tsrequest->set_headers();

            $tssubmission = new plagiarism_turnitinsim_submission($this->tsrequest, $submission->id);

            if ($tssubmission->getstatus() == TURNITINSIM_SUBMISSION_STATUS_QUEUED) {
                $tssubmission->create_submission_in_turnitin();
            }

            if (!empty($tssubmission->getturnitinid())) {
                $tssubmission->upload_submission_to_turnitin();

                // Set the time for the report to be generated.
                $tssubmission->calculate_generation_time();
            }

            $tssubmission->update();
        }

        return true;
    }

    /**
     * Request a report to be generated and get report scores from Turnitin.
     * @return boolean
     */
    public function get_reports() {
        global $DB;

        // Should this task be run?
        $taskname = get_string('taskgetreportscores', 'plagiarism_turnitinsim');
        if (!$this->run_task($taskname)) {
            return true;
        }

        // Get submissions to request reports for.
        // Joined with course_modules so that we don't request reports for submissions belonging to deleted course_modules.
        $submissions = $DB->get_records_sql('SELECT s.id FROM {plagiarism_turnitinsim_sub} s
                                    JOIN {course_modules} c
                                    ON s.cm = c.id
                                    WHERE ((to_generate = ? AND generation_time <= ?) OR (status = ?)) 
                                        AND c.deletioninprogress = ?
                                        AND turnitinid IS NOT NULL',
            array(1, time(), TURNITINSIM_SUBMISSION_STATUS_REQUESTED, 0)
        );

        // Request reports be generated or get scores for reports that have been requested.
        $count = 0;
        foreach ($submissions as $submission) {
            $tssubmission = new plagiarism_turnitinsim_submission($this->tsrequest, $submission->id);

            // Request Originality Report to be generated if it hasn't already, this should have been done by the
            // webhook callback so ignore anything submitted to Turnitin in the 2 minutes.
            // Otherwise retrieve originality score if we haven't received it back within 5 minutes.
            if ($tssubmission->getstatus() == TURNITINSIM_SUBMISSION_STATUS_UPLOADED
                && $tssubmission->getsubmittedtime() < (time() - $this->get_report_gen_request_delay())) {

                $tssubmission->request_turnitin_report_generation();

            } else if ($tssubmission->getstatus() != TURNITINSIM_SUBMISSION_STATUS_UPLOADED
                && $tssubmission->getrequestedtime() < (time() - $this->get_report_gen_score_delay())) {

                $tssubmission->request_turnitin_report_score();
            }

            // Only process a set number of submissions.
            $count++;
            if ($count == TURNITINSIM_SUBMISSION_SEND_LIMIT) {
                break;
            }
        }

        return true;
    }

    /**
     *
     * This task performs several sub tasks;
     * Test whether the webhook is working, if not create a new one.
     * Check what is the latest version of the EULA and store details locally.
     * Update the features enabled on the Turnitin Account and store locally.
     *
     * @return bool
     */
    public function admin_update() {

        // Should this task be run?
        $taskname = get_string('taskadminupdate', 'plagiarism_turnitinsim');
        if (!$this->run_task($taskname)) {
            return true;
        }

        // Update enabled features.
        $this->check_enabled_features();

        // Test the webhook.
        $this->test_webhook();

        // Check the latest EULA version.
        $this->check_latest_eula_version();

        return true;
    }

    /**
     * Test whether the webhook is working, if not create a new one.
     * @return bool
     */
    public function test_webhook() {
        // Reset headers.
        $this->tsrequest->set_headers();

        // Check webhook is valid.
        $webhookid = get_config('plagiarism_turnitinsim', 'turnitin_webhook_id');

        // If we have a webhook id then retrieve the webhook.
        if ($webhookid) {
            $valid = $this->tscallback->get_webhook($webhookid);

            if (!$valid) {
                $this->tscallback->delete_webhook($webhookid);
                $this->tscallback->create_webhook();
            }
        } else {
            $this->tscallback->create_webhook();
        }

        return true;
    }

    /**
     * Check what is the latest version of the EULA and store details locally.
     */
    public function check_latest_eula_version() {
        // Reset headers.
        $this->tsrequest->set_headers();

        // Get the features enabled so we can check if EULA is required for this tenant.
        $features = json_decode(get_config('plagiarism_turnitinsim', 'turnitin_features_enabled'));
        if (!(bool)$features->tenant->require_eula) {
            return true;
        }

        // Get the latest EULA version.
        $response = $this->tseula->get_latest_version();
        if (!empty($response)) {
            // Compare latest EULA to the current EULA we have stored.
            $currenteulaversion = get_config('plagiarism_turnitinsim', 'turnitin_eula_version');
            $neweulaversion = (empty($response->version)) ? '' : $response->version;

            // Update EULA version and url if necessary.
            if ($currenteulaversion != $neweulaversion) {
                set_config('turnitin_eula_version', $response->version, 'plagiarism_turnitinsim');
                set_config('turnitin_eula_url', $response->url, 'plagiarism_turnitinsim');

                // Notify all users linked to Turnitin that there is a new EULA to accept.
                $message = new new_eula();
                $message->send_message();
            }
        }

        return true;
    }

    /**
     * Check the features enabled for this account in Turnitin.
     */
    public function check_enabled_features() {
        // Get the enabled features.
        $response = $this->tssettings->get_enabled_features();

        if (!empty($response) && $response->httpstatus == TURNITINSIM_HTTP_OK) {

            // Remove status from response.
            unset($response->httpstatus);
            unset($response->status);

            // Compare enabled features to the current enabled features we have stored.
            $currentfeatures = get_config('plagiarism_turnitinsim', 'turnitin_features_enabled');
            $newfeatures = json_encode($response);

            // Update enabled features if necessary.
            if ($currentfeatures != $newfeatures && !empty($newfeatures)) {
                set_config('turnitin_features_enabled', $newfeatures, 'plagiarism_turnitinsim');
            }
        }

        return true;
    }

    /**
     * Check if the task should be run. Initially this will check if the plugin is configured
     * and only run if it is but this could be expanded.
     *
     * return boolean
     */
    public function run_task($taskname = '') {
        $plugin = new plagiarism_plugin_turnitinsim();
        if (!$plugin->is_plugin_configured()) {
            mtrace(get_string('taskoutputpluginnotconfigured', 'plagiarism_turnitinsim', $taskname));

            return false;
        }

        return true;
    }

    /**
     * Get the delay for requesting report generation as a backup to the webhook failing.
     * This is a lot less in testing to avoid long waits.
     *
     * @return int
     */
    public function get_report_gen_request_delay() {
        if (defined('BEHAT_SITE_RUNNING') || defined('BEHAT_TEST')) {
            return TURNITINSIM_REPORT_GEN_REQUEST_DELAY_TESTING;
        }

        return TURNITINSIM_REPORT_GEN_REQUEST_DELAY;
    }

    /**
     * Get the delay for requesting report score as a backup to the webhook failing.
     * This is a lot less in testing to avoid long waits.
     *
     * @return int
     */
    public function get_report_gen_score_delay() {
        if (defined('BEHAT_SITE_RUNNING') || defined('BEHAT_TEST')) {
            return TURNITINSIM_REPORT_GEN_SCORE_DELAY_TESTING;
        }

        return TURNITINSIM_REPORT_GEN_SCORE_DELAY;
    }
}