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
 * Extend course settings navigation to add Learning Journey link.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
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

