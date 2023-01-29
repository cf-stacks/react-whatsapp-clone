<?php

/*
 * ==========================================================
 * WHATSAPP APP POST FILE
 * ==========================================================
 *
 * WhatsApp app post file to receive messages sent by Twilio. © 2017-2022 board.support. All rights reserved.
 *
 */

$raw = file_get_contents('php://input');
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if ($raw) {
    require('../../include/functions.php');
    $twilio = !empty(sb_get_multi_setting('whatsapp-twilio', 'whatsapp-twilio-user'));
    $response = [];
    if ($twilio) {
        $items = explode('&', urldecode($raw));
        for ($i = 0; $i < count($items); $i++) {
            $value = explode('=', $items[$i]);
            $response[$value[0]] = str_replace('\/', '/', $value[1]);
        }
    } else {
        $response = json_decode($raw, true);
    }
    $error = $twilio ? sb_isset($response, 'ErrorCode') : (isset($response['statuses']) && is_array($response['statuses']) ? $response['statuses'][0]['errors'][0]['code'] : false);
    if (($twilio && isset($response['From']) && !$error) || (!$twilio && isset($response['messages']))) {
        if ($twilio && (!isset($response['Body']) && !isset($response['MediaContentType0']))) die();
        sb_cloud_load_by_url();
        $GLOBALS['SB_FORCE_ADMIN'] = true;
        $user_id = false;
        $conversation_id = false;
        $phone = $twilio ? str_replace('whatsapp:', '', $response['From']) : '+' . $response['contacts'][0]['wa_id'];
        $user = sb_get_user_by('phone', $phone);
        $department = sb_get_setting('whatsapp-department');
        $payload = '';

        if ($twilio) {
            $message = $response['Body'];
        } else {
            $message_360 = $response['messages'][0];
            $message_360_type = $message_360['type'];
            $message = $message_360_type == 'text' ? $message_360['text']['body'] : '';
            $payload = json_encode(['waid' => $message_360['id']]);
        }

        // User and conversation
        if (!$user) {
            $name = $twilio ? $response['ProfileName'] : $response['contacts'][0]['profile']['name'];
            $space_in_name = strpos($name, ' ');
            $first_name = $space_in_name ? trim(substr($name, 0, $space_in_name)) : $name . $space_in_name;
            $last_name = $space_in_name ? trim(substr($name, $space_in_name)) : '';
            $extra = ['phone' => [$phone, 'Phone']];
            if ($message && sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')) {
                $detected_language = sb_google_language_detection($message);
                if (!empty($detected_language)) $extra['language'] = [$detected_language, 'Language'];
            }
            $user_id = sb_add_user(['first_name' => $first_name, 'last_name' => $last_name, 'user_type' => 'user'], $extra);
            $user = sb_get_user($user_id);
        } else {
            $user_id = $user['id'];
            $conversation_id = sb_whatsapp_get_conversation_id($user_id);
        }
        $GLOBALS['SB_LOGIN'] = $user;
        if (!$conversation_id) {
            $conversation_id = sb_isset(sb_new_conversation($user_id, 2, '', $department, -1, 'wa'), 'details', [])['id'];
        } else if ($payload && sb_isset(sb_db_get('SELECT COUNT(*) AS `count` FROM sb_messages WHERE conversation_id =  ' . $conversation_id . ' AND payload LIKE "%' . sb_db_escape($payload) . '%"'), 'count') != 0) {
            die();
        }

        // Attachments
        $attachments = [];
        if ($twilio) {
            $extension = sb_isset($response, 'MediaContentType0');
            if ($extension) {
                $extension = sb_whatsapp_get_extension($extension);
                if ($extension) {
                    $file_name = basename($response['MediaUrl0']) . $extension;
                    array_push($attachments, [$file_name, sb_download_file($response['MediaUrl0'], $file_name)]);
                }
            }
        } else if ($message_360_type != 'text') {
            $file_data = $message_360[$message_360_type];
            switch ($message_360_type) {
                case 'contacts':
                    for ($i = 0; $i < count($file_data); $i++) {
                        $message .= $file_data[$i]['phones'][0]['phone'] . PHP_EOL;
                    }
                    break;
                case 'interactive':
                    $message = $file_data[$file_data['type']]['title'];
                    break;
                case 'order':
                    $total = 0;
                    $products = $file_data['product_items'];
                    for ($i = 0; $i < count($products); $i++) {
                        $price = intval($products[$i]['item_price']);
                        $quantity = intval($products[$i]['quantity']);
                        $message .= '*' . $price . $products[$i]['currency'] . '* ' . $products[$i]['product_retailer_id'] . ($quantity > 1 ? ' __x' . $quantity . '__' : '') . PHP_EOL;
                        $total += $price;
                    }
                    $message = '`' . sb_('New order') . '` ' . $products[0]['currency'] . ' ' . $total . PHP_EOL . $message;
                    $url = sb_get_setting('whatsapp-order-webhook');
                    if ($url) {
                        sb_curl($url, $raw, [ 'Content-Type: application/json', 'Content-Length: ' . strlen($raw)]);
                    }
                    if (defined('SB_WOOCOMMERCE')) {
                        $woocommerce_wa_carts = sb_get_external_setting('wc-whatsapp-carts', []);
                        $products['expiration'] = time() + 2600000;
                        $woocommerce_wa_carts[trim(str_replace('+', '', $phone))] = $products;
                        sb_save_external_setting('wc-whatsapp-carts', $woocommerce_wa_carts);
                    }
                    break;
                default:
                    $file_name = sb_isset($file_data, 'filename', $file_data['id']);
                    $url = sb_download_file('https://waba.360dialog.io/v1/media/' . $file_data['id'], $file_name, isset($file_data['filename']) ? '' : $file_data['mime_type'], ['D360-API-KEY: ' . sb_get_multi_setting('whatsapp-360', 'whatsapp-360-key')]);
                    array_push($attachments, [basename($url), $url]);
                    if (isset($file_data['caption']) && $file_data['caption'] != $file_name) $message = $file_data['caption'];
            }
        }

        // Send message
        $response = sb_send_message($user_id, $conversation_id, $message, $attachments, 2, $payload);

        // Dialogflow, Notifications, Bot messages
        $response_extarnal = sb_messaging_platforms_functions($conversation_id, $message, $attachments, $user, ['source' => 'wa', 'platform_value' => $phone]);

        // Queue
        if (sb_get_multi_setting('queue', 'queue-active')) {
            sb_queue($conversation_id, $department, true);
        }

        // Online status
        sb_update_users_last_activity($user_id);

        $GLOBALS['SB_FORCE_ADMIN'] = false;
    } else if ($error === 470) {
        if (!$twilio) $response = $response['statuses'][0];
        $phone = $twilio ? str_replace('whatsapp:', '', $response['To']) : $response['recipient_id'];
        $user = sb_get_user_by('phone', $phone);
        if (!isset($response['ErrorMessage']) && isset($response['MessageStatus'])) $response['ErrorMessage'] = $response['MessageStatus'];
        if ($user) {
            $agents_ids = sb_get_agents_ids();
            $message = sb_db_get('SELECT id, message, attachments, conversation_id FROM sb_messages WHERE user_id IN (' . implode(',', $agents_ids) . ') AND conversation_id IN (SELECT id FROM sb_conversations WHERE source = "wa" AND user_id = ' . $user['id'] . ') ORDER BY id DESC LIMIT 1');
            if ($message) {
                $GLOBALS['SB_FORCE_ADMIN'] = true;
                $conversation_id = $message['conversation_id'];
                $user_language = sb_get_user_language($user['id']);
                $user_name = sb_get_user_name($user);
                $user_email = sb_isset($user, 'email', '');
                $conversation_url_parameter = $conversation_id && $user ? ('?conversation=' . $conversation_id . '&token=' . $user['token']) : '';

                // SMS
                if (sb_get_multi_setting('whatsapp-sms', 'whatsapp-sms-active')) {
                    $template = sb_get_multi_setting('whatsapp-sms', 'whatsapp-sms-template');
                    $message_sms = $template ? str_replace('{message}', $message['message'], sb_translate_string($template, $user_language)) : $message['message'];
                    $message_sms = str_replace(['{conversation_url_parameter}', '{recipient_name}', '{recipient_email}'], [$conversation_url_parameter, $user_name, $user_email], $message_sms);
                    $response_sms = sb_send_sms($message_sms, $phone, false, $conversation_id, empty($message['attachments']) ? [] : json_decode($message['attachments']));
                    if ($response_sms['status'] == 'sent' || $response_sms['status'] == 'queued') $response = ['whatsapp-fallback' => true];
                }

                // WhatsApp Template
                $response_template = false;
                if ($twilio) {
                    $template = sb_get_setting('whatsapp-template');
                    if ($template) {
                        $response_template = sb_whatsapp_send_message($phone, str_replace(['{conversation_url_parameter}', '{recipient_name}', '{recipient_email}'], [$conversation_url_parameter, $user_name, $user_email], sb_translate_string($template, $user_language)));
                    }
                } else {
                    $settings = sb_get_setting('whatsapp-template-360');
                    if ($settings && !empty($settings['whatsapp-template-360-namespace'])) {
                        $template = sb_whatsapp_360_templates($settings['whatsapp-template-360-name'], $user_language);
                        if ($template) {
                            $merge_fields = explode(',', str_replace(' ', '', $settings['whatsapp-template-360-parameters']));
                            $parameters = [];
                            $index = 0;
                            $components = sb_isset($template, 'components', []);
                            for ($i = 0; $i < count($components); $i++) {
                                switch (strtolower($components[$i]['type'])) {
                                    case 'body':
                                        $count = substr_count($components[$i]['text'], '{{');
                                        if ($count) {
                                            $parameters_sub = [];
                                            for ($j = 0; $j < $count; $j++) {
                                                array_push($parameters_sub, sb_whatsapp_create_template_parameter('text', $merge_fields[$index], $conversation_url_parameter, $user_name, $user_email));
                                                $index++;
                                            }
                                            array_push($parameters, ['type' => 'body', 'parameters' => $parameters_sub]);
                                        }
                                        break;
                                    case 'buttons':
                                        $buttons = $components[$i]['buttons'];
                                        for ($j = 0; $j < count($buttons); $j++) {
                                            $key = strtolower($buttons[$j]['type']) == 'url' ? 'url' : 'text';
                                            $count = substr_count($buttons[$j][$key], '{{');
                                            if ($count) {
                                                array_push($parameters, ['type' => 'button', 'sub_type' => $key, 'index' => $j, 'parameters' => [sb_whatsapp_create_template_parameter('text', $merge_fields[$index], $conversation_url_parameter, $user_name, $user_email)]]);
                                                $index++;
                                            }
                                        }
                                        break;
                                    case 'header':
                                        $format = strtolower($components[$i]['format']);
                                        $parameter = ['type' => $format];
                                        if ($format == 'text') {
                                            $parameter = sb_whatsapp_create_template_parameter('text', $merge_fields[$index], $conversation_url_parameter, $user_name, $user_email);
                                        } else {
                                            $parameter[$format] = ['link' => $components[$i]['example']['header_handle'][0]];
                                        }
                                        array_push($parameters, ['type' => 'header', 'parameters' => [$parameter]]);
                                        break;
                                }
                            }
                            $query = ['type' => 'template', 'template' => ['namespace' => $settings['whatsapp-template-360-namespace'], 'language' => ['policy' => 'deterministic', 'code' => $template['language']], 'name' => $template['name'], 'components' => $parameters]];
                            $response_template = sb_whatsapp_send_message($phone, $query);
                        }
                    }
                }
                if (($twilio && ($response_template['status'] == 'sent' || $response_template['status'] == 'queued')) || (!$twilio && $response_template && count(sb_isset($response_template, 'messages')))) {
                    if (isset($response['whatsapp-fallback'])) {
                        $response['whatsapp-template-fallback'] = true;
                    } else {
                        $response = ['whatsapp-template-fallback' => true];
                    }
                }
                sb_update_message($message['id'], false, false, $response);
                $GLOBALS['SB_FORCE_ADMIN'] = false;
            }
        }
    }
}

function sb_whatsapp_create_template_parameter($type, $text, $conversation_url_parameter, $user_name, $user_email) {
    $parameter = ['type' => $type];
    $parameter[$type] = str_replace(['{conversation_url_parameter}', '{recipient_name}', '{recipient_email}'], [$conversation_url_parameter, $user_name, $user_email], $text);
    return $parameter;
}

function sb_whatsapp_get_conversation_id($user_id) {
    return sb_isset(sb_db_get('SELECT id FROM sb_conversations WHERE source = "wa" AND user_id = ' . $user_id . ' ORDER BY id DESC LIMIT 1'), 'id');
}

function sb_whatsapp_get_extension($mime_type) {
    switch ($mime_type) {
        case 'video/mp4':
            return '.mp4';
        case 'image/gif':
            return'.gif';
        case 'image/png':
            return '.png';
        case 'image/jpg':
        case 'image/jpeg':
            return '.jpg';
        case 'image/webp':
            return '.webp';
        case 'audio/ogg':
            return '.ogg';
        case 'audio/mpeg':
            return '.mp3';
        case 'audio/amr':
            return '.amr';
        case 'application/pdf':
            return '.pdf';
    }
    return false;
}

die();

?>