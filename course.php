<?php

require('../../config.php');

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);

require_login($course);

$context = context_course::instance($course->id);
require_capability('local/learningjourney:managereminders', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/learningjourney/course.php', ['id' => $course->id]));
$PAGE->set_pagelayout('admin');
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

echo $OUTPUT->notification(get_string('reminder_help', 'local_learningjourney'), \core\output\notification::NOTIFY_INFO);

// If preview requested, show a rendered example of the email.
if ($previewdata) {
    global $USER, $SITE;

    if (!isset($cms[$previewdata->cmid])) {
        // Invalid activity selected – skip preview.
    } else {
        $cm = $cms[$previewdata->cmid];

        $activityurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $subject = !empty($previewdata->subject)
            ? $previewdata->subject
            : get_string('defaultsubject', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'course' => format_string($course->fullname),
            ]);

        $message = $previewdata->message ?? get_string('defaultmessage', 'local_learningjourney');

        $replacements = [
            '{{fullname}}' => fullname($USER),
            '{{firstname}}' => $USER->firstname,
            '{{lastname}}' => $USER->lastname,
            '{{activityname}}' => format_string($cm->name),
            '{{coursename}}' => format_string($course->fullname),
            '{{activityurl}}' => $activityurl->out(false),
            '{{courseurl}}' => $courseurl->out(false),
            '{{sitename}}' => format_string($SITE->shortname ?? $SITE->fullname ?? ''),
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

        echo html_writer::tag('h3', get_string('previewheading', 'local_learningjourney'));
        echo html_writer::start_div('box generalbox');
        echo html_writer::tag('p',
            html_writer::tag('strong', get_string('previewsubjectlabel', 'local_learningjourney') . ' ') .
            s($subject)
        );
        echo html_writer::tag('p', html_writer::tag('strong', get_string('previewbodylabel', 'local_learningjourney')));
        echo html_writer::div($message);
        echo html_writer::end_div();
    }
}

// Preview from existing reminder row (without using the form).
if ($previewexistingid && !$previewdata) {
    global $USER, $SITE;

    $reminder = $DB->get_record('local_learningjourney', ['id' => $previewexistingid, 'courseid' => $course->id], '*', IGNORE_MISSING);
    if ($reminder && isset($cms[$reminder->cmid])) {
        $cm = $cms[$reminder->cmid];

        $activityurl = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $subject = !empty($reminder->subject)
            ? $reminder->subject
            : get_string('defaultsubject', 'local_learningjourney', [
                'activity' => format_string($cm->name),
                'course' => format_string($course->fullname),
            ]);

        $message = $reminder->message ?? get_string('defaultmessage', 'local_learningjourney');

        $replacements = [
            '{{fullname}}' => fullname($USER),
            '{{firstname}}' => $USER->firstname,
            '{{lastname}}' => $USER->lastname,
            '{{activityname}}' => format_string($cm->name),
            '{{coursename}}' => format_string($course->fullname),
            '{{activityurl}}' => $activityurl->out(false),
            '{{courseurl}}' => $courseurl->out(false),
            '{{sitename}}' => format_string($SITE->shortname ?? $SITE->fullname ?? ''),
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

        echo html_writer::tag('h3', get_string('previewheading', 'local_learningjourney'));
        echo html_writer::start_div('box generalbox');
        echo html_writer::tag('p',
            html_writer::tag('strong', get_string('previewsubjectlabel', 'local_learningjourney') . ' ') .
            s($subject)
        );
        echo html_writer::tag('p', html_writer::tag('strong', get_string('previewbodylabel', 'local_learningjourney')));
        echo html_writer::div($message);
        echo html_writer::end_div();
    }
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
        get_string('actions', 'local_learningjourney'),
    ];

    foreach ($reminders as $reminder) {
        $activityname = isset($modoptions[$reminder->cmid]) ? $modoptions[$reminder->cmid] : $reminder->cmid;

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
        ]);
        $editurl = new moodle_url('/local/learningjourney/course.php', [
            'id' => $course->id,
            'reminderid' => $reminder->id,
        ]);

        // Use Moodle core-style icon actions (like standard edit/delete icons).
        $previewicon = $OUTPUT->action_icon(
            $previewurl,
            new pix_icon('t/preview', get_string('preview', 'local_learningjourney')),
            null,
            ['class' => 'action-icon']
        );

        $editicon = $OUTPUT->action_icon(
            $editurl,
            new pix_icon('t/edit', get_string('edit', 'local_learningjourney')),
            null,
            ['class' => 'action-icon']
        );

        $actionshtml = html_writer::span($previewicon . ' ' . $editicon, 'actions');

        $row = new html_table_row([
            $activityname,
            userdate($reminder->timetosend),
            $target,
            $filterstr,
            $status,
            $actionshtml,
        ]);

        $table->data[] = $row;
    }

    echo html_writer::tag('h3', get_string('reminderlist', 'local_learningjourney'));
    echo html_writer::table($table);
}

$mform->display();

echo $OUTPUT->footer();

