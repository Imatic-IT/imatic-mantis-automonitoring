<?php
/**
 * JSON endpoint: which @mentions in the note being written point to users
 * who cannot see the issue (or the private note), and which point to no user.
 *
 * POST body (JSON): { "bug_id": int, "text": string, "private": bool }
 * Response:         { "no_access": [username, ...], "unknown": [candidate, ...] }
 */

require_api('authentication_api.php');
require_api('access_api.php');
require_api('bug_api.php');
require_api('config_api.php');

header('Content-Type: application/json');

auth_ensure_user_authenticated();

if (!plugin_config_get('mention_access_warning')) {
    http_response_code(404);
    echo json_encode(['error' => 'Mention access warning is disabled']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed']);
    exit;
}

$t_data = json_decode(file_get_contents('php://input'), true);
$t_bug_id = isset($t_data['bug_id']) ? (int)$t_data['bug_id'] : 0;
$t_text = isset($t_data['text']) ? (string)$t_data['text'] : '';
$t_private = !empty($t_data['private']);

if ($t_bug_id <= 0 || !bug_exists($t_bug_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid bug_id']);
    exit;
}

# Only somebody who can see the issue may ask who else can.
access_ensure_bug_level(config_get('view_bug_threshold'), $t_bug_id);

echo json_encode(imatic_mention_check_access($t_bug_id, $t_text, $t_private));
