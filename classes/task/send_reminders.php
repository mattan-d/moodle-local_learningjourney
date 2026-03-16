<?php

namespace local_learningjourney\task;

defined('MOODLE_INTERNAL') || die();

use completion_info;
use context_course;
use moodle_url;
use core_completion\progress;

class send_reminders extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sendreminders', 'local_learningjourney');
    }

    public function execute() {
        global $DB;

        $now = time();

        $reminders = $DB->get_records_select(
            'local_learningjourney',
            'enabled = :enabled AND sent = :sent AND timetosend <= :now',
            ['enabled' => 1, 'sent' => 0, 'now' => $now]
        );

        if (empty($reminders)) {
            return;
        }

        foreach ($reminders as $reminder) {
            $this->process_reminder($reminder);
        }
    }

    /**
     * Process a single reminder definition.
     *
     * @param \stdClass $reminder
     * @return void
     */
    protected function process_reminder(\stdClass $reminder): void {
        global $DB;

        if (!$course = $DB->get_record('course', ['id' => $reminder->courseid], '*', IGNORE_MISSING)) {
            return;
        }

        if (!$cm = get_coursemodule_from_id(null, $reminder->cmid, 0, false, IGNORE_MISSING)) {
            return;
        }

        $context = context_course::instance($course->id);
        $users = get_enrolled_users($context, '', 0, 'u.*');

        if (empty($users)) {
            return;
        }

        $completion = new completion_info($course);

        $activityurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $subject = $reminder->subject ?: get_string('defaultsubject', 'local_learningjourney', [
            'activity' => format_string($cm->name),
            'course' => format_string($course->fullname),
        ]);

        // Prepare manager mapping based on custom profile field 'manager' (username).
        $managersbyuser = [];
        $managerusercache = [];

        $managerfield = $DB->get_record('user_info_field', ['shortname' => 'manager'], '*', IGNORE_MISSING);
        if ($managerfield) {
            $userids = array_map(static function($u) {
                return $u->id;
            }, $users);

            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $params = $inparams + ['fieldid' => $managerfield->id];
            $records = $DB->get_records_select('user_info_data', "fieldid = :fieldid AND userid {$insql}", $params);

            foreach ($records as $rec) {
                $managerusername = trim((string)$rec->data);
                if ($managerusername === '') {
                    continue;
                }
                if (!array_key_exists($managerusername, $managerusercache)) {
                    $managerusercache[$managerusername] = $DB->get_record('user', [
                        'username' => $managerusername,
                        'deleted' => 0,
                    ], '*', IGNORE_MISSING);
                }
                if ($managerusercache[$managerusername]) {
                    $managersbyuser[$rec->userid] = $managerusercache[$managerusername];
                }
            }
        }

        $managerrows = [];

        foreach ($users as $user) {
            $iscomplete = $this->user_matches_filter($completion, $cm, $user->id, $reminder->completionfilter, true);
            if ($iscomplete === null) {
                continue;
            }

            $messagehtml = $this->render_message($reminder->message, $user, $course, $cm, $activityurl, $courseurl);
            $messagetext = html_to_text($messagehtml);

            email_to_user(
                $user,
                get_admin(),
                $subject,
                $messagetext,
                $messagehtml
            );

            // Collect data for manager summary if enabled and manager exists.
            if (!empty($reminder->sendmanagers) && isset($managersbyuser[$user->id])) {
                $manager = $managersbyuser[$user->id];

                $progresspercent = progress::get_course_progress_percentage($course, $user->id);
                if ($progresspercent === null) {
                    $progresspercent = 0;
                } else {
                    $progresspercent = round($progresspercent);
                }

                $managerrows[$manager->id][] = (object)[
                    'learner' => $user,
                    'complete' => (bool)$iscomplete,
                    'progress' => $progresspercent,
                ];
            }
        }

        // Send summary emails to each manager.
        if (!empty($reminder->sendmanagers) && !empty($managerrows)) {
            $this->send_manager_summaries(
                $managerrows,
                $reminder,
                $course,
                $cm,
                $activityurl,
                $courseurl
            );
        }

        // Mark reminder as sent (single-run reminder).
        $reminder->sent = 1;
        $reminder->senttime = time();
        $reminder->timemodified = time();
        $DB->update_record('local_learningjourney', $reminder);
    }

    /**
     * Decide if a user should receive this reminder based on completion filter.
     *
     * @param completion_info $completion
     * @param \cm_info|\stdClass $cm
     * @param int $userid
     * @param string $filter
     * @return bool
     */
    protected function user_matches_filter(completion_info $completion, $cm, int $userid, string $filter, bool $returnstate = false) {
        if ($filter === 'all') {
            return $returnstate ? true : true;
        }

        $data = $completion->get_data($cm, false, $userid);

        $iscomplete = !empty($data) && !empty($data->completionstate);

        if ($filter === 'completed') {
            return $returnstate ? $iscomplete : $iscomplete;
        }

        if ($filter === 'notcompleted') {
            return $returnstate ? !$iscomplete : !$iscomplete;
        }

        return $returnstate ? null : false;
    }

    /**
     * Build the reminder message body with basic placeholders.
     *
     * @param string|null $rawmessage
     * @param \stdClass $user
     * @param \stdClass $course
     * @param \cm_info|\stdClass $cm
     * @param moodle_url $activityurl
     * @param moodle_url $courseurl
     * @return string
     */
    protected function render_message(
        ?string $rawmessage,
        \stdClass $user,
        \stdClass $course,
        $cm,
        moodle_url $activityurl,
        moodle_url $courseurl
    ): string {
        global $CFG;

        $message = $rawmessage ?? get_string('defaultmessage', 'local_learningjourney');

        $replacements = [
            '{{fullname}}' => fullname($user),
            '{{firstname}}' => $user->firstname,
            '{{lastname}}' => $user->lastname,
            '{{activityname}}' => format_string($cm->name),
            '{{coursename}}' => format_string($course->fullname),
            '{{activityurl}}' => $activityurl->out(false),
            '{{courseurl}}' => $courseurl->out(false),
            '{{sitename}}' => format_string($CFG->sitename),
        ];

        $message = strtr($message, $replacements);

        $message .= html_writer::empty_tag('hr');
        $message .= html_writer::tag('p',
            get_string('messagefooter', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'activityurl' => $activityurl->out(false),
                'courseurl' => $courseurl->out(false),
            ])
        );

        return $message;
    }

    /**
     * Send summary emails to managers with learners' completion status and progress.
     *
     * @param array $managerrows managerid => array of row objects
     * @param \stdClass $reminder
     * @param \stdClass $course
     * @param \cm_info|\stdClass $cm
     * @param moodle_url $activityurl
     * @param moodle_url $courseurl
     * @return void
     */
    protected function send_manager_summaries(
        array $managerrows,
        \stdClass $reminder,
        \stdClass $course,
        $cm,
        moodle_url $activityurl,
        moodle_url $courseurl
    ): void {
        global $DB;

        foreach ($managerrows as $managerid => $rows) {
            $manager = $DB->get_record('user', ['id' => $managerid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$manager) {
                continue;
            }

            $subject = $reminder->managersubject ?: get_string('defaultmanagersubject', 'local_learningjourney', [
                'course' => format_string($course->fullname),
            ]);

            $message = $reminder->managermessage ?: get_string('defaultmanagermessage', 'local_learningjourney');

            $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                'activity' => format_string($cm->name),
            ]));

            $table = new \html_table();
            $table->head = [
                get_string('fullname'),
                get_string('status'),
                get_string('completion', 'completion'),
            ];

            foreach ($rows as $row) {
                $statusstr = $row->complete
                    ? get_string('managerstatus_complete', 'local_learningjourney')
                    : get_string('managerstatus_notcomplete', 'local_learningjourney');

                $progressstr = get_string('managerprogress', 'local_learningjourney', $row->progress);

                $table->data[] = new \html_table_row([
                    fullname($row->learner),
                    $statusstr,
                    $progressstr,
                ]);
            }

            $message .= \html_writer::table($table);

            $message .= html_writer::empty_tag('hr');
            $message .= html_writer::tag('p',
                get_string('messagefooter', 'local_learningjourney', [
                    'activity' => format_string($cm->name),
                    'activityurl' => $activityurl->out(false),
                    'courseurl' => $courseurl->out(false),
                ])
            );

            $messagetext = html_to_text($message);

            email_to_user(
                $manager,
                get_admin(),
                $subject,
                $messagetext,
                $message
            );
        }
    }
}

