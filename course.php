<?php

require('../../config.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');

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

/**
 * Wrap preview content in the same RTL email template used for sending.
 *
 * @param string $subject
 * @param string $bodyhtml
 * @return string
 */
function local_learningjourney_wrap_email_html_preview(string $subject, string $bodyhtml): string {
    $title = s($subject);
    $body = $bodyhtml;

    // IMPORTANT: Preview HTML is embedded inside a Moodle page, so do not output <html>/<head>/<body>.
    // Use inline styles to approximate the sent email look.
    $containerstyle = 'direction:rtl;text-align:right;font-family:Arial !important;color:#111827;background:#f5f7fb;padding:16px;border-radius:12px;';
    $cardstyle = 'max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;';
    $headerstyle = 'padding:18px 20px;background:#0f62fe;color:#ffffff;font-size:16px;font-weight:bold;font-family:Arial !important;';
    $contentstyle = 'padding:20px;font-family:Arial !important;';

    return \html_writer::tag('div',
        \html_writer::tag('div',
            \html_writer::tag('div', $title, ['style' => $headerstyle]) .
            \html_writer::tag('div', $body, ['style' => $contentstyle]),
            ['style' => $cardstyle]
        ),
        ['style' => $containerstyle, 'dir' => 'rtl']
    );
}

/**
 * Resolve draft or saved pluginfile URLs in reminder message HTML for display.
 *
 * Moodle 4.4+ format_text() no longer accepts component/filearea/itemid; callers must run
 * file_rewrite_pluginfile_urls() first. Draft files live in the user context, not the course.
 *
 * @param string $html Raw HTML from editor or DB (may contain @@PLUGINFILE@@/...).
 * @param context_course $context Course context (for saved reminder files and format_text).
 * @param int $draftitemid Draft item id when previewing unsaved form data.
 * @param int $reminderitemid Reminder id when rendering stored message.
 * @return string
 */
function local_learningjourney_format_message_embeds(
    string $html,
    context_course $context,
    int $draftitemid = 0,
    int $reminderitemid = 0
): string {
    global $CFG, $USER;

    // file_rewrite_pluginfile_urls() only matches @@PLUGINFILE@@/ exactly.
    $html = preg_replace('/@@pluginfile@@\//i', '@@PLUGINFILE@@/', $html);
    $html = preg_replace('#@@PLUGINFILE@@([^/])#', '@@PLUGINFILE@@/$1', $html);

    $forcehttps = strpos($CFG->wwwroot, 'https://') === 0;

    if ($draftitemid > 0) {
        $usercontext = context_user::instance($USER->id);
        $html = file_rewrite_pluginfile_urls(
            $html,
            'pluginfile.php',
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            ['forcehttps' => $forcehttps]
        );
    } else if ($reminderitemid > 0) {
        $html = file_rewrite_pluginfile_urls(
            $html,
            'pluginfile.php',
            $context->id,
            'local_learningjourney',
            'message',
            $reminderitemid,
            ['forcehttps' => $forcehttps]
        );
    }

    return format_text($html, FORMAT_HTML, [
        'context' => $context,
        'noclean' => true,
        'filter' => false,
        'para' => false,
        'overflowdiv' => true,
    ]);
}

/**
 * Preview helper: match user against selected filter.
 *
 * @param completion_info $completion
 * @param \cm_info|null $cm
 * @param int $userid
 * @param string $filter
 * @return bool|null
 */
function local_learningjourney_user_matches_filter_preview(
    completion_info $completion,
    ?\cm_info $cm,
    int $userid,
    string $filter
): ?bool {
    if (!$cm) {
        // Keep parity with scheduled task for "all activities".
        return null;
    }

    if ($filter === 'all') {
        return true;
    }

    $data = $completion->get_data($cm, false, $userid);
    $iscomplete = !empty($data) && !empty($data->completionstate);

    if ($filter === 'completed' || $filter === 'oncomplete') {
        return $iscomplete;
    }
    if ($filter === 'notcompleted') {
        return !$iscomplete;
    }

    return null;
}

/**
 * Build manager rows for preview with same logic as scheduled task.
 *
 * @param \stdClass $course
 * @param \cm_info|null $cm
 * @param string $completionfilter
 * @return array managerid => array of row objects
 */
function local_learningjourney_get_manager_rows_preview(\stdClass $course, ?\cm_info $cm, string $completionfilter): array {
    global $DB;

    $context = context_course::instance($course->id);
    $users = get_enrolled_users($context, '', 0, 'u.*');
    if (empty($users)) {
        return [];
    }

    $completion = new completion_info($course);

    // Resolve manager user by custom profile field "manager" (username).
    $managersbyuser = [];
    $managerfield = $DB->get_record('user_info_field', ['shortname' => 'manager'], '*', IGNORE_MISSING);
    if ($managerfield) {
        $managercache = [];
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
            if (!array_key_exists($managerusername, $managercache)) {
                $managercache[$managerusername] = $DB->get_record('user', [
                    'username' => $managerusername,
                    'deleted' => 0,
                ], '*', IGNORE_MISSING);
            }
            if ($managercache[$managerusername]) {
                $managersbyuser[$rec->userid] = $managercache[$managerusername];
            }
        }
    }

    $rowsbymanager = [];
    foreach ($users as $user) {
        $iscomplete = local_learningjourney_user_matches_filter_preview($completion, $cm, $user->id, $completionfilter);
        if ($cm && $iscomplete === null) {
            continue;
        }
        if ($cm && ($completionfilter === 'completed' || $completionfilter === 'oncomplete' || $completionfilter === 'notcompleted')) {
            if ($completionfilter === 'completed' || $completionfilter === 'oncomplete') {
                if (!$iscomplete) {
                    continue;
                }
            } else if ($completionfilter === 'notcompleted' && $iscomplete) {
                continue;
            }
        }

        if (!isset($managersbyuser[$user->id])) {
            continue;
        }
        $manager = $managersbyuser[$user->id];
        $progresspercent = \core_completion\progress::get_course_progress_percentage($course, $user->id);
        $progresspercent = ($progresspercent === null) ? 0 : round($progresspercent);

        $rowsbymanager[$manager->id][] = (object)[
            'learner' => $user,
            'complete' => (bool)$iscomplete,
            'progress' => $progresspercent,
        ];
    }

    return $rowsbymanager;
}

/**
 * Build preview HTML list of all activity statuses for one learner.
 *
 * @param \stdClass $course
 * @param int $userid
 * @return string
 */
function local_learningjourney_build_activity_status_list_preview(\stdClass $course, int $userid): string {
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

        $items[] = html_writer::tag('li', format_string($cm->name) . ' - ' . $status);
    }

    if (empty($items)) {
        return '-';
    }

    return html_writer::tag('ul', implode('', $items), ['style' => 'margin:0;padding-right:18px;']);
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
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_learningjourney', 'message', (int)$todelete->id);
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

        // Persist embedded files and rewrite draftfile.php links to @@PLUGINFILE@@ in stored HTML.
        if (!empty($data->messageitemid)) {
            $rewritten = file_save_draft_area_files(
                (int)$data->messageitemid,
                $context->id,
                'local_learningjourney',
                'message',
                (int)$record->id,
                null,
                (string)($data->message ?? '')
            );
            if ($rewritten !== null) {
                $DB->set_field('local_learningjourney', 'message', $rewritten, ['id' => $record->id]);
            }
        }
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

        $record->id = $DB->insert_record('local_learningjourney', $record);

        // Persist embedded files and rewrite draftfile.php links to @@PLUGINFILE@@ in stored HTML.
        if (!empty($data->messageitemid)) {
            $rewritten = file_save_draft_area_files(
                (int)$data->messageitemid,
                $context->id,
                'local_learningjourney',
                'message',
                (int)$record->id,
                null,
                (string)($data->message ?? '')
            );
            if ($rewritten !== null) {
                $DB->set_field('local_learningjourney', 'message', $rewritten, ['id' => $record->id]);
            }
        }
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
        $message = local_learningjourney_format_message_embeds(
            $message,
            $context,
            (int)($previewdata->messageitemid ?? 0),
            0
        );

        // Manager preview: show the same summary table structure sent by cron.
        if (($previewdata->targettype ?? 'student') === 'manager') {
            $rowsbymanager = local_learningjourney_get_manager_rows_preview($course, $cm, $previewdata->completionfilter ?? 'all');
            $managerrows = $rowsbymanager[$USER->id] ?? [];
            if (empty($managerrows) && !empty($rowsbymanager)) {
                $managerrows = reset($rowsbymanager);
            }

            $table = new html_table();
            $table->head = [get_string('fullname'), get_string('status'), get_string('completion', 'completion')];
            foreach ($managerrows as $row) {
                $statusstr = $row->complete
                    ? get_string('managerstatus_complete', 'local_learningjourney')
                    : get_string('managerstatus_notcomplete', 'local_learningjourney');
                $progressstr = get_string('managerprogress', 'local_learningjourney', $row->progress);
                $table->data[] = new html_table_row([fullname($row->learner), $statusstr, $progressstr]);
            }

            $activityname = format_string($cm->name);
            $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', ['activity' => $activityname]));
            if (!empty($table->data)) {
                $message .= html_writer::table($table);
            }
        }

        $message = local_learningjourney_wrap_email_html_preview($subject, $message);

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
        $message = local_learningjourney_format_message_embeds(
            $message,
            $context,
            (int)($previewdata->messageitemid ?? 0),
            0
        );

        if (($previewdata->targettype ?? 'student') === 'manager') {
            $rowsbymanager = local_learningjourney_get_manager_rows_preview($course, null, $previewdata->completionfilter ?? 'all');
            $managerrows = $rowsbymanager[$USER->id] ?? [];
            if (empty($managerrows) && !empty($rowsbymanager)) {
                $managerrows = reset($rowsbymanager);
            }
            $table = new html_table();
            $table->head = [get_string('fullname'), get_string('manageractivitystatuses', 'local_learningjourney'), get_string('completion', 'completion')];
            foreach ($managerrows as $row) {
                $progressstr = get_string('managerprogress', 'local_learningjourney', $row->progress);
                $statuslist = local_learningjourney_build_activity_status_list_preview($course, $row->learner->id);
                $table->data[] = new html_table_row([fullname($row->learner), $statuslist, $progressstr]);
            }
            $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                'activity' => get_string('allactivities', 'local_learningjourney'),
            ]));
            if (!empty($table->data)) {
                $message .= html_writer::table($table);
            }
        } else {
            // Student preview for all activities: activity statuses + course progress.
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
        }

        $message = local_learningjourney_wrap_email_html_preview($subject, $message);

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
            $message = local_learningjourney_format_message_embeds($message, $context, 0, (int)$reminder->id);

            if (($reminder->targettype ?? 'student') === 'manager') {
                $rowsbymanager = local_learningjourney_get_manager_rows_preview($course, $cm, $reminder->completionfilter ?? 'all');
                $managerrows = $rowsbymanager[$USER->id] ?? [];
                if (empty($managerrows) && !empty($rowsbymanager)) {
                    $managerrows = reset($rowsbymanager);
                }

                $table = new html_table();
                $table->head = [get_string('fullname'), get_string('status'), get_string('completion', 'completion')];
                foreach ($managerrows as $row) {
                    $statusstr = $row->complete
                        ? get_string('managerstatus_complete', 'local_learningjourney')
                        : get_string('managerstatus_notcomplete', 'local_learningjourney');
                    $progressstr = get_string('managerprogress', 'local_learningjourney', $row->progress);
                    $table->data[] = new html_table_row([fullname($row->learner), $statusstr, $progressstr]);
                }
                $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                    'activity' => format_string($cm->name),
                ]));
                if (!empty($table->data)) {
                    $message .= html_writer::table($table);
                }
            }

            $message = local_learningjourney_wrap_email_html_preview($subject, $message);

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
            $message = local_learningjourney_format_message_embeds($message, $context, 0, (int)$reminder->id);

            if (($reminder->targettype ?? 'student') === 'manager') {
                $rowsbymanager = local_learningjourney_get_manager_rows_preview($course, null, $reminder->completionfilter ?? 'all');
                $managerrows = $rowsbymanager[$USER->id] ?? [];
                if (empty($managerrows) && !empty($rowsbymanager)) {
                    $managerrows = reset($rowsbymanager);
                }
                $table = new html_table();
                $table->head = [get_string('fullname'), get_string('manageractivitystatuses', 'local_learningjourney'), get_string('completion', 'completion')];
                foreach ($managerrows as $row) {
                    $progressstr = get_string('managerprogress', 'local_learningjourney', $row->progress);
                    $statuslist = local_learningjourney_build_activity_status_list_preview($course, $row->learner->id);
                    $table->data[] = new html_table_row([fullname($row->learner), $statuslist, $progressstr]);
                }
                $message .= html_writer::tag('h4', get_string('managerstatusheading', 'local_learningjourney', [
                    'activity' => get_string('allactivities', 'local_learningjourney'),
                ]));
                if (!empty($table->data)) {
                    $message .= html_writer::table($table);
                }
            } else {
                // Student preview for all activities.
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
            }

            $message = local_learningjourney_wrap_email_html_preview($subject, $message);

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

