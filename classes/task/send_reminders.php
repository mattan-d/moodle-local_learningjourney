<?php

namespace local_learningjourney\task;

defined('MOODLE_INTERNAL') || die();

use completion_info;
use context_course;
use moodle_url;

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

        foreach ($users as $user) {
            if (!$this->user_matches_filter($completion, $cm, $user->id, $reminder->completionfilter)) {
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
    protected function user_matches_filter(completion_info $completion, $cm, int $userid, string $filter): bool {
        if ($filter === 'all') {
            return true;
        }

        $data = $completion->get_data($cm, false, $userid);

        $iscomplete = !empty($data) && !empty($data->completionstate);

        if ($filter === 'completed') {
            return $iscomplete;
        }

        if ($filter === 'notcompleted') {
            return !$iscomplete;
        }

        return false;
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
}

