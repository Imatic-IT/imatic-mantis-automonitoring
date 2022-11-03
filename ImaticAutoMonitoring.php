<?php

require 'core/function.php';

class ImaticAutoMonitoringPlugin extends MantisPlugin
{

    public function register()
    {
        $this->name = 'Imatic automonitoring';
        $this->description = 'Auto monitoring when someone is @mentioned or assigned or changed status ';
        $this->version = '0.0.1';
        $this->requires = [
            'MantisCore' => '2.0.0',
        ];

        $this->author = 'Imatic Software s.r.o.';
        $this->contact = 'info@imatic.cz';
        $this->url = 'https://www.imatic.cz/';
    }

    public function config(): array
    {
        return [
            'allow_automonitoring_when_mentioned' => true,
            'allow_automonitoring_when_assigned' => true
        ];
    }

    public function hooks(): array
    {
        return [
            'EVENT_BUGNOTE_ADD' => 'event_bugnote_add_hook',
            'EVENT_UPDATE_BUG' => 'event_update_bug_hook'
        ];
    }


    public function event_bugnote_add_hook()
    {
        if (!plugin_config_get('allow_automonitoring_when_mentioned')) {
            return;
        }

        if (!empty($_POST)) {

            $text = $_POST['bugnote_text'];

            if (!$text) {
                return;
            }

            $bug_id = $_POST['bug_id'];
            $bug = bug_get_row($bug_id);
            $p_project_id = $bug['project_id'];

            #Get usernames from text (Mantis method mention.api.php)
            $f_usernames = mention_get_users($text);

            if (!empty($f_usernames)) {

                foreach ($f_usernames as $key => $user_id) {

                    $accessible_projects = user_get_accessible_projects($user_id);

                    if (!in_array($p_project_id, $accessible_projects)) {
                        return;
                    }

                    $f_usernames = $key; # RENAME ID TO KEY (KEY IS USERNAMES

                    imatic_add_monitoring($f_usernames, $bug_id);

                }
            }
        }
    }


    public function event_update_bug_hook()
    {

        if (!plugin_config_get('allow_automonitoring_when_assigned')) {
            return;
        }

        if ($_POST && $_POST['action_type'] == 'assign') {

            $t_username = user_get_name($_POST['handler_id']);
            $bug_id = $_POST['bug_id'];

            imatic_add_monitoring($t_username, $bug_id);
        }
    }
}