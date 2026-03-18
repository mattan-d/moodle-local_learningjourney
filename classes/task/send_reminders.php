<?php

namespace local_learningjourney\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

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

        $cm = null;
        if (!empty($reminder->cmid)) {
            $cm = get_coursemodule_from_id(null, $reminder->cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }
        }

        $context = context_course::instance($course->id);
        $users = get_enrolled_users($context, '', 0, 'u.*');

        if (empty($users)) {
            return;
        }

        $completion = new completion_info($course);

        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        if ($cm) {
            $activityurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        } else {
            // Reminder for all activities in course.
            $activityurl = $courseurl;
        }

        $managerrows = [];
        $managersbyuser = [];

        $sendtomanagers = !empty($reminder->targettype) && $reminder->targettype === 'manager';

        if ($sendtomanagers) {
            // Prepare manager mapping based on custom profile field 'manager' (username).
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
        }

        $sentcount = 0;

        foreach ($users as $user) {
            if ($cm) {
                $iscomplete = $this->user_matches_filter($completion, $cm, $user->id, $reminder->completionfilter, true);
                if ($iscomplete === null) {
                    continue;
                }
            } else {
                // For "all activities" we ignore per-activity completion filter and always include the user.
                $iscomplete = null;
            }

            if (empty($reminder->targettype) || $reminder->targettype === 'student') {
                // Send to student.
                $rawsubject = $reminder->subject ?: $this->get_default_subject($course, $cm);
                $subject = $this->replace_placeholders($rawsubject, $user, $course, $cm, $activityurl, $courseurl);

                $messagehtml = $this->render_message($reminder->message, $user, $course, $cm, $activityurl, $courseurl);

                // If this reminder is for "all activities in course", append per-activity status table for this learner.
                if (!$cm) {
                    $messagehtml .= $this->build_activity_status_table($course, $completion, $user->id);
                }

                $messagetext = html_to_text($messagehtml);

                email_to_user(
                    $user,
                    get_admin(),
                    $subject,
                    $messagetext,
                    $messagehtml
                );
                $sentcount++;
            }

            // Collect data for manager summary if this is a manager-type reminder and manager exists.
            if ($sendtomanagers && isset($managersbyuser[$user->id])) {
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
        if ($sendtomanagers && !empty($managerrows)) {
            $sentcount += $this->send_manager_summaries(
                $managerrows,
                $reminder,
                $course,
                $cm,
                $activityurl,
                $courseurl
            );
        }

        // Mark reminder as sent (single-run reminder) and store sent count.
        $reminder->sent = 1;
        $reminder->senttime = time();
        $reminder->sentcount = $sentcount;
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

        if ($filter === 'completed' || $filter === 'oncomplete') {
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
        $message = $rawmessage ?? get_string('defaultmessage', 'local_learningjourney');
        $message = $this->replace_placeholders($message, $user, $course, $cm, $activityurl, $courseurl);

        // Intentionally do not add any automatic footer.

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
     * @return int Number of manager emails sent
     */
    protected function send_manager_summaries(
        array $managerrows,
        \stdClass $reminder,
        \stdClass $course,
        $cm,
        moodle_url $activityurl,
        moodle_url $courseurl
    ): int {
        global $DB;

        $sent = 0;

        foreach ($managerrows as $managerid => $rows) {
            $manager = $DB->get_record('user', ['id' => $managerid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$manager) {
                continue;
            }

            $rawsubject = $reminder->subject ?: $this->get_default_subject($course, $cm);
            $subject = $this->replace_placeholders($rawsubject, $manager, $course, $cm, $activityurl, $courseurl);

            $message = $reminder->message ?: get_string('defaultmanagermessage', 'local_learningjourney');
            $message = $this->replace_placeholders($message, $manager, $course, $cm, $activityurl, $courseurl);

            $activityname = $cm ? format_string($cm->name) : get_string('allactivities', 'local_learningjourney');

            $message .= \html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                'activity' => $activityname,
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

            // Intentionally do not add any automatic footer.

            $messagetext = html_to_text($message);

            email_to_user(
                $manager,
                get_admin(),
                $subject,
                $messagetext,
                $message
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * Build a status table for all activities in the course for a specific learner,
     * including overall course progress at the end.
     *
     * Used when the reminder is configured for "all activities in course".
     *
     * @param \stdClass $course
     * @param completion_info $completion
     * @param int $userid
     * @return string HTML fragment
     */
    protected function build_activity_status_table(
        \stdClass $course,
        completion_info $completion,
        int $userid
    ): string {
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();

        $table = new \html_table();
        $table->head = [
            get_string('activity', 'local_learningjourney'),
            get_string('status'),
        ];

        foreach ($cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if (!$completion->is_enabled($cm)) {
                continue;
            }

            $data = $completion->get_data($cm, false, $userid);
            $iscomplete = !empty($data) && !empty($data->completionstate);

            $statusstr = $iscomplete
                ? get_string('managerstatus_complete', 'local_learningjourney')
                : get_string('managerstatus_notcomplete', 'local_learningjourney');

            $table->data[] = new \html_table_row([
                format_string($cm->name),
                $statusstr,
            ]);
        }

        $html = '';

        if (!empty($table->data)) {
            $html .= \html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                'activity' => get_string('allactivities', 'local_learningjourney'),
            ]));
            $html .= \html_writer::table($table);
        }

        $progresspercent = progress::get_course_progress_percentage($course, $userid);
        if ($progresspercent === null) {
            $progresspercent = 0;
        } else {
            $progresspercent = round($progresspercent);
        }

        $html .= \html_writer::tag(
            'p',
            get_string('managerprogress', 'local_learningjourney', $progresspercent)
        );

        return $html;
    }

    /**
     * Replace placeholders in subject/body.
     *
     * Supports both legacy {{var}} and new {var} formats.
     *
     * Supported variables:
     * - {firstname}
     * - {activityname}
     * - {duedateshortformat}
     * - {modurl}
     * - {modname}
     *
     * @param string $text
     * @param \stdClass $user
     * @param \stdClass $course
     * @param \cm_info|\stdClass|null $cm
     * @param moodle_url $activityurl
     * @param moodle_url $courseurl
     * @return string
     */
    protected function replace_placeholders(
        string $text,
        \stdClass $user,
        \stdClass $course,
        $cm,
        moodle_url $activityurl,
        moodle_url $courseurl
    ): string {
        global $CFG;

        $activityname = $cm ? format_string($cm->name) : get_string('allactivities', 'local_learningjourney');
        $modname = $cm ? (string)$cm->modname : 'course';
        $duedate = $this->get_module_due_date_timestamp($course, $cm);
        $duedateshortformat = $duedate ? userdate($duedate, get_string('strftimedateshort')) : '';

        $replacements = [
            // New requested format.
            '{firstname}' => $user->firstname ?? '',
            '{activityname}' => $activityname,
            '{duedateshortformat}' => $duedateshortformat,
            '{modurl}' => $activityurl->out(false),
            '{modname}' => $modname,

            // Keep existing legacy format working.
            '{{fullname}}' => fullname($user),
            '{{firstname}}' => $user->firstname ?? '',
            '{{lastname}}' => $user->lastname ?? '',
            '{{activityname}}' => $activityname,
            '{{coursename}}' => format_string($course->fullname),
            '{{activityurl}}' => $activityurl->out(false),
            '{{courseurl}}' => $courseurl->out(false),
            '{{sitename}}' => format_string($CFG->sitename),
        ];

        // Also allow legacy double-brace versions for new variables.
        $replacements['{{modurl}}'] = $replacements['{modurl}'];
        $replacements['{{modname}}'] = $replacements['{modname}'];
        $replacements['{{duedateshortformat}}'] = $replacements['{duedateshortformat}'];

        return strtr($text, $replacements);
    }

    /**
     * Best-effort extraction of an activity due/close date as a timestamp.
     *
     * @param \stdClass $course
     * @param \cm_info|\stdClass|null $cm
     * @return int|null
     */
    protected function get_module_due_date_timestamp(\stdClass $course, $cm): ?int {
        global $DB;

        if (!$cm || empty($cm->modname)) {
            return null;
        }

        // Common due/close fields by module type.
        $fieldmap = [
            'assign' => ['duedate', 'cutoffdate'],
            'quiz' => ['timeclose'],
            'lesson' => ['deadline'],
            'choice' => ['timeclose'],
            'workshop' => ['submissionend', 'assessmentend'],
            'data' => ['timeavailableto', 'timedue'],
        ];

        $fields = $fieldmap[$cm->modname] ?? [];
        if (empty($fields)) {
            return null;
        }

        if (!$instance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', IGNORE_MISSING)) {
            return null;
        }

        foreach ($fields as $field) {
            if (!empty($instance->{$field}) && (int)$instance->{$field} > 0) {
                return (int)$instance->{$field};
            }
        }

        return null;
    }

    /**
     * Default subject when no custom subject was provided.
     *
     * @param \stdClass $course
     * @param \cm_info|\stdClass|null $cm
     * @return string
     */
    protected function get_default_subject(\stdClass $course, $cm): string {
        if ($cm) {
            return get_string('defaultsubject', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'course' => format_string($course->fullname),
            ]);
        }

        return get_string('defaultsubject_course', 'local_learningjourney', [
            'course' => format_string($course->fullname),
        ]);
    }
}

