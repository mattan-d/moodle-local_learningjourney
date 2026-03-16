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

$mform = new \local_learningjourney\form\reminder_form(null, [
    'modoptions' => $modoptions,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
} else if ($data = $mform->get_data()) {
    global $DB;

    $record = new stdClass();
    $record->courseid = $course->id;
    $record->cmid = $data->cmid;
    $record->timetosend = $data->timetosend;
    $record->completionfilter = $data->completionfilter;
    $record->subject = $data->subject ?? null;
    $record->message = $data->message ?? null;
    $record->enabled = empty($data->enabled) ? 0 : 1;
    $record->timecreated = time();
    $record->timemodified = time();

    $DB->insert_record('local_learningjourney', $record);

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

$mform->display();

echo $OUTPUT->footer();

