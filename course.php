<?php

require('../../config.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Replace placeholders in preview subject/body.
 *
 * Supports both legacy {{var}} and new {var} formats.
 *
 * @param string $text
 * @param \stdClass $user
 * @param \stdClass $course
 * @param \cm_info|null $cm
 * @param moodle_url $activityurl
 * @param moodle_url $courseurl
 * @return string
 */
function local_learningjourney_replace_placeholders_preview(
    string $text,
    \stdClass $user,
    \stdClass $course,
    ?\cm_info $cm,
    moodle_url $activityurl,
    moodle_url $courseurl
): string {
    global $CFG, $DB;

    $activityname = $cm ? format_string($cm->name) : get_string('allactivities', 'local_learningjourney');
    $modname = $cm ? (string)$cm->modname : 'course';

    // Best-effort due/close date extraction.
    $duedate = null;
    if ($cm) {
        $fieldmap = [
            'assign' => ['duedate', 'cutoffdate'],
            'quiz' => ['timeclose'],
            'lesson' => ['deadline'],
            'choice' => ['timeclose'],
            'workshop' => ['submissionend', 'assessmentend'],
            'data' => ['timeavailableto', 'timedue'],
        ];
        $fields = $fieldmap[$cm->modname] ?? [];
        if (!empty($fields)) {
            $instance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', IGNORE_MISSING);
            if ($instance) {
                foreach ($fields as $field) {
                    if (!empty($instance->{$field}) && (int)$instance->{$field} > 0) {
                        $duedate = (int)$instance->{$field};
                        break;
                    }
                }
            }
        }
    }

    $duedateshortformat = $duedate ? userdate($duedate, get_string('strftimedateshort')) : '';

    $replacements = [
        '{firstname}' => $user->firstname ?? '',
        '{activityname}' => $activityname,
        '{duedateshortformat}' => $duedateshortformat,
        '{modurl}' => $activityurl->out(false),
        '{modname}' => $modname,

        // Legacy.
        '{{fullname}}' => fullname($user),
        '{{firstname}}' => $user->firstname ?? '',
        '{{lastname}}' => $user->lastname ?? '',
        '{{activityname}}' => $activityname,
        '{{coursename}}' => format_string($course->fullname),
        '{{activityurl}}' => $activityurl->out(false),
        '{{courseurl}}' => $courseurl->out(false),
        '{{sitename}}' => format_string($CFG->sitename),
        '{{modurl}}' => $activityurl->out(false),
        '{{modname}}' => $modname,
        '{{duedateshortformat}}' => $duedateshortformat,
    ];

    return strtr($text, $replacements);
}

$previewpopup = optional_param('previewpopup', 0, PARAM_BOOL);

/**
 * Render a visually separated preview block (Bootstrap card).
 *
 * @param string $subject
 * @param string $bodyhtml
 * @param moodle_url $popupurl
 * @return void
 */
function local_learningjourney_render_preview_card(string $subject, string $bodyhtml, moodle_url $popupurl): void {
    $header = html_writer::div(
        html_writer::tag('strong', get_string('previewheading', 'local_learningjourney')),
        'card-header bg-light'
    );

    $content = html_writer::div(
        html_writer::tag('p',
            html_writer::tag('strong', get_string('previewsubjectlabel', 'local_learningjourney') . ' ') . s($subject)
        ) .
        html_writer::tag('p', html_writer::tag('strong', get_string('previewbodylabel', 'local_learningjourney'))) .
        html_writer::div($bodyhtml, 'border rounded p-3 bg-white'),
        'card-body'
    );

    echo html_writer::div($header . $content, 'card border-primary mb-4');
}

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);

require_login($course);

$context = context_course::instance($course->id);
require_capability('local/learningjourney:managereminders', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/learningjourney/course.php', ['id' => $course->id]));
$PAGE->set_pagelayout($previewpopup ? 'popup' : 'admin');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_title(get_string('pluginname', 'local_learningjourney'));

$modinfo = get_fast_modinfo($course);
$cms = $modinfo->get_cms();

$modoptions = [];
foreach ($cms as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $modoptions[$cm->id] = $cm->get_formatted_name();
}

require_once(__DIR__ . '/classes/form/reminder_form.php');

$reminderid = optional_param('reminderid', 0, PARAM_INT);
$previewexistingid = optional_param('previewid', 0, PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);

// Handle delete action (with sesskey protection).
if ($deleteid && confirm_sesskey()) {
    if ($todelete = $DB->get_record('local_learningjourney', ['id' => $deleteid, 'courseid' => $course->id], '*', IGNORE_MISSING)) {
        $DB->delete_records('local_learningjourney', ['id' => $deleteid]);
        redirect(
            new moodle_url('/local/learningjourney/course.php', ['id' => $course->id]),
            get_string('deleted', 'moodle'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$mform = new \local_learningjourney\form\reminder_form(null, [
    'modoptions' => $modoptions,
    'courseid' => $course->id,
]);

// If editing an existing reminder, preload its data into the form.
if ($reminderid) {
    $existing = $DB->get_record('local_learningjourney', ['id' => $reminderid, 'courseid' => $course->id], '*', IGNORE_MISSING);
    if ($existing) {
        $existing->reminderid = $existing->id;
        unset($existing->id);
        $mform->set_data($existing);
    }
}

$previewdata = null;
$previewpressed = optional_param('preview', null, PARAM_RAW);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
} else if ($previewpressed && ($data = $mform->get_data())) {
    $previewdata = $data;
} else if ($data = $mform->get_data()) {
    global $DB;

    if (!empty($data->reminderid)) {
        // Update existing reminder.
        $record = $DB->get_record('local_learningjourney', ['id' => $data->reminderid, 'courseid' => $course->id], '*', MUST_EXIST);
        $record->cmid = $data->cmid;
        $record->timetosend = $data->timetosend;
        $record->completionfilter = $data->completionfilter;
        $record->subject = $data->subject ?? null;
        $record->message = $data->message ?? null;
        $record->enabled = empty($data->enabled) ? 0 : 1;
        $record->targettype = $data->targettype ?? 'student';
        $record->timemodified = time();

        $DB->update_record('local_learningjourney', $record);
    } else {
        // Insert new reminder.
        $record = new stdClass();
        $record->courseid = $course->id;
        $record->cmid = $data->cmid;
        $record->timetosend = $data->timetosend;
        $record->completionfilter = $data->completionfilter;
        $record->subject = $data->subject ?? null;
        $record->message = $data->message ?? null;
        $record->enabled = empty($data->enabled) ? 0 : 1;
        $record->targettype = $data->targettype ?? 'student';
        $record->sent = 0;
        $record->senttime = null;
        $record->timecreated = time();
        $record->timemodified = time();

        $DB->insert_record('local_learningjourney', $record);
    }

    redirect(
        new moodle_url('/local/learningjourney/course.php', ['id' => $course->id]),
        get_string('remindersaved', 'local_learningjourney'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('pluginname', 'local_learningjourney'));

if (!$previewpopup) {
    echo $OUTPUT->notification(get_string('reminder_help', 'local_learningjourney'), \core\output\notification::NOTIFY_INFO);
}

// Clear "edit mode" CTA and visible hint.
if (!$previewpopup && !empty($reminderid)) {
    echo $OUTPUT->notification(get_string('editingreminder', 'local_learningjourney'), \core\output\notification::NOTIFY_INFO);
    $newurl = new moodle_url('/local/learningjourney/course.php', ['id' => $course->id]);
    echo $OUTPUT->single_button($newurl, get_string('newreminder', 'local_learningjourney'), 'get');
}

// If preview requested, show a rendered example of the email.
if ($previewdata) {
    global $USER, $SITE;

    if (!empty($previewdata->cmid) && isset($cms[$previewdata->cmid])) {
        $cm = $cms[$previewdata->cmid];

        $activityurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $subject = !empty($previewdata->subject)
            ? $previewdata->subject
            : get_string('defaultsubject', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'course' => format_string($course->fullname),
            ]);
        $subject = local_learningjourney_replace_placeholders_preview($subject, $USER, $course, $cm, $activityurl, $courseurl);

        $message = $previewdata->message ?? get_string('defaultmessage', 'local_learningjourney');
        $message = local_learningjourney_replace_placeholders_preview($message, $USER, $course, $cm, $activityurl, $courseurl);

        $message .= html_writer::empty_tag('hr');
        $message .= html_writer::tag('p',
            get_string('messagefooter', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'activityurl' => $activityurl->out(false),
                'courseurl' => $courseurl->out(false),
            ])
        );

        $popupurl = new moodle_url('/local/learningjourney/course.php', [
            'id' => $course->id,
            'previewpopup' => 1,
        ]);
        local_learningjourney_render_preview_card($subject, $message, $popupurl);
    } else {
        // Preview for "all activities in course" (cmid = 0).
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $subject = !empty($previewdata->subject)
            ? $previewdata->subject
            : get_string('defaultsubject_course', 'local_learningjourney', [
                'course' => format_string($course->fullname),
            ]);
        $subject = local_learningjourney_replace_placeholders_preview($subject, $USER, $course, null, $courseurl, $courseurl);

        $message = $previewdata->message ?? get_string('defaultmessage', 'local_learningjourney');
        $message = local_learningjourney_replace_placeholders_preview($message, $USER, $course, null, $courseurl, $courseurl);

        // Do not show the automatic footer for "all activities" previews.

        // Append per-activity status table and overall progress for the current user.
        $completion = new completion_info($course);
        $modinfo = get_fast_modinfo($course);
        $cmsall = $modinfo->get_cms();

        $table = new html_table();
        $table->head = [
            get_string('activity', 'local_learningjourney'),
            get_string('status'),
        ];

        foreach ($cmsall as $cmitem) {
            if (!$cmitem->uservisible) {
                continue;
            }
            if (!$completion->is_enabled($cmitem)) {
                continue;
            }

            $data = $completion->get_data($cmitem, false, $USER->id);
            $iscomplete = !empty($data) && !empty($data->completionstate);

            $statusstr = $iscomplete
                ? get_string('managerstatus_complete', 'local_learningjourney')
                : get_string('managerstatus_notcomplete', 'local_learningjourney');

            $table->data[] = new html_table_row([
                format_string($cmitem->name),
                $statusstr,
            ]);
        }

        if (!empty($table->data)) {
            $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                'activity' => get_string('allactivities', 'local_learningjourney'),
            ]));
            $message .= html_writer::table($table);
        }

        $progresspercent = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
        if ($progresspercent === null) {
            $progresspercent = 0;
        } else {
            $progresspercent = round($progresspercent);
        }

        $message .= html_writer::tag(
            'p',
            get_string('managerprogress', 'local_learningjourney', $progresspercent)
        );

        $popupurl = new moodle_url('/local/learningjourney/course.php', [
            'id' => $course->id,
            'previewpopup' => 1,
        ]);
        local_learningjourney_render_preview_card($subject, $message, $popupurl);
    }
}

// Preview from existing reminder row (without using the form).
if ($previewexistingid && !$previewdata) {
    global $USER, $SITE;

    $reminder = $DB->get_record('local_learningjourney', ['id' => $previewexistingid, 'courseid' => $course->id], '*', IGNORE_MISSING);
    if ($reminder) {
        if (!empty($reminder->cmid) && isset($cms[$reminder->cmid])) {
            $cm = $cms[$reminder->cmid];

            $activityurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
            $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

            $subject = !empty($reminder->subject)
                ? $reminder->subject
                : get_string('defaultsubject', 'local_learningjourney', [
                    'activity' => format_string($cm->name),
                    'course' => format_string($course->fullname),
                ]);
            $subject = local_learningjourney_replace_placeholders_preview($subject, $USER, $course, $cm, $activityurl, $courseurl);

            $message = $reminder->message ?? get_string('defaultmessage', 'local_learningjourney');
            $message = local_learningjourney_replace_placeholders_preview($message, $USER, $course, $cm, $activityurl, $courseurl);

            $message .= html_writer::empty_tag('hr');
            $message .= html_writer::tag('p',
                get_string('messagefooter', 'local_learningjourney', [
                    'activity' => format_string($cm->name),
                    'activityurl' => $activityurl->out(false),
                    'courseurl' => $courseurl->out(false),
                ])
            );

            $popupurl = new moodle_url('/local/learningjourney/course.php', [
                'id' => $course->id,
                'previewid' => $reminder->id,
                'previewpopup' => 1,
            ]);
            local_learningjourney_render_preview_card($subject, $message, $popupurl);
        } else {
            // Existing reminder preview for "all activities".
            $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

            $subject = !empty($reminder->subject)
                ? $reminder->subject
                : get_string('defaultsubject_course', 'local_learningjourney', [
                    'course' => format_string($course->fullname),
                ]);
            $subject = local_learningjourney_replace_placeholders_preview($subject, $USER, $course, null, $courseurl, $courseurl);

            $message = $reminder->message ?? get_string('defaultmessage', 'local_learningjourney');
            $message = local_learningjourney_replace_placeholders_preview($message, $USER, $course, null, $courseurl, $courseurl);

            // Do not show the automatic footer for "all activities" previews.

            // Append per-activity status table and overall progress for the current user.
            $completion = new completion_info($course);
            $modinfo = get_fast_modinfo($course);
            $cmsall = $modinfo->get_cms();

            $table = new html_table();
            $table->head = [
                get_string('activity', 'local_learningjourney'),
                get_string('status'),
            ];

            foreach ($cmsall as $cmitem) {
                if (!$cmitem->uservisible) {
                    continue;
                }
                if (!$completion->is_enabled($cmitem)) {
                    continue;
                }

                $data = $completion->get_data($cmitem, false, $USER->id);
                $iscomplete = !empty($data) && !empty($data->completionstate);

                $statusstr = $iscomplete
                    ? get_string('managerstatus_complete', 'local_learningjourney')
                    : get_string('managerstatus_notcomplete', 'local_learningjourney');

                $table->data[] = new html_table_row([
                    format_string($cmitem->name),
                    $statusstr,
                ]);
            }

            if (!empty($table->data)) {
                $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                    'activity' => get_string('allactivities', 'local_learningjourney'),
                ]));
                $message .= html_writer::table($table);
            }

            $progresspercent = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
            if ($progresspercent === null) {
                $progresspercent = 0;
            } else {
                $progresspercent = round($progresspercent);
            }

            $message .= html_writer::tag(
                'p',
                get_string('managerprogress', 'local_learningjourney', $progresspercent)
            );

            $popupurl = new moodle_url('/local/learningjourney/course.php', [
                'id' => $course->id,
                'previewid' => $reminder->id,
                'previewpopup' => 1,
            ]);
            local_learningjourney_render_preview_card($subject, $message, $popupurl);
        }
    }
}

// If this is a popup preview, do not render the rest of the page.
if ($previewpopup && ($previewdata || $previewexistingid)) {
    echo $OUTPUT->footer();
    exit;
}

// Show existing reminders for this course.
$reminders = $DB->get_records('local_learningjourney', ['courseid' => $course->id], 'timetosend ASC');

if (!empty($reminders)) {
    $table = new html_table();
    $table->head = [
        get_string('activity', 'local_learningjourney'),
        get_string('timetosend', 'local_learningjourney'),
        get_string('targettype', 'local_learningjourney'),
        get_string('completionfilter', 'local_learningjourney'),
        get_string('status', 'local_learningjourney'),
            get_string('sentcount', 'local_learningjourney'),
        get_string('actions', 'local_learningjourney'),
    ];

    foreach ($reminders as $reminder) {
        if (empty($reminder->cmid)) {
            $activityname = get_string('activity_all', 'local_learningjourney');
        } else {
            $activityname = isset($modoptions[$reminder->cmid]) ? $modoptions[$reminder->cmid] : $reminder->cmid;
        }

        $target = (!empty($reminder->targettype) && $reminder->targettype === 'manager')
            ? get_string('target_manager', 'local_learningjourney')
            : get_string('target_student', 'local_learningjourney');

        $filterstr = get_string('filter_' . $reminder->completionfilter, 'local_learningjourney');

        if (!empty($reminder->sent)) {
            $status = get_string('status_sent', 'local_learningjourney', userdate($reminder->senttime));
        } else {
            $status = get_string('status_notsent', 'local_learningjourney');
        }

        $previewurl = new moodle_url('/local/learningjourney/course.php', [
            'id' => $course->id,
            'previewid' => $reminder->id,
            'previewpopup' => 1,
        ]);
        $editurl = new moodle_url('/local/learningjourney/course.php', [
            'id' => $course->id,
            'reminderid' => $reminder->id,
        ]);
        $deleteurl = new moodle_url('/local/learningjourney/course.php', [
            'id' => $course->id,
            'deleteid' => $reminder->id,
            'sesskey' => sesskey(),
        ]);

        // Use Moodle core-style icon actions (like standard edit/delete icons).
        $previewicon = $OUTPUT->action_icon(
            $previewurl,
            new pix_icon('t/preview', get_string('preview', 'local_learningjourney')),
            null,
            [
                'class' => 'action-icon',
                'target' => '_blank',
                'rel' => 'noopener',
            ]
        );

        $editicon = $OUTPUT->action_icon(
            $editurl,
            new pix_icon('t/edit', get_string('edit', 'local_learningjourney')),
            null,
            ['class' => 'action-icon']
        );
        $deleteicon = $OUTPUT->action_icon(
            $deleteurl,
            new pix_icon('t/delete', get_string('delete', 'local_learningjourney')),
            null,
            ['class' => 'action-icon']
        );

        $actionshtml = html_writer::span($previewicon . ' ' . $editicon . ' ' . $deleteicon, 'actions');

        $row = new html_table_row([
            $activityname,
            userdate($reminder->timetosend),
            $target,
            $filterstr,
            $status,
            (string)($reminder->sentcount ?? 0),
            $actionshtml,
        ]);

        $table->data[] = $row;
    }

    echo html_writer::tag('h3', get_string('reminderlist', 'local_learningjourney'));
    echo html_writer::table($table);
}

$mform->display();

echo $OUTPUT->footer();

