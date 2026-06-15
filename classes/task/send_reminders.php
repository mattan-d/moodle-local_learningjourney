<?php

namespace local_learningjourney\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/learningjourney/lib.php');
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

        $activitymode = \local_learningjourney_get_activity_mode($reminder);
        $selectedcmids = \local_learningjourney_parse_cmids($reminder);

        $cm = null;
        if ($activitymode === 'specific') {
            $cmid = (int)($selectedcmids[0] ?? $reminder->cmid ?? 0);
            if ($cmid > 0) {
                $cm = get_coursemodule_from_id(null, $cmid, 0, false, IGNORE_MISSING);
                if (!$cm) {
                    return;
                }
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
            $activityurl = $courseurl;
        }

        $directreportsbyusername = \local_learningjourney_build_direct_reports_by_manager_username($users);

        $managerrows = [];
        $managersbyuser = [];

        $targettype = $reminder->targettype ?? 'student';
        $sendtomanagers = $targettype === 'manager';
        $sendtoexternalmanagers = $targettype === 'manager_external';

        if ($sendtomanagers || $sendtoexternalmanagers) {
            $managersbyuser = \local_learningjourney_resolve_managers_by_learner($users);
        }

        $enrolledmanagerids = [];
        if ($sendtomanagers) {
            foreach (\local_learningjourney_get_enrolled_managers($course, $context, $users) as $managerid => $ignored) {
                $enrolledmanagerids[$managerid] = true;
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
                // No specific activity: include every enrolled user.
                $iscomplete = null;
            }

            if ($targettype === 'student') {
                // Send to student.
                $rawsubject = $reminder->subject ?: $this->get_default_subject($course, $cm, $activitymode);
                $subject = $this->replace_placeholders($rawsubject, $user, $course, $cm, $activityurl, $courseurl, $activitymode, $context, $directreportsbyusername, $targettype);

                $messagehtml = $this->render_message(
                    $reminder->message,
                    $user,
                    $course,
                    $cm,
                    $activityurl,
                    $courseurl,
                    $context,
                    (int)$reminder->id,
                    $activitymode,
                    $directreportsbyusername,
                    $targettype
                );

                if ($activitymode === 'all') {
                    $messagehtml .= $this->build_activity_status_table($course, $completion, $user->id);
                }

                $messagehtml = $this->wrap_email_html($subject, $messagehtml);
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
                if (!isset($enrolledmanagerids[$manager->id])) {
                    continue;
                }

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

        // Send personal message to external managers (not enrolled in the course).
        if ($sendtoexternalmanagers) {
            $externalmanagers = \local_learningjourney_get_external_managers($course, $context, $users);
            foreach ($externalmanagers as $manager) {
                $rawsubject = $reminder->subject ?: $this->get_default_subject($course, $cm, $activitymode);
                $subject = $this->replace_placeholders($rawsubject, $manager, $course, $cm, $activityurl, $courseurl, $activitymode, $context, $directreportsbyusername, 'manager_external');

                $messagehtml = $this->render_message(
                    $reminder->message,
                    $manager,
                    $course,
                    $cm,
                    $activityurl,
                    $courseurl,
                    $context,
                    (int)$reminder->id,
                    $activitymode,
                    $directreportsbyusername,
                    'manager_external'
                );

                $messagehtml = $this->wrap_email_html($subject, $messagehtml);
                $messagetext = html_to_text($messagehtml);

                email_to_user(
                    $manager,
                    get_admin(),
                    $subject,
                    $messagetext,
                    $messagehtml
                );
                $sentcount++;
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
                $courseurl,
                $context,
                $activitymode,
                $directreportsbyusername
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
        moodle_url $courseurl,
        \context_course $context,
        int $reminderid,
        string $activitymode = 'specific',
        ?array $directreportsbyusername = null,
        string $targettype = 'student'
    ): string {
        $message = $rawmessage ?? get_string('defaultmessage', 'local_learningjourney');
        $message = $this->replace_placeholders(
            $message,
            $user,
            $course,
            $cm,
            $activityurl,
            $courseurl,
            $activitymode,
            $context,
            $directreportsbyusername,
            $targettype
        );

        // Use per-recipient token URLs so embedded images load in email clients without a Moodle login.
        $message = $this->rewrite_message_files_for_email($message, $context, $reminderid, (int)$user->id);

        // Intentionally do not add any automatic footer.

        return $message;
    }

    /**
     * Turn @@PLUGINFILE@@ paths into tokenpluginfile.php URLs for a specific recipient.
     *
     * @param string $message HTML still containing @@PLUGINFILE@@/... from the database.
     * @param \context_course $context Course context where files are stored.
     * @param int $reminderid Reminder id (file itemid).
     * @param int $recipientuserid User who receives the email (token is scoped to this user).
     * @return string
     */
    protected function rewrite_message_files_for_email(
        string $message,
        \context_course $context,
        int $reminderid,
        int $recipientuserid
    ): string {
        global $CFG;

        if ($reminderid < 1 || $recipientuserid < 1) {
            return $message;
        }

        $message = preg_replace('/@@pluginfile@@\//i', '@@PLUGINFILE@@/', $message);
        $message = preg_replace('#@@PLUGINFILE@@([^/])#', '@@PLUGINFILE@@/$1', $message);

        return file_rewrite_pluginfile_urls(
            $message,
            'pluginfile.php',
            $context->id,
            'local_learningjourney',
            'message',
            $reminderid,
            [
                'includetoken' => $recipientuserid,
                'forcehttps' => strpos($CFG->wwwroot, 'https://') === 0,
            ]
        );
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
        moodle_url $courseurl,
        \context_course $context,
        string $activitymode = 'specific',
        ?array $directreportsbyusername = null
    ): int {
        global $DB;

        $sent = 0;

        foreach ($managerrows as $managerid => $rows) {
            $manager = $DB->get_record('user', ['id' => $managerid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$manager) {
                continue;
            }

            $rawsubject = $reminder->subject ?: $this->get_default_subject($course, $cm, $activitymode);
            $subject = $this->replace_placeholders(
                $rawsubject,
                $manager,
                $course,
                $cm,
                $activityurl,
                $courseurl,
                $activitymode,
                $context,
                $directreportsbyusername,
                'manager'
            );

            $message = $reminder->message ?: get_string('defaultmanagermessage', 'local_learningjourney');
            $message = $this->replace_placeholders(
                $message,
                $manager,
                $course,
                $cm,
                $activityurl,
                $courseurl,
                $activitymode,
                $context,
                $directreportsbyusername,
                'manager'
            );
            $message = $this->rewrite_message_files_for_email(
                $message,
                $context,
                (int)$reminder->id,
                (int)$manager->id
            );

            if ($activitymode !== 'none') {
                $activityname = $cm ? format_string($cm->name) : get_string('allactivities', 'local_learningjourney');

                $message .= \html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                    'activity' => $activityname,
                ]));

                $table = new \html_table();
                if ($cm) {
                    $table->head = [
                        get_string('fullname'),
                        get_string('status'),
                        get_string('completion', 'completion'),
                    ];
                } else {
                    $table->head = [
                        get_string('fullname'),
                        get_string('manageractivitystatuses', 'local_learningjourney'),
                        get_string('completion', 'completion'),
                    ];
                }

                foreach ($rows as $row) {
                    $progressstr = get_string('managerprogress', 'local_learningjourney', $row->progress);

                    if ($cm) {
                        $statusstr = $row->complete
                            ? get_string('managerstatus_complete', 'local_learningjourney')
                            : get_string('managerstatus_notcomplete', 'local_learningjourney');

                        $table->data[] = new \html_table_row([
                            fullname($row->learner),
                            $statusstr,
                            $progressstr,
                        ]);
                    } else {
                        $table->data[] = new \html_table_row([
                            fullname($row->learner),
                            $this->build_manager_activity_status_list($course, $row->learner->id),
                            $progressstr,
                        ]);
                    }
                }

                $message .= \html_writer::table($table);
            }

            // Intentionally do not add any automatic footer.

            $message = $this->wrap_email_html($subject, $message);
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
     * Build HTML list of all activity statuses for one learner.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return string
     */
    protected function build_manager_activity_status_list(\stdClass $course, int $userid): string {
        $completion = new completion_info($course);
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();

        $items = [];
        foreach ($cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if (!$completion->is_enabled($cm)) {
                continue;
            }

            $data = $completion->get_data($cm, false, $userid);
            $iscomplete = !empty($data) && !empty($data->completionstate);
            $status = $iscomplete
                ? get_string('managerstatus_complete', 'local_learningjourney')
                : get_string('managerstatus_notcomplete', 'local_learningjourney');

            $items[] = \html_writer::tag('li', format_string($cm->name) . ' - ' . $status);
        }

        if (empty($items)) {
            return '-';
        }

        return \html_writer::tag('ul', implode('', $items), ['style' => 'margin:0;padding-right:18px;']);
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
     * Wrap email body HTML in a modern, RTL-safe template.
     *
     * @param string $subject
     * @param string $bodyhtml
     * @return string
     */
    protected function wrap_email_html(string $subject, string $bodyhtml): string {
        $title = s($subject);
        $body = $bodyhtml;

        // Basic, email-client-friendly styling (inline, simple tables/divs).
        $css = implode("\n", [
            'body{margin:0;padding:0;background:#f5f7fb;direction:rtl;text-align:right;font-family:Arial !important;color:#111827;}',
            '.container{width:100%;padding:24px 12px;}',
            '.card{max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}',
            '.content{padding:20px;font-family:Arial !important;}',
            '.content *{font-family:Arial !important;}',
            '.content h4{margin:18px 0 10px 0;font-size:14px;}',
            '.content p{margin:0 0 12px 0;line-height:1.6;}',
            'a{color:#0f62fe;text-decoration:underline;}',
            'table{border-collapse:collapse;width:100%;}',
            'th,td{border:1px solid #e5e7eb;padding:8px 10px;vertical-align:top;}',
            'th{background:#f3f4f6;font-weight:bold;}',
            '.muted{color:#6b7280;font-size:12px;}',
        ]);

        return '<!doctype html>' .
            '<html lang="he" dir="rtl">' .
            '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>' . $title . '</title>' .
            '<style>' . $css . '</style></head>' .
            '<body>' .
            '<div class="container">' .
            '<div class="card">' .
            '<div class="content">' . $body . '</div>' .
            '</div>' .
            '</div>' .
            '</body></html>';
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
     * - {directmanager}
     * - {directemployees}
     *
     * @param string $text
     * @param \stdClass $user
     * @param \stdClass $course
     * @param \cm_info|\stdClass|null $cm
     * @param moodle_url $activityurl
     * @param moodle_url $courseurl
     * @param string $activitymode
     * @param \context_course $context
     * @param array|null $directreportsbyusername
     * @return string
     */
    protected function replace_placeholders(
        string $text,
        \stdClass $user,
        \stdClass $course,
        $cm,
        moodle_url $activityurl,
        moodle_url $courseurl,
        string $activitymode = 'specific',
        \context_course $context = null,
        ?array $directreportsbyusername = null,
        string $targettype = 'student'
    ): string {
        global $CFG;

        if ($cm) {
            $activityname = format_string($cm->name);
        } else if ($activitymode === 'none') {
            $activityname = '';
        } else {
            $activityname = get_string('allactivities', 'local_learningjourney');
        }
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

        if ($context) {
            $replacements = array_merge(
                $replacements,
                \local_learningjourney_get_direct_manager_replacements($user, $course, $context, $directreportsbyusername, $targettype)
            );
        }

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
    protected function get_default_subject(\stdClass $course, $cm, string $activitymode = 'specific'): string {
        if ($cm) {
            return get_string('defaultsubject', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'course' => format_string($course->fullname),
            ]);
        }

        if ($activitymode === 'none') {
            return get_string('defaultsubject_noactivity', 'local_learningjourney', [
                'course' => format_string($course->fullname),
            ]);
        }

        return get_string('defaultsubject_course', 'local_learningjourney', [
            'course' => format_string($course->fullname),
        ]);
    }
}

