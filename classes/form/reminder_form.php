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

        $mform->addElement('header', 'general', get_string('remindersettings', 'local_learningjourney'));

        $mform->addElement('select', 'cmid', get_string('activity', 'local_learningjourney'), $modoptions);
        $mform->addRule('cmid', null, 'required', null, 'client');

        $mform->addElement('date_time_selector', 'timetosend', get_string('timetosend', 'local_learningjourney'));
        $mform->addRule('timetosend', null, 'required', null, 'client');

        $options = [
            'completed' => get_string('filter_completed', 'local_learningjourney'),
            'notcompleted' => get_string('filter_notcompleted', 'local_learningjourney'),
            'all' => get_string('filter_all', 'local_learningjourney'),
        ];
        $mform->addElement('select', 'completionfilter', get_string('completionfilter', 'local_learningjourney'), $options);
        $mform->setDefault('completionfilter', 'all');

        $mform->addElement('text', 'subject', get_string('subject', 'local_learningjourney'), ['size' => 64]);
        $mform->setType('subject', PARAM_TEXT);

        $editoroptions = [
            'maxfiles' => 0,
            'trusttext' => false,
        ];
        $mform->addElement('editor', 'message_editor', get_string('message', 'local_learningjourney'), null, $editoroptions);

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'local_learningjourney'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function set_data($defaultvalues) {
        if (!empty($defaultvalues->message)) {
            $defaultvalues->message_editor = [
                'text' => $defaultvalues->message,
                'format' => FORMAT_HTML,
            ];
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

