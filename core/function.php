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