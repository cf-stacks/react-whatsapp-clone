<?php
/*
 * ==========================================================
 * Gupshup WhatsApp message receiver
 * ==========================================================
 */

$raw = file_get_contents('php://input');
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
        require('../../include/functions.php');
        if (empty(sb_get_multi_setting('whatsapp-gupshup', 'whatsapp-gupshup-active'))) {
            die();
        }

        // $date = (new DateTime('NOW'))->format("y:m:d h:i:s");
        // if ($handle = fopen('gupshup.log', 'a')) {
        //     fwrite($handle, $date . ' REQ --> ' . json_encode($raw) . PHP_EOL);
        // }
        // fclose($handle);
    
        $response = json_decode($raw, true);
    
        if (empty($response['payload'])) {
            die();
        }
        sb_cloud_load_by_url();
    
        $GLOBALS['SB_FORCE_ADMIN'] = true;

        $user_id         = false;
        $conversation_id = false;
        $phone           = '+' . $response['payload']['sender']['phone'];

        $user            = sb_get_user_by('phone', $phone);
        $department      = sb_get_setting('whatsapp-department');
        $payload         = '';
        $message         = $response['payload']['type'] == 'text' ? $response['payload']['payload']['text'] : '';

        // if (filter_var($message, FILTER_VALIDATE_URL) !== FALSE) {
        //     $message = '<a href="' . $message . '" target="_blank">' . $message . '</a>';
        // }
    
        // User and conversation
        if (!$user) {
            $name          = trim($response['payload']['sender']['name']);
            $space_in_name = strpos($name, ' ');
            $first_name    = $space_in_name ? trim(substr($name, 0, $space_in_name)) : $name . $space_in_name;
            $last_name     = $space_in_name ? trim(substr($name, $space_in_name)) : '';
            $extra         = ['phone' => [$phone, 'Phone']];
            $user_id       = sb_add_user(['first_name' => $first_name, 'last_name' => $last_name, 'user_type' => 'lead'], $extra);
            $user          = sb_get_user($user_id);
        } else {
            $user_id         = $user['id'];
            $conversation_id = sb_whatsapp_get_conversation_id($user_id);
        }
    
        $GLOBALS['SB_LOGIN'] = $user;
    
        if (!$conversation_id) {
            $conversation_id = sb_isset(sb_new_conversation($user_id, 2, '', $department, -1, 'wa'), 'details', [])['id'];
        }
    
        // Attachments
        $attachments = [];
        if (in_array($response['payload']['type'], ['audio', 'video', 'file', 'image', 'sticker'])) {
            $directory_date = date('d-m-y');
            $path           = '../../uploads/' . $directory_date;
            $url            = SB_URL . '/uploads/' . $directory_date;
    
            if (!file_exists($path)) {
                mkdir($path, 0777, true);
            }

            $file_name = get_filename($response);
            
            if (!file_put_contents($path . '/' . $file_name, stream_get_contents(fopen($response['payload']['payload']['url'], 'r')))) {
                die();
            }
    
            array_push($attachments, [$file_name, $url . '/' . $file_name]);
        }
   
        // Send message
        $response = sb_send_message($isAdminAnswer ? 1 : $user_id, $conversation_id, $message, $attachments, 2, $payload);

        // Dialogflow, Notifications, Bot messages
        $response_external = sb_messaging_platforms_functions($conversation_id, $message, $attachments, $user, ['source' => 'wa', 'platform_value' => $phone]);
        
        // Queue
        if (sb_get_multi_setting('queue', 'queue-active')) {
            sb_queue($conversation_id, $department, true);
        }
    
        // Online status
        sb_update_users_last_activity($user_id);
        
        $GLOBALS['SB_FORCE_ADMIN'] = false;
} catch (Throwable $e) {
    var_dump($e);
}


function sb_whatsapp_get_conversation_id($user_id) {
    return sb_isset(sb_db_get('SELECT id FROM sb_conversations WHERE source = "wa" AND user_id = ' . $user_id . ' ORDER BY id DESC LIMIT 1'), 'id');
}

function api_whatsapp_parse_phone($jid) {
    return substr($jid, 0, strpos($jid, '@'));
}

function get_filename($data) {
    switch ($data['payload']['type']) {
        case 'audio':
            switch ($data['payload']['payload']['contentType']) {
                case "audio/mp3":
                case "audio/mpeg":
                    $ext = '.mp3';
                    break;
                case "audio/mp4":
                    $ext = '.mp4';
                    break;
                case "audio/ogg; codecs=opus":
                default:
                    $ext = '.ogg';
            }
            return basename($data['payload']['payload']['url']) . $ext;
        case 'video':
            return basename($data['payload']['payload']['url']) . '.mp4';
        case 'file':
            return $data['payload']['payload']['name'];
        case 'image':
            $ext = $data['payload']['payload']['contentType'] == "image/png" ? '.png' : '.jpg';
            return basename($data['payload']['payload']['url']) . $ext;
        case 'sticker':
            return basename($data['payload']['payload']['url']) . '.webp';
    }
}

die();

?>