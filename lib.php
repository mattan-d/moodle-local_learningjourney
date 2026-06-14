<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Serves files embedded in reminder message HTML (editor images).
 *
 * @param stdClass $course Course record (from file context).
 * @param stdClass $cm Always null for this plugin (course-level context).
 * @param context $context Course context.
 * @param string $filearea Only "message" is supported.
 * @param array $args Remaining path: reminder id, optional subdirs, filename.
 * @param bool $forcedownload Download vs inline.
 * @param array $options Extra options for send_stored_file.
 * @return bool False if access denied or file missing.
 *
 * Embedded images use plain pluginfile.php URLs. Valid image files under a real reminder may be served
 * without a Moodle login so email clients and external viewers can load them. Non-image files still
 * require login plus manage capability or course enrolment.
 *
 * @copyright 2026 CentricApp LTD. dev@centricapp.co.il
 */
function local_learningjourney_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB;

    if ($filearea !== 'message') {
        return false;
    }

    if ($context->contextlevel != CONTEXT_COURSE) {
        return false;
    }

    $reminderid = (int)array_shift($args);
    if ($reminderid < 1) {
        return false;
    }

    $reminder = $DB->get_record('local_learningjourney', [
        'id' => $reminderid,
        'courseid' => $course->id,
    ], 'id', IGNORE_MISSING);
    if (!$reminder) {
        return false;
    }

    $filename = array_pop($args);
    if ($filename === null || $filename === '') {
        return false;
    }
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_learningjourney', 'message', $reminderid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    $publicimage = $file->is_valid_image();

    if (!$publicimage) {
        require_login($course);
        $canmanage = has_capability('local/learningjourney:managereminders', $context);
        $enrolled = is_enrolled($context, null, '', true);
        if (!$canmanage && !$enrolled) {
            return false;
        }
    }

    \core\session\manager::write_close();
    send_stored_file($file, 60 * 60, 0, $forcedownload, $options);
}

/**
 * Resolve direct managers for a set of learners via custom profile field "manager" (username).
 *
 * @param array $users Enrolled learner user records keyed by id.
 * @return array userid => manager user record
 */
function local_learningjourney_resolve_managers_by_learner(array $users): array {
    global $DB;

    $managersbyuser = [];
    if (empty($users)) {
        return $managersbyuser;
    }

    $managerfield = $DB->get_record('user_info_field', ['shortname' => 'manager'], '*', IGNORE_MISSING);
    if (!$managerfield) {
        return $managersbyuser;
    }

    $userids = array_map(static function($user) {
        return $user->id;
    }, $users);

    list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $params = $inparams + ['fieldid' => $managerfield->id];
    $records = $DB->get_records_select('user_info_data', "fieldid = :fieldid AND userid {$insql}", $params);

    $managercache = [];
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

    return $managersbyuser;
}

/**
 * Get the direct manager user record for a user (via profile field "manager" username).
 *
 * @param int $userid
 * @return \stdClass|null
 */
function local_learningjourney_get_user_direct_manager(int $userid): ?\stdClass {
    global $DB;

    $managerfield = $DB->get_record('user_info_field', ['shortname' => 'manager'], '*', IGNORE_MISSING);
    if (!$managerfield) {
        return null;
    }

    $record = $DB->get_record('user_info_data', [
        'fieldid' => $managerfield->id,
        'userid' => $userid,
    ], 'data', IGNORE_MISSING);

    if (!$record) {
        return null;
    }

    $managerusername = trim((string)$record->data);
    if ($managerusername === '') {
        return null;
    }

    return $DB->get_record('user', [
        'username' => $managerusername,
        'deleted' => 0,
    ], '*', IGNORE_MISSING) ?: null;
}

/**
 * Build a map of manager username => enrolled direct reports in a course.
 *
 * @param array $enrolledusers Enrolled user records keyed by id.
 * @return array manager username => array of user records
 */
function local_learningjourney_build_direct_reports_by_manager_username(array $enrolledusers): array {
    global $DB;

    if (empty($enrolledusers)) {
        return [];
    }

    $managerfield = $DB->get_record('user_info_field', ['shortname' => 'manager'], '*', IGNORE_MISSING);
    if (!$managerfield) {
        return [];
    }

    $userbyid = [];
    foreach ($enrolledusers as $user) {
        $userbyid[$user->id] = $user;
    }

    $userids = array_keys($userbyid);
    list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $params = $inparams + ['fieldid' => $managerfield->id];
    $records = $DB->get_records_select('user_info_data', "fieldid = :fieldid AND userid {$insql}", $params);

    $byusername = [];
    foreach ($records as $rec) {
        $managerusername = trim((string)$rec->data);
        if ($managerusername === '' || !isset($userbyid[$rec->userid])) {
            continue;
        }
        $byusername[$managerusername][] = $userbyid[$rec->userid];
    }

    return $byusername;
}

/**
 * Build an HTML table of direct employee names for email placeholders.
 *
 * @param array $employees User records.
 * @return string
 */
function local_learningjourney_build_direct_employees_table_html(array $employees): string {
    if (empty($employees)) {
        return '';
    }

    $table = new html_table();
    $table->head = [get_string('fullname')];

    foreach ($employees as $employee) {
        $table->data[] = new html_table_row([fullname($employee)]);
    }

    return html_writer::table($table);
}

/**
 * Placeholder values for {directmanager} and {directemployees}.
 *
 * @param \stdClass $recipient Email recipient.
 * @param \stdClass $course
 * @param \context_course $context
 * @param array|null $directreportsbyusername Optional pre-built map from local_learningjourney_build_direct_reports_by_manager_username().
 * @return array<string, string>
 */
function local_learningjourney_get_direct_manager_replacements(
    \stdClass $recipient,
    \stdClass $course,
    \context_course $context,
    ?array $directreportsbyusername = null
): array {
    $manager = local_learningjourney_get_user_direct_manager((int)$recipient->id);
    $directmanager = $manager ? fullname($manager) : '';

    if ($directreportsbyusername === null) {
        $enrolled = get_enrolled_users($context, '', 0, 'u.*');
        $directreportsbyusername = local_learningjourney_build_direct_reports_by_manager_username($enrolled);
    }

    $managerusername = trim((string)($recipient->username ?? ''));
    $employees = ($managerusername !== '') ? ($directreportsbyusername[$managerusername] ?? []) : [];
    $directemployees = local_learningjourney_build_direct_employees_table_html($employees);

    return [
        '{directmanager}' => $directmanager,
        '{directemployees}' => $directemployees,
        '{{directmanager}}' => $directmanager,
        '{{directemployees}}' => $directemployees,
    ];
}

/**
 * Get unique direct managers of enrolled learners who are not enrolled in the course.
 *
 * @param \stdClass $course
 * @param \context_course $context
 * @param array $users Enrolled learner user records keyed by id.
 * @return array managerid => manager user record
 */
function local_learningjourney_get_external_managers(\stdClass $course, \context_course $context, array $users): array {
    $managersbyuser = local_learningjourney_resolve_managers_by_learner($users);
    if (empty($managersbyuser)) {
        return [];
    }

    $enrolledids = [];
    foreach ($users as $user) {
        $enrolledids[$user->id] = true;
    }

    $external = [];
    foreach ($managersbyuser as $manager) {
        if (isset($enrolledids[$manager->id])) {
            continue;
        }
        $external[$manager->id] = $manager;
    }

    return $external;
}

/**
 * Get external managers for all learners enrolled in a course.
 *
 * @param \stdClass $course
 * @param \context_course $context
 * @return array managerid => manager user record
 */
function local_learningjourney_get_external_managers_for_course(\stdClass $course, \context_course $context): array {
    $users = get_enrolled_users($context, '', 0, 'u.*');
    return local_learningjourney_get_external_managers($course, $context, $users);
}

/**
 * Human-readable label for reminder target type.
 *
 * @param string $targettype
 * @return string
 */
function local_learningjourney_get_targettype_label(string $targettype): string {
    switch ($targettype) {
        case 'manager':
            return get_string('target_manager', 'local_learningjourney');
        case 'manager_external':
            return get_string('target_manager_external', 'local_learningjourney');
        default:
            return get_string('target_student', 'local_learningjourney');
    }
}

/**
 * Build HTML list of external managers who will receive the reminder.
 *
 * @param \stdClass $course
 * @param \context_course $context
 * @return string
 */
function local_learningjourney_render_external_managers_recipients_preview(\stdClass $course, \context_course $context): string {
    $managers = local_learningjourney_get_external_managers_for_course($course, $context);

    if (empty($managers)) {
        return html_writer::div(
            get_string('preview_norecipients_external', 'local_learningjourney'),
            'alert alert-warning mb-3'
        );
    }

    $items = [];
    foreach ($managers as $manager) {
        $label = fullname($manager);
        if (!empty($manager->email)) {
            $label .= ' (' . s($manager->email) . ')';
        }
        $items[] = html_writer::tag('li', $label);
    }

    return html_writer::tag('h4', get_string('previewrecipientsheading', 'local_learningjourney')) .
        html_writer::tag('ul', implode('', $items), ['class' => 'mb-3']);
}

/**
 * Normalize activity ids submitted from the reminder form.
 *
 * @param mixed $cmids Raw form value (array, scalar, or null).
 * @return int[] Unique activity ids; empty array means no activity selected.
 */
function local_learningjourney_normalize_cmids_input($cmids): array {
    if (!isset($cmids)) {
        return [];
    }

    if (is_array($cmids)) {
        $normalized = array_map('intval', $cmids);
    } else if ($cmids !== null && $cmids !== '') {
        $normalized = [(int)$cmids];
    } else {
        return [];
    }

    $normalized = array_values(array_unique($normalized));
    if (in_array(0, $normalized, true)) {
        return [0];
    }

    return $normalized;
}

/**
 * Parse stored cmids JSON (and legacy cmid) into an int array.
 *
 * @param \stdClass $reminder Reminder database record.
 * @return int[]
 */
function local_learningjourney_parse_cmids(\stdClass $reminder): array {
    if (isset($reminder->cmids) && $reminder->cmids !== null && $reminder->cmids !== '') {
        $decoded = json_decode($reminder->cmids, true);
        if (is_array($decoded)) {
            return local_learningjourney_normalize_cmids_input($decoded);
        }
    }

    if (!empty($reminder->cmid)) {
        return [(int)$reminder->cmid];
    }

    // Legacy records without cmids: cmid 0 means all activities.
    return [0];
}

/**
 * Activity selection mode for a reminder.
 *
 * @param \stdClass $reminder Reminder database record.
 * @return string One of: none, all, specific.
 */
function local_learningjourney_get_activity_mode(\stdClass $reminder): string {
    if (isset($reminder->cmids) && $reminder->cmids !== null && $reminder->cmids !== '') {
        $decoded = json_decode($reminder->cmids, true);
        if (is_array($decoded)) {
            if (empty($decoded)) {
                return 'none';
            }
            $cmids = local_learningjourney_normalize_cmids_input($decoded);
            if (empty($cmids)) {
                return 'none';
            }
            if (in_array(0, $cmids, true)) {
                return 'all';
            }
            return 'specific';
        }
    }

    if (!empty($reminder->cmid)) {
        return 'specific';
    }

    return 'all';
}

/**
 * Human-readable label for the activity column in the reminders list.
 *
 * @param \stdClass $reminder Reminder database record.
 * @param array $modoptions cmid => activity name map.
 * @return string
 */
function local_learningjourney_format_reminder_activity_label(\stdClass $reminder, array $modoptions): string {
    $mode = local_learningjourney_get_activity_mode($reminder);
    if ($mode === 'none') {
        return get_string('activity_none', 'local_learningjourney');
    }
    if ($mode === 'all') {
        return get_string('activity_all', 'local_learningjourney');
    }

    $cmids = local_learningjourney_parse_cmids($reminder);
    $labels = [];
    foreach ($cmids as $cmid) {
        if (isset($modoptions[$cmid])) {
            $labels[] = $modoptions[$cmid];
        }
    }

    if (!empty($labels)) {
        return implode(', ', $labels);
    }

    return (string)($cmids[0] ?? '');
}

function local_learningjourney_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    if (!$context instanceof context_course) {
        return;
    }

    if (!has_capability('local/learningjourney:managereminders', $context)) {
        return;
    }

    if ($coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
        $url = new moodle_url('/local/learningjourney/course.php', ['id' => $context->instanceid]);
        $coursenode->add(
            get_string('pluginname', 'local_learningjourney'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'local_learningjourney',
            new pix_icon('i/email', '')
        );
    }
}

