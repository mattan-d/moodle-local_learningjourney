<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_learningjourney.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_learningjourney_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // Ensure 'sent' and 'senttime' fields exist (in case site was installed before they were added).
    if ($oldversion < 2026031601) {
        $table = new xmldb_table('local_learningjourney');

        $fieldsent = new xmldb_field('sent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'enabled');
        if (!$dbman->field_exists($table, $fieldsent)) {
            $dbman->add_field($table, $fieldsent);
        }

        $fieldsenttime = new xmldb_field('senttime', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sent');
        if (!$dbman->field_exists($table, $fieldsenttime)) {
            $dbman->add_field($table, $fieldsenttime);
        }

        upgrade_plugin_savepoint(true, 2026031601, 'local', 'learningjourney');
    }

    // Add manager-related fields.
    if ($oldversion < 2026031602) {
        $table = new xmldb_table('local_learningjourney');

        $fieldsendmanagers = new xmldb_field('sendmanagers', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'senttime');
        if (!$dbman->field_exists($table, $fieldsendmanagers)) {
            $dbman->add_field($table, $fieldsendmanagers);
        }

        $fieldmansubject = new xmldb_field('managersubject', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'sendmanagers');
        if (!$dbman->field_exists($table, $fieldmansubject)) {
            $dbman->add_field($table, $fieldmansubject);
        }

        $fieldmanmsg = new xmldb_field('managermessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'managersubject');
        if (!$dbman->field_exists($table, $fieldmanmsg)) {
            $dbman->add_field($table, $fieldmanmsg);
        }

        upgrade_plugin_savepoint(true, 2026031602, 'local', 'learningjourney');
    }

    return true;
}

