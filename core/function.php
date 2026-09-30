<?php


# MANTIS METHODS FROM bug_monitor_add.php
function imatic_add_monitoring($f_usernames, $bug_id)
{
    if (is_blank($f_usernames)) {
        return;
    }

    $t_usernames = preg_split('/[,|]/', $f_usernames, -1, PREG_SPLIT_NO_EMPTY);

    $t_user_ids = array();
    foreach ($t_usernames as $t_username) {
        $t_user_ids[] = user_get_id_by_name(trim($t_username));
    }

    imatic_add_monitoring_users($t_user_ids, $bug_id);
}

/**
 * Add monitoring for the given user ids.
 *
 * Each user is added separately and failures are swallowed: MonitorAddCommand
 * throws when the target user has no access to the project or when the acting
 * user may not add monitors for others. Automonitoring is a convenience, it
 * must never abort the bug update that triggered it.
 *
 * @param array $p_user_ids
 * @param int   $p_bug_id
 * @return void
 */
function imatic_add_monitoring_users(array $p_user_ids, $p_bug_id)
{
    if (!$p_bug_id) {
        return;
    }

    $t_user_ids = array_unique(array_filter(array_map('intval', $p_user_ids)));

    foreach ($t_user_ids as $t_user_id) {
        if (!user_exists($t_user_id)) {
            continue;
        }

        if (imatic_is_excluded_from_monitoring($t_user_id)) {
            continue;
        }

        $t_data = array(
            'query' => array('issue_id' => $p_bug_id),
            'payload' => array('users' => array(array('id' => $t_user_id))),
        );

        try {
            $t_command = new MonitorAddCommand($t_data);
            $t_command->execute();
        } catch (Exception $e) {
            continue;
        }
    }
}

/**
 * Whether the user is excluded from automonitoring.
 *
 * Service accounts such as the EmailReporting user report issues and add notes
 * without being members of the project, so monitoring them is both pointless
 * and rejected by MonitorAddCommand. Configure them once instead of granting
 * the account access to every project.
 *
 * Accepts user ids and user names, so the option can be filled in from the
 * Configuration Report screen with whatever is at hand.
 *
 * @param int $p_user_id
 * @return bool
 */
function imatic_is_excluded_from_monitoring($p_user_id)
{
    static $s_excluded_ids = null;

    if ($s_excluded_ids === null) {
        $s_excluded_ids = array();

        foreach ((array)plugin_config_get('automonitoring_excluded_users') as $t_excluded) {
            if (is_numeric($t_excluded)) {
                $s_excluded_ids[] = (int)$t_excluded;
                continue;
            }

            $t_excluded_id = user_get_id_by_name(trim($t_excluded));
            if ($t_excluded_id) {
                $s_excluded_ids[] = (int)$t_excluded_id;
            }
        }
    }

    return in_array((int)$p_user_id, $s_excluded_ids, true);
}

/**
 * Keep only the mentioned users who can see the given note.
 *
 * Same check core uses to decide who gets the mention e-mail: view_bug_threshold
 * on the issue, raised to private_bugnote_threshold for a private note.
 *
 * @param array $p_user_ids
 * @param int   $p_bugnote_id
 * @return array user ids with access
 */
function imatic_mention_filter_users_with_access(array $p_user_ids, $p_bugnote_id)
{
    if (empty($p_user_ids)) {
        return array();
    }

    return access_has_bugnote_level_filter(
        config_get('view_bug_threshold'),
        $p_bugnote_id,
        $p_user_ids
    );
}

/**
 * Check the @mentions in a note that is still being written.
 *
 * The note does not exist yet, so the private-note rule of
 * access_has_bugnote_level() is replicated here: for a private note the
 * threshold is raised to private_bugnote_threshold of the issue's project.
 *
 * @param int    $p_bug_id
 * @param string $p_text    Note text as typed so far.
 * @param bool   $p_private Whether the note will be private.
 * @return array ['no_access' => [username, ...], 'unknown' => [candidate, ...]]
 */
function imatic_mention_check_access($p_bug_id, $p_text, $p_private)
{
    $t_result = array('no_access' => array(), 'unknown' => array());

    if (!mention_enabled() || is_blank($p_text)) {
        return $t_result;
    }

    $t_candidates = imatic_mention_get_candidates($p_text);
    if (empty($t_candidates)) {
        return $t_result;
    }

    $t_users = imatic_mention_get_users($p_text); # username => id
    $t_project_id = bug_get_field($p_bug_id, 'project_id');
    $t_view_threshold = config_get('view_bug_threshold', null, null, $t_project_id);

    foreach ($t_candidates as $t_candidate) {
        if (!isset($t_users[$t_candidate])) {
            # The candidate regex only knows ASCII word characters, so it cuts
            # "@všem" down to "v". Do not report such fragments as typos.
            if (!preg_match('/' . preg_quote(mentions_tag() . $t_candidate, '/') . '\p{L}/u', $p_text)) {
                $t_result['unknown'][] = $t_candidate;
            }
            continue;
        }

        $t_user_id = (int)$t_users[$t_candidate];
        $t_threshold = $t_view_threshold;

        if ($p_private) {
            $t_private_threshold = config_get('private_bugnote_threshold', null, $t_user_id, $t_project_id);
            $t_threshold = max($t_threshold, $t_private_threshold);
        }

        if (!access_has_bug_level($t_threshold, $p_bug_id, $t_user_id)) {
            $t_result['no_access'][] = $t_candidate;
        }
    }

    return $t_result;
}

/**
 * Given a string find the @ mentioned users.  The return list is a valid
 * list of valid mentioned users.  The list will be empty if the mentions
 * feature is disabled.
 *
 * @param string $p_text The text to process.
 * @return array with valid usernames as keys and their ids as values.
 */
function imatic_mention_get_users( $p_text ) {
    if ( !mention_enabled() ) {
        return array();
    }

    $t_matches = imatic_mention_get_candidates( $p_text );
    if( empty( $t_matches )) {
        return array();
    }

    $t_mentioned_users = array();

    foreach( $t_matches as $t_candidate ) {
        if( $t_user_id = user_get_id_by_name( $t_candidate ) ) {
            if( false === $t_user_id ) {
                continue;
            }

            $t_mentioned_users[$t_candidate] = $t_user_id;
        }
    }

    return $t_mentioned_users;
}

/**
 * Imatic update: this also get user name which is an email adress
 * A method that takes in a text argument and extracts all candidate @ mentions
 * from it.  The return list will not include the @ sign and will not include
 * duplicates.  This method is mainly for testability and it doesn't take into
 * consideration whether the @ mentions features is enabled or not.
 *
 * @param string $p_text The text to process.
 * @return array of @ mentions without the @ sign.
 * @private
 */
function imatic_mention_get_candidates( $p_text ) {
    if( is_blank( $p_text ) ) {
        return array();
    }

    static $s_pattern = null;
    if( $s_pattern === null ) {
        $t_quoted_tag = preg_quote( mentions_tag() );
        $s_pattern = '/(?:'
            # Negative lookbehind to ensure we have whitespace or start of
            # string before the tag - ensures we don't match a tag in the
            # middle of a word (e.g. e-mail address)
            . '(?<=^|[^\w@.])'
            # Negative lookbehind to ensure we don't match multiple tags
            . '(?<!' . $t_quoted_tag . ')' . $t_quoted_tag
            . ')'
            # any word char, dash or period, must end with word char
            . '([\w\-.@]*[\w])'
            # Lookforward to ensure next char is not a valid mention char or
            # the end of the string, or the mention tag
            . '(?=[^\w@]|$)'
            . '(?!$t_quoted_tag)'
            . '/';
    }
//    pre_r($s_pattern);

    preg_match_all( $s_pattern, $p_text, $t_mentions );

    return array_unique( $t_mentions[1] );
}