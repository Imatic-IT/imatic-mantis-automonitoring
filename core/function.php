<?php


# MANTIS METHODS FROM bug_monitor_add.php
function imatic_add_monitoring($f_usernames, $bug_id)
{

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