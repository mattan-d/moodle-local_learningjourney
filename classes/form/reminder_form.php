<?php

namespace local_learningjourney\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use moodleform;

class reminder_form extends moodleform {

    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata ?? [];

        $modoptions = $customdata['modoptions'] ?? [];
        $courseid = $customdata['courseid'] ?? 0;

        // Keep course id in the form so it is always posted back.
        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);

        // Optional: existing reminder id when editing.
        $mform->addElement('hidden', 'reminderid', 0);
        $mform->setType('reminderid', PARAM_INT);

        $mform->addElement('header', 'general', get_string('remindersettings', 'local_learningjourney'));

        // Add "all activities in course" as default option.
        $activityoptions = [0 => get_string('activity_all', 'local_learningjourney')] + $modoptions;

        $mform->addElement('select', 'cmid', get_string('activity', 'local_learningjourney'), $activityoptions);
        $mform->addRule('cmid', null, 'required', null, 'client');
        $mform->setDefault('cmid', 0);

        $mform->addElement('date_time_selector', 'timetosend', get_string('timetosend', 'local_learningjourney'));
        $mform->addRule('timetosend', null, 'required', null, 'client');

        $options = [
            'completed' => get_string('filter_completed', 'local_learningjourney'),
            'notcompleted' => get_string('filter_notcompleted', 'local_learningjourney'),
            'all' => get_string('filter_all', 'local_learningjourney'),
            'oncomplete' => get_string('filter_oncomplete', 'local_learningjourney'),
        ];
        $mform->addElement('select', 'completionfilter', get_string('completionfilter', 'local_learningjourney'), $options);
        $mform->setDefault('completionfilter', 'all');

        // Reminder type (student / manager) under the same "Reminder settings" header.
        $typoptions = [
            'student' => get_string('target_student', 'local_learningjourney'),
            'manager' => get_string('target_manager', 'local_learningjourney'),
        ];
        $mform->addElement('select', 'targettype', get_string('targettype', 'local_learningjourney'), $typoptions);
        $mform->setDefault('targettype', 'student');

        $mform->addElement('text', 'subject', get_string('subject', 'local_learningjourney'), ['size' => 64]);
        $mform->setType('subject', PARAM_TEXT);

        // Help: show available placeholders.
        $mform->addElement('static', 'placeholders', get_string('placeholdersheading', 'local_learningjourney'),
            get_string('placeholdershelp', 'local_learningjourney'));

        $editoroptions = [
            'maxfiles' => 0,
            'trusttext' => false,
        ];
        // כל סוגי התזכורות (סטודנט/מנהל) משתמשים באותו שדה הודעה אישית.
        $mform->addElement('editor', 'message_editor', get_string('message', 'local_learningjourney'), null, $editoroptions);
        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_learningjourney'));
        $mform->setDefault('enabled', 1);

        // Buttons: save + preview.
        $buttonarray = [];
        $buttonarray[] =& $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        $previewurl = new \moodle_url('/local/learningjourney/course.php', [
            'id' => $courseid,
            'previewpopup' => 1,
        ]);
        $buttonarray[] =& $mform->createElement('submit', 'preview', get_string('preview', 'local_learningjourney'), [
            // Always open preview in a new tab/window.
            'formtarget' => '_blank',
            'formaction' => $previewurl->out(false),
            'rel' => 'noopener',
        ]);
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }

    public function set_data($defaultvalues) {
        if (!empty($defaultvalues->message)) {
            $defaultvalues->message_editor = [
                'text' => $defaultvalues->message,
                'format' => FORMAT_HTML,
            ];
        }
        // If editing an existing reminder, pass its id into reminderid field.
        if (!empty($defaultvalues->reminderid)) {
            $defaultvalues->reminderid = (int)$defaultvalues->reminderid;
        }
        parent::set_data($defaultvalues);
    }

    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return null;
        }

        if (isset($data->message_editor)) {
            $data->message = $data->message_editor['text'];
            unset($data->message_editor);
        }

        return $data;
    }
}

