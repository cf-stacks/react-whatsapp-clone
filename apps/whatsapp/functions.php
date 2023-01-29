<?php

/*
 * ==========================================================
 * WHATSAPP APP
 * ==========================================================
 *
 * WhatsApp app main file. © 2017-2022 board.support. All rights reserved.
 *
 * 1. Send a message to WhatsApp
 * 2. Convert Support Board rich messages to WhatsApp rich messages
 * 3. 360dialog synchronization
 * 4. 360dialog curl
 * 5. Upload a WhatsApp 360dialog media
 * 6. Return the WhatsApp templates of 360dialog
 * 7. WhatsApp shop URL
 *
 */

define('SB_WHATSAPP', '1.0.6');

function sb_whatsapp_send_message($to, $message = '', $attachments = []) {
    if (empty($message) && empty($attachments)) return false;
    $settings = sb_get_setting('whatsapp-twilio');
    $twilio = !empty($settings['whatsapp-twilio-user']);
    $to = trim(str_replace('+', '', $to));
    $user = sb_get_user_by('phone', $to);
    $response = false;
    $merge_field = false;
    $merge_field_checkout = false;

    $goproxy = !empty(sb_get_multi_setting('whatsapp-go', 'whatsapp-go-active')) && !empty(sb_get_multi_setting('whatsapp-go', 'whatsapp-go-url'));
    $gupshup = !empty(sb_get_multi_setting('whatsapp-gupshup', 'whatsapp-gupshup-active'));

    // Security
    if (!sb_is_agent() && !sb_is_agent($user) && sb_get_active_user_ID() != sb_isset($user, 'id') && empty($GLOBALS['SB_FORCE_ADMIN'])) {
        return new SBError('security-error', 'sb_whatsapp_send_message');
    }

    // Send the message
    if (is_string($message)) {
        $message = sb_whatsapp_rich_messages($message, ['user_id' => $user['id']]);
        if ($message[1]) $attachments = $message[1];
        $message = $message[0];
        $merge_field = sb_get_shortcode($message, 'catalog', true);
        $merge_field_checkout = sb_get_shortcode($message, 'catalog_checkout', true);
    }
    $attachments_count = count($attachments);
    if ($twilio) {
        $supported_mime_types = ['jpg', 'jpeg', 'png', 'pdf', 'mp3', 'ogg', 'amr', 'mp4'];
        $from = $settings['whatsapp-twilio-sender'];
        $header = ['Authorization: Basic ' . base64_encode($settings['whatsapp-twilio-user'] . ':' . $settings['whatsapp-twilio-token'])];
        $query = ['Body' => $message, 'From' => trim(strpos($from, 'whatsapp') === false ? ('whatsapp:' . $from) : $from), 'To' => 'whatsapp:' . $to];
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['whatsapp-twilio-user'] . '/Messages.json';
        if ($attachments_count) {
            if (in_array(strtolower(sb_isset(pathinfo($attachments[0][1]), 'extension')), $supported_mime_types)) $query['MediaUrl'] = str_replace(' ', '%20', $attachments[0][1]);
            else $query['Body'] .= PHP_EOL . PHP_EOL . $attachments[0][1];
        }
        $response = sb_curl($url, $query, $header);
        if ($attachments_count > 1) {
            $query['Body'] = '';
            for ($i = 1; $i < $attachments_count; $i++) {
                if (in_array(strtolower(sb_isset(pathinfo($attachments[$i][1]), 'extension')), $supported_mime_types)) $query['MediaUrl'] = str_replace(' ', '%20', $attachments[$i][1]);
                else $query['Body'] = $attachments[$i][1];
                $response = sb_curl($url, $query, $header);
            }
        }
    } elseif ($gupshup) {
        $url = 'https://api.gupshup.io/sm/api/v1/msg';
        $header = ['Content-Type: application/x-www-form-urlencoded', 'apikey: ' . sb_get_multi_setting('whatsapp-gupshup', 'whatsapp-gupshup-api-key')];
        $query = [
            "channel"     => "whatsapp",
            "source"      => sb_get_multi_setting('whatsapp-gupshup', 'whatsapp-gupshup-phone'),
            "src.name"    => sb_get_multi_setting('whatsapp-gupshup', 'whatsapp-gupshup-app'),
            "destination" => $to,
        ];
        if ($attachments_count) {
            $filename = str_replace(' ', '%20', $attachments[0][1]);
            if (in_array(strtolower(sb_isset(pathinfo($filename), 'extension')), ['jpg', 'jpeg', 'png'])) {
                // image
                $query['message'] = json_encode([
                    "type"        => "image",
                    "originalUrl" => $filename,
                    "previewUrl"  => $filename,
                ]);
            } elseif (in_array(strtolower(sb_isset(pathinfo($filename), 'extension')), ['mp3', 'ogg', 'amr'])) {
                // audio
                $query['message'] = json_encode([
                    "type" => "audio",
                    "url"  => $filename,
                ]);
            } elseif (in_array(strtolower(sb_isset(pathinfo($filename), 'extension')), ['mp4'])) {
                // video
                $query['message'] = json_encode([
                    "type" => "video",
                    "url"  => $filename,
                ]);
            } else {
                // file
                $query['message'] = json_encode([
                    "type"     => "file",
                    "url"      => $filename,
                    "filename" => basename($filename),
                ]);
            }
        } else {
            $query['message'] = json_encode([
                "type" => "text",
                "text" => $message,
            ]);
        }

        $response = sb_curl($url, $query, $header);

        // $date = (new DateTime('NOW'))->format("y:m:d h:i:s");
        // if ($handle = fopen('log.txt', 'a')) {
        //     fwrite($handle, $date . ' REQ --> ' . json_encode($query) . PHP_EOL);
        //     fwrite($handle, $date . ' RES <-- ' . json_encode($response) . PHP_EOL);
        // }
        // fclose($handle);
    } elseif ($goproxy) {
        $url = sb_get_multi_setting('whatsapp-go', 'whatsapp-go-url');
        $header = ['Content-Type: application/json'];
        $query = [
            "receiver" => $to,
            "message"  => $message,
        ];
        if ($attachments_count) {
            $query['media'] = str_replace(' ', '%20', $attachments[0][1]);
        }

        $response = sb_curl($url, json_encode($query), $header);
    } else {
        if ($message) {
            $query = ['recipient_type' => 'individual', 'to' => $to];
            if (is_string($message)) {
                if ($merge_field_checkout) {
                    $query['type'] = 'text';
                    $query['text'] = ['body' => str_replace('{catalog_checkout}', sb_woocommerce_get_url('cart') . '?sbwa=' . sb_encryption($to), $message)];
                } else if ($merge_field) {
                    $query['type'] = 'interactive';
                    if (isset($merge_field['product_id'])) {
                        $query['interactive'] = ['type' => 'product', 'action' => ['catalog_id' => $merge_field['id'], 'product_retailer_id' => $merge_field['product_id']]];
                    } else {
                        $continue = true;
                        $index = 1;
                        $sections = [];
                        $query['interactive'] = ['type' => 'product_list', 'action' => ['catalog_id' => $merge_field['id']], 'header' => ['text' => $merge_field['header'], 'type' => 'text']];
                        while ($continue) {
                            if (isset($merge_field['section_' . $index])) {
                                $continue_2 = true;
                                $index_2 = 1;
                                $products = [];
                                while ($continue_2) {
                                    $id = 'product_id_' . $index . '_' . $index_2;
                                    if (isset($merge_field[$id])) {
                                        array_push($products, ['product_retailer_id' => $merge_field[$id]]);
                                        $index_2++;
                                    } else {
                                        array_push($sections, ['title' => $merge_field['section_' . $index], 'product_items' => $products]);
                                        $continue_2 = false;
                                    }
                                }
                                $index++;
                            } else {
                                $query['interactive']['action']['sections'] = $sections;
                                $continue = false;
                            }
                        }
                    }
                    if (isset($merge_field['body'])) $query['interactive']['body'] = ['text' => $merge_field['body']];
                    if (isset($merge_field['footer'])) $query['interactive']['footer'] = ['text' => $merge_field['footer']];
                } else {
                    $query['type'] = 'text';
                    $query['text'] = ['body' => $message];
                }
            } else {
                $query = array_merge($query, $message);
            }
            $response = sb_whatsapp_360_curl('messages', $query);
        }
        for ($i = 0; $i < $attachments_count; $i++) {
            $link = $attachments[$i][1];
            $media_type = 'document';
            switch (strtolower(sb_isset(pathinfo($link), 'extension'))) {
                case 'jpg':
                case 'jpeg':
                case 'png':
                    $media_type = 'image';
                    break;
                case 'mp4':
                case '3gpp':
                    $media_type = 'video';
                    break;
                case 'aac':
                case 'amr':
                case 'mpeg':
                    $media_type = 'audio';
                    break;
            }
            $query = ['recipient_type' => 'individual', 'to' => $to, 'type' => $media_type];
            $query[$media_type] = ['link' => $link, 'caption' =>  $media_type == 'document' ? $attachments[$i][0] : ''];
            $response_2 = sb_whatsapp_360_curl('messages', $query);
            if (!$response) $response = $response_2;
        }
    }
    return $response;
}

function sb_whatsapp_rich_messages($message, $extra = false) {
    $shortcode = sb_get_shortcode($message);
    $attachments = false;
    if ($shortcode) {
        $shortcode_id = sb_isset($shortcode, 'id', '');
        $shortcode_name = $shortcode['shortcode_name'];
        $message = trim(str_replace($shortcode['shortcode'], '', $message) . (isset($shortcode['title']) ? ' *' . sb_($shortcode['title']) . '*' : '') . PHP_EOL . sb_(sb_isset($shortcode, 'message', '')));
        $twilio = !empty(sb_get_multi_setting('whatsapp-twilio', 'whatsapp-twilio-user'));
        switch ($shortcode_name) {
            case 'slider-images':
                $attachments = explode(',', $shortcode['images']);
                for ($i = 0; $i < count($attachments); $i++) {
                    $attachments[$i] = [$attachments[$i], $attachments[$i]];
                }
                $message = '';
                break;
            case 'slider':
            case 'card':
                $suffix = $shortcode_name == 'slider' ? '-1' : '';
                $message = '*' . sb_($shortcode['header' . $suffix]) . '*' . (isset($shortcode['description' . $suffix]) ? (PHP_EOL . $shortcode['description' . $suffix]) : '') . (isset($shortcode['extra' . $suffix]) ? (PHP_EOL . '```' . $shortcode['extra' . $suffix] . '```') : ''). (isset($shortcode['link' . $suffix]) ? (PHP_EOL . PHP_EOL . $shortcode['link' . $suffix]) : '');
                $attachments = [[$shortcode['image' . $suffix], $shortcode['image' . $suffix]]];
                break;
            case 'list-image':
            case 'list':
                $index = 0;
                if ($shortcode_name == 'list-image') {
                    $shortcode['values'] = str_replace('://', '', $shortcode['values']);
                    $index = 1;
                }
                $values = explode(',', $shortcode['values']);
                if (strpos($values[0], ':')) {
                    for ($i = 0; $i < count($values); $i++) {
                        $value = explode(':', $values[$i]);
                        $message .= PHP_EOL . '• *' . trim($value[$index]) . '* ' . trim($value[$index + 1]);
                    }
                } else {
                    for ($i = 0; $i < count($values); $i++) {
                        $message .= PHP_EOL . '• ' . trim($values[$i]);
                    }
                }
                break;
            case 'select':
            case 'buttons':
            case 'chips':
                $values = explode(',', $shortcode['options']);
                $count = count($values);
                if ($twilio) {
                    $message .= PHP_EOL;
                    for ($i = 0; $i < $count; $i++) {
                        $message .= PHP_EOL . '• ' . trim($values[$i]);
                    }
                } else {
                    if ($count > 10) $count = 10;
                    $is_buttons = $count < 4;
                    $message = ['type' => $is_buttons ? 'button' : 'list', 'body' => ['text' => sb_isset($shortcode, 'message')]];
                    if (!empty($shortcode['title'])) {
                        $message['header'] = ['type' => 'text', 'text' => $shortcode['title']];
                    }
                    $buttons = [];
                    for ($i = 0; $i < $count; $i++) {
                        $value = trim($values[$i]);
                        $item = ['id' => $value, 'title' => substr($value, 0, 20)];
                        array_push($buttons, $is_buttons ? ['type' => 'reply', 'reply' => $item] : $item);
                    }
                    $message['action'] = $is_buttons ? ['buttons' => $buttons] : ['button' => sb_(sb_isset($shortcode, 'whatsapp', 'Menu')), 'sections' => [['title' => substr(sb_isset($shortcode, 'title', $shortcode['message']), 0, 24), 'rows' => $buttons]]];
                    $message = ['type' => 'interactive', 'interactive' => $message];
                }
                if ($shortcode_id == 'sb-human-takeover' && defined('SB_DIALOGFLOW')) {
                    sb_dialogflow_set_active_context('human-takeover', [], 2, false, sb_isset($extra, 'user_id'));
                }
                break;
            case 'button':
                $message = $shortcode['link'];
                break;
            case 'video':
                $message = ($shortcode['type'] == 'youtube' ? 'https://www.youtube.com/embed/' : 'https://player.vimeo.com/video/') . $shortcode['id'];
                break;
            case 'image':
                $attachments = [[$shortcode['url'], $shortcode['url']]];
                break;
            case 'rating':
                if (!$twilio) {
                    $message =  ['type' => 'interactive', 'interactive' => ['type' => 'button', 'body' => ['text' => $shortcode['message']], 'action' =>  ['buttons' => [['type' => 'reply', 'reply' => ['id' => 'rating-positive', 'title' => sb_($shortcode['label-positive'])]], ['type' => 'reply', 'reply' => ['id' => 'rating-negative', 'title' => sb_($shortcode['label-negative'])]]]]]];
                    if (!empty($shortcode['title'])) {
                        $message['interactive']['header'] = ['type' => 'text', 'text' => $shortcode['title']];
                    }
                }
                if (defined('SB_DIALOGFLOW')) sb_dialogflow_set_active_context('rating', [], 2, false, sb_isset($extra, 'user_id'));
                break;
            case 'articles':
                if (isset($shortcode['link'])) $message = $shortcode['link'];
                break;
        }
    }
    return [$message, $attachments];
}

function sb_whatsapp_360_synchronization($key = false, $cloud = '') {
    return sb_whatsapp_360_curl('configs/webhook', ['url' => SB_URL . '/apps/whatsapp/post.php' . str_replace(['&', '%26', '%3D'], ['?', '?', '='], $cloud)]);
}

function sb_whatsapp_360_curl($url_part, $post_fields = false, $type = 'POST') {
    $key = sb_get_multi_setting('whatsapp-360', 'whatsapp-360-key');
    return sb_curl((strpos($key, 'sandbox') ? 'https://waba-sandbox.360dialog.io/v1/' : 'https://waba.360dialog.io/v1/') . $url_part, $post_fields ? json_encode($post_fields) : '', ['D360-API-KEY: ' . $key, 'Content-Type: application/json'], $type);
}

function sb_whatsapp_360_upload($link) {
    $path = substr($link, strrpos(substr($link, 0, strrpos($link, '/')), '/'));
    $response = sb_curl('https://waba.360dialog.io/v1/media', file_get_contents(sb_upload_path() . $path), ['D360-API-KEY: ' . sb_get_multi_setting('whatsapp-360', 'whatsapp-360-key')], 'UPLOAD');
    return isset($response['media']) ? $response['media'][0]['id'] : false;
}

function sb_whatsapp_360_templates($template_name = false, $template_language = false) {
    $templates = sb_isset(json_decode(sb_whatsapp_360_curl('configs/templates', false, 'GET'), true), 'waba_templates', []);
    if ($template_name) {
        $template = false;
        $default_language = sb_get_multi_setting('whatsapp-template-360', 'whatsapp-template-360-language');
        $template_language = substr(strtolower($template_language), 0, 2);
        for ($i = 0; $i < count($templates); $i++) {
            if ($templates[$i]['name'] == $template_name) {
                if (!$template_language) return $templates[$i];
                $language = substr(strtolower($templates[$i]['language']), 0, 2);
                if ($language == $template_language) {
                    return $templates[$i];
                } else if ($language == $default_language) {
                    $template = $templates[$i];
                }
            }
        }
        return $template;
    }
    return $templates;
}

function sb_whatsapp_shop_url($sbwa) {
    $carts = sb_get_external_setting('wc-whatsapp-carts');
    $cart = sb_isset($carts, sb_encryption($sbwa, false));
    $update = false;
    $now = time();
    if ($cart) {
        for ($i = 0; $i < count($cart); $i++) {
            sb_woocommerce_update_cart($cart[$i]['product_retailer_id'], 'cart-add', $cart[$i]['quantity']);
        }
        header('Location: ' . wc_get_checkout_url());
    }
    for ($i = 0; $i < count($carts); $i++) {
        if ($now > $cart[$i]['expiration']) {
            array_splice($carts, $i, 1);
            $update = true;
        }
    }
    if ($update) {
        sb_save_external_setting('wc-whatsapp-carts', $carts);
    }
}

?>