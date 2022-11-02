<?php

class ImaticAutoMonitoringPlugin extends MantisPlugin
{

    public function register()
    {
        $this->name = 'Imatic automonitoring';
        $this->description = 'Auto monitoring when someone is @mentioned ';
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
            'allow_automonitoring_when_mentioned' => true
        ];
    }

    public function hooks(): array
    {
        return [
            'EVENT_BUGNOTE_ADD' => 'event_bugnote_add'
        ];
    }


    public function event_bugnote_add()
    {
        #If is allowed auto monitoring
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
                $t_payload = array();

                foreach ($f_usernames as $key => $user_id) {

                    $accesible_projects = user_get_accessible_projects($user_id);

                    if (!in_array($p_project_id, $accesible_projects)) {
                        return;
                    }

                    $f_usernames = $key; /* RENAME ID TO KEY (KEY IS USERNAMES*/

                    # MANTIS METHODS FROM bug_monitor_add.php
                    if (!is_blank($f_usernames)) {
                        $t_usernames = preg_split('/[,|]/', $f_usernames, -1, PREG_SPLIT_NO_EMPTY);
                        $t_users = array();
                        foreach ($t_usernames as $t_username) {
                            $t_users[] = array('name_or_realname' => trim($t_username));
                        }
                        $t_payload['users'] = $t_users;
                    }
                    $t_data = array(
                        'query' => array('issue_id' => $bug_id),
                        'payload' => $t_payload,
                    );

                    $t_command = new MonitorAddCommand($t_data);
                    $t_command->execute();
                    # END MANTIS METHODS FROM bug_monitor_add.php
                }
            }
        }
    }

}