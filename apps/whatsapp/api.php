<?php
/*
 * ==========================================================
 * GOLANG WHATSAPP MESSAGE RECEIVER
 * ==========================================================
 *
 * WhatsApp app post file to receive messages sent by custom golang service
 *
 */

$raw = file_get_contents('php://input');
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    if (!empty($_POST['Chat'])) {
        require('../../include/functions.php');
        if (empty(sb_get_multi_setting('whatsapp-go', 'whatsapp-go-active'))) {
            die();
        }
    
        $response = $_POST;
    
        if (empty($response['Conversation']) && empty($_FILES["attachment"])) {
            die();
        }
        sb_cloud_load_by_url();
    
        $GLOBALS['SB_FORCE_ADMIN'] = true;

        $adminPhone      = sb_get_multi_setting('whatsapp-go', 'whatsapp-go-phone');
        $isAdminAnswer   = false;
    
        $user_id         = false;
        $conversation_id = false;
        $phone           = '+' . api_whatsapp_parse_phone($response['Sender']);

        if ($phone == $adminPhone) {
            $isAdminAnswer = true;
            $phone         = '+' . api_whatsapp_parse_phone($response['Chat']);
        }

        $user            = sb_get_user_by('phone', $phone);
        $department      = sb_get_setting('whatsapp-department');
        $payload         = '';
        $message         = $response['Conversation'];

        // if (filter_var($message, FILTER_VALIDATE_URL) !== FALSE) {
        //     $message = '<a href="' . $message . '" target="_blank">' . $message . '</a>';
        // }

        if ($isAdminAnswer && !$user) {
            die();
        }
    
        // User and conversation
        if (!$user) {
            $name          = trim($response['SenderName']);
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
        if (isset($_FILES['attachment'])) {
            if (0 < $_FILES['attachment']['error']) {
                // skip upload
            } else {
                $file_name      = $_FILES['attachment']['name'];
                $directory_date = date('d-m-y');
                $path           = '../../uploads/' . $directory_date;
                $url            = SB_URL . '/uploads/' . $directory_date;
    
                if (!file_exists($path)) {
                    mkdir($path, 0777, true);
                }
    
                move_uploaded_file($_FILES['attachment']['tmp_name'], $path . '/' . $file_name);
    
                array_push($attachments, [$file_name, $url . '/' . $file_name]);
            }
        }
   
        // Send message
        $response = sb_send_message($isAdminAnswer ? 1 : $user_id, $conversation_id, $message, $attachments, 2, $payload);

        if (!$isAdminAnswer) {
            // Dialogflow, Notifications, Bot messages
            $response_external = sb_messaging_platforms_functions($conversation_id, $message, $attachments, $user, ['source' => 'wa', 'platform_value' => $phone]);
        
            // Queue
            if (sb_get_multi_setting('queue', 'queue-active')) {
                sb_queue($conversation_id, $department, true);
            }
        
            // Online status
            sb_update_users_last_activity($user_id);
        }
        
        $GLOBALS['SB_FORCE_ADMIN'] = false;
    }
} catch (Throwable $e) {
    var_dump($e);
}


function sb_whatsapp_get_conversation_id($user_id) {
    return sb_isset(sb_db_get('SELECT id FROM sb_conversations WHERE source = "wa" AND user_id = ' . $user_id . ' ORDER BY id DESC LIMIT 1'), 'id');
}

function api_whatsapp_parse_phone($jid) {
    return substr($jid, 0, strpos($jid, '@'));
}

die();

?>