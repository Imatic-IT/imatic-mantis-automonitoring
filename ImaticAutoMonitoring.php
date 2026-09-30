<?php

require 'core/function.php';

class ImaticAutoMonitoringPlugin extends MantisPlugin
{
    private const MOVE_ACTION = 'MOVE';

    public function register()
    {
        $this->name = 'Imatic automonitoring';
        $this->description = 'Auto monitoring when someone is @mentioned or assigned or changed status; warns when a mentioned user cannot see the issue';
        $this->version = '0.3.0';
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
            'automonitoring_when_mentioned' => true,
            # Show a warning under the note textarea while typing when an
            # @mentioned user cannot see the issue (or the private note).
            'mention_access_warning' => true,
            'automonitoring_when_commented' => true,
            'automonitoring_when_assigned' => true,
            'automonitoring_when_unassigned' => true,
            'automonitoring_when_created' => true,
            'automonitoring_when_change_status' => true,
            'atomonitoring_when_move_to_another_project' => true,
            # Service accounts (EmailReporting user, importers) that must never
            # be put on a monitor list. User ids or user names.
            'automonitoring_excluded_users' => [],
            'self_automonitoring_when_change_status' => true,
            'self_automonitoring_when_assigned' => [
                'allow' => true,
                'access_level' => 90
            ]
        ];
    }

    public function hooks(): array
    {
        return [
            'EVENT_UPDATE_BUG' => 'event_update_bug_hook',
            'EVENT_BUGNOTE_ADD' => 'event_bugnote_add_hook',
            'EVENT_BUG_ACTION' => 'event_bug_action_hook',
            'EVENT_REPORT_BUG' => 'event_bug_add_hook',
            'EVENT_BUGNOTE_ADD_FORM' => 'event_bugnote_add_form',
        ];
    }

    /**
     * Render the (hidden) warning row and load the script that checks the
     * @mentions in the note being written against the users' access.
     */
    public function event_bugnote_add_form($p_event, $p_bug_id = null)
    {
        if (!plugin_config_get('mention_access_warning') || !$p_bug_id || !mention_enabled()) {
            return;
        }

        $t_settings = htmlspecialchars(json_encode([
            'bug_id' => (int)$p_bug_id,
            'check_url' => plugin_page('check_mentions'),
            'lang' => [
                'no_access' => plugin_lang_get('mention_no_access'),
                'no_access_private' => plugin_lang_get('mention_no_access_private'),
                'unknown' => plugin_lang_get('mention_unknown'),
            ],
        ]), ENT_QUOTES);

        echo '<tr id="imatic-mention-access-row" hidden>'
            . '<td colspan="2">'
            . '<div id="imatic-mention-access-warning" class="alert alert-warning no-margin"></div>'
            . '</td>'
            . '</tr>';
        echo '<script id="imaticAutoMonitoringMentions" data-settings="' . $t_settings . '"'
            . ' src="' . plugin_file('mention_access_check.js') . '&v=' . $this->version . '"></script>';
    }


    public function event_bugnote_add_hook($p_event = null, $p_bug_id = null, $p_bugnote_id = null)
    {
        if (!$p_bug_id || !$p_bugnote_id) {
            return;
        }

        $t_user_ids = [];

        # The author of the note stays in the loop, otherwise he loses access to
        # the issue as soon as somebody else takes over as handler.
        if (plugin_config_get('automonitoring_when_commented')) {
            $t_user_ids[] = (int)bugnote_get_field($p_bugnote_id, 'reporter_id');
        }

        if (plugin_config_get('automonitoring_when_mentioned')) {
            $t_text = bugnote_get_text($p_bugnote_id);

            # Only users who can actually see the note. Being @mentioned must
            # not put somebody on the monitor list of an issue (or a private
            # note) they have no access to - core applies the same filter
            # before it mails the mentioned users (bugnote_process_mentions).
            $t_mentioned_user_ids = imatic_mention_filter_users_with_access(
                array_values(imatic_mention_get_users($t_text)),
                $p_bugnote_id
            );

            foreach ($t_mentioned_user_ids as $t_mentioned_user_id) {
                $t_user_ids[] = (int)$t_mentioned_user_id;
            }
        }

        imatic_add_monitoring_users($t_user_ids, $p_bug_id);

        return true;
    }


    public function event_update_bug_hook($p_event = null, $p_existing_bug = null, $p_updated_bug = null)
    {
        if (!$p_updated_bug instanceof BugData) {
            return;
        }

        $this->imatic_automonitoring_when_assign($p_existing_bug, $p_updated_bug);
        $this->imatic_automonitoring_when_change_status($p_updated_bug);
    }

    /**
     * Keep everybody involved in a handler change on the monitor list.
     *
     * Driven by the bug data carried by EVENT_UPDATE_BUG instead of $_POST, so
     * it works for every path into bug_update.php (inline "Assign to", the full
     * update form and the change status page) rather than only for the inline
     * dropdown that posts action_type=assign.
     */
    private function imatic_automonitoring_when_assign($p_existing_bug, BugData $p_updated_bug)
    {
        $t_old_handler_id = $p_existing_bug instanceof BugData ? (int)$p_existing_bug->handler_id : 0;
        $t_new_handler_id = (int)$p_updated_bug->handler_id;

        if ($t_old_handler_id === $t_new_handler_id) {
            return;
        }

        $t_user_ids = [];

        if (plugin_config_get('automonitoring_when_assigned')) {
            $t_user_ids[] = $t_new_handler_id;

            $t_self_automonitoring = plugin_config_get('self_automonitoring_when_assigned');

            if (!empty($t_self_automonitoring['allow'])) {
                $t_current_user_id = auth_get_current_user_id();
                $t_threshold = $this->self_automonitoring_threshold($t_self_automonitoring);

                if (access_get_global_level($t_current_user_id) >= $t_threshold) {
                    $t_user_ids[] = $t_current_user_id;
                }
            }
        }

        # The previous handler loses the access he had through being the handler,
        # so hand him monitoring instead of dropping him off the issue.
        if (plugin_config_get('automonitoring_when_unassigned')) {
            $t_user_ids[] = $t_old_handler_id;
        }

        imatic_add_monitoring_users($t_user_ids, $p_updated_bug->id);
    }

    /**
     * The config key used to be misspelled as 'access_lever', which made the
     * lookup return null and the threshold check pass for everyone. Read the
     * correct key but keep honouring the old one for installations that already
     * stored it in the plugin config table.
     */
    private function self_automonitoring_threshold(array $p_config): int
    {
        if (isset($p_config['access_level'])) {
            return (int)$p_config['access_level'];
        }

        if (isset($p_config['access_lever'])) {
            return (int)$p_config['access_lever'];
        }

        return NOBODY;
    }

    private function imatic_automonitoring_when_change_status(BugData $p_updated_bug)
    {
        if (!plugin_config_get('automonitoring_when_change_status')) {
            return;
        }

        if ((int)$p_updated_bug->status >= RESOLVED) {
            return;
        }

        $t_user_ids = [];

        if (plugin_config_get('self_automonitoring_when_change_status')) {
            $t_user_ids[] = auth_get_current_user_id();
        }

        $t_user_ids[] = (int)$p_updated_bug->handler_id;

        imatic_add_monitoring_users($t_user_ids, $p_updated_bug->id);
    }

    /*
     *  This add automonitoring to user when he move the issue to another project
     *  It is prevent to add monitoring to user who is not in the project ( if user move the issue to another project)
     *  It is prevent before lost access to the issue (if user move the issue to another project, where he does not have access)
     */
    public function event_bug_action_hook()
    {
        if (!plugin_config_get('atomonitoring_when_move_to_another_project')) {
            return;
        }

        $this->imatic_add_monitoring_when_move_to_another_project();

        return $_POST;
    }
    private function imatic_add_monitoring_when_move_to_another_project(): void
    {
        if (isset($_POST['action']) && !empty($_POST['action'])) {
            $action = $_POST['action'];

            if ($action == self::MOVE_ACTION) {
                $current_user_username = user_get_name(auth_get_current_user_id());

                $bugIds = $_POST['bug_arr'];

                foreach ($bugIds as $bugId) {

                    if ($current_user_username && $bugId) {
                        imatic_add_monitoring($current_user_username, $bugId);
                    }
                }
            }
        }
    }

    public function event_bug_add_hook($p_event, BugData $p_bug, $p_bug_id)
    {
        if (!plugin_config_get('automonitoring_when_created')) {
            return;
        }

        imatic_add_monitoring_users([$p_bug->reporter_id], $p_bug_id);
    }
}