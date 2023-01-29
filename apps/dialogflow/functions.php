<?php

/*
 * ==========================================================
 * DIALOGFLOW APP
 * ==========================================================
 *
 * Dialogflow App main file. © 2017-2022 board.support. All rights reserved.
 *
 */

define('SB_DIALOGFLOW', '1.2.8');

/*
 * -----------------------------------------------------------
 * OBJECTS
 * -----------------------------------------------------------
 *
 * Dialogflow objects
 *
 */

class SBDialogflowEntity {
    public $data;

    function __construct($id, $values, $prompts = []) {
        $this->data = ['displayName' => $id, 'entities' => $values, 'kind' => 'KIND_MAP', 'enableFuzzyExtraction' => true];
    }

    public function __toString() {
        return $this->json();
    }

    function json() {
        return json_encode($this->data);
    }

    function data() {
        return $this->data;
    }
}

class SBDialogflowIntent {
    public $data;

    function __construct($name, $training_phrases, $bot_responses, $entities = [], $entities_values = [], $payload = false, $input_contexts = [], $output_contexts = [], $prompts = [], $id = false) {
        $training_phrases_api = [];
        $parameters = [];
        $parameters_checks = [];
        $messages = [];
        $json = json_decode(file_get_contents(SB_PATH . '/apps/dialogflow/data.json'), true);
        $entities = array_merge($entities, $json['entities']);
        $entities_values = array_merge($entities_values, $json['entities-values']);
        $project_id = false;
        if (is_string($bot_responses)) {
            $bot_responses = [$bot_responses];
        }
        if (is_string($training_phrases)) {
            $training_phrases = [$training_phrases];
        }
        for ($i = 0; $i < count($training_phrases); $i++) {
            $parts_temp = explode('@', $training_phrases[$i]);
            $parts = [];
            $parts_after = false;
            for ($j = 0; $j < count($parts_temp); $j++) {
                $part = ['text' => ($j == 0 ? '' : '@') . $parts_temp[$j]];
                for ($y = 0; $y < count($entities); $y++) {
                    $entity = is_string($entities[$y]) ? $entities[$y] : $entities[$y]['displayName'];
                    $entity_type = '@' . $entity;
                    $entity_name = str_replace('.', '-', $entity);
                    $entity_value = empty($entities_values[$entity]) ? $entity_type : $entities_values[$entity][array_rand($entities_values[$entity])];
                    if (strpos($part['text'], $entity_type) !== false) {
                        $mandatory = true;
                        if (strpos($part['text'], $entity_type . '*') !== false) {
                            $mandatory = false;
                            $part['text'] = str_replace($entity_type . '*', $entity_type, $part['text']);
                        }
                        $parts_after = explode($entity_type, $part['text']);
                        $part = ['text' => $entity_value,  'entityType' => $entity_type,  'alias' => $entity_name, 'userDefined' => true];
                        if (count($parts_after) > 1) {
                            $parts_after = ['text' => $parts_after[1]];
                        } else {
                            $parts_after = false;
                        }
                        if (!in_array($entity, $parameters_checks)) {
                            array_push($parameters, ['displayName' => $entity_name, 'value' => '$' . $entity, 'mandatory' => $mandatory, 'entityTypeDisplayName' => '@' . $entity, 'prompts' => sb_isset($prompts, $entity_name, [])]);
                            array_push($parameters_checks, $entity);
                        }
                        break;
                    }
                }
                array_push($parts, $part);
                if ($parts_after) array_push($parts, $parts_after);
            }
            array_push($training_phrases_api, ['type' => 'EXAMPLE', 'parts' => $parts]);
        }
        for ($i = 0; $i < count($bot_responses); $i++) {
            array_push($messages, ['text' => ['text' => $bot_responses[$i]]]);
        }
        if (!empty($payload)) {
            $std = new stdClass;
            $std->payload = $payload;
            array_push($messages, $std);
        }
        if (!empty($input_contexts) && is_array($input_contexts)) {
            $project_id = sb_get_setting('dialogflow-project-id');
            for ($i = 0; $i < count($input_contexts); $i++) {
                $input_contexts[$i] = 'projects/' . $project_id. '/agent/sessions/-/contexts/' . $input_contexts[$i];
            }
        }
        if (!empty($output_contexts) && is_array($output_contexts)) {
            $project_id = $project_id == false ? sb_get_setting('dialogflow-project-id') : $project_id;
            for ($i = 0; $i < count($output_contexts); $i++) {
                $is_array = is_array($output_contexts[$i]);
                $output_contexts[$i] = ['name' => 'projects/' . $project_id . '/agent/sessions/-/contexts/' . ($is_array ? $output_contexts[$i][0] : $output_contexts[$i]), 'lifespanCount' => ($is_array ? $output_contexts[$i][1] : 3)];
            }
        }
        $t = ['displayName' => $name, 'trainingPhrases' => $training_phrases_api, 'parameters' => $parameters, 'messages' => $messages, 'inputContextNames' => $input_contexts, 'outputContexts' => $output_contexts];
        if ($id) $t['name'] = $id;
        $this->data = $t;
    }

    public function __toString() {
        return $this->json();
    }

    function json() {
        return json_encode($this->data);
    }

    function data() {
        return $this->data;
    }
}

/*
 * -----------------------------------------------------------
 * SEND DIALOGFLOW BOT MESSAGE
 * -----------------------------------------------------------
 *
 * Send the user message to the bot and return the reply
 *
 */

$sb_recursion = true;
function sb_dialogflow_message($conversation_id = false, $message = '', $token = -1, $language = false, $attachments = [], $event = '', $parameters = false, $project_id = false) {
    $user_id = $conversation_id && sb_is_agent() ? sb_db_get('SELECT user_id FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id, true))['user_id'] : sb_get_active_user_ID();
    $query = ['queryInput' => [], 'queryParams' => ['payload' => ['support_board' => ['conversation_id' => $conversation_id, 'user_id' => $user_id]]]];
    $bot_id = sb_get_bot_id();
    $cx = sb_get_setting('dialogflow-edition', 'es') == 'cx';
    $human_takeover = sb_get_setting('dialogflow-human-takeover');
    $human_takeover = $human_takeover['dialogflow-human-takeover-active'] ? $human_takeover : false;
    $response_success = [];

    if ($parameters) {
        $query['queryParams']['payload'] = array_merge($query['queryParams']['payload'], $parameters);
    }
    if (empty($bot_id)) {
        return new SBValidationError('bot-id-not-found');
    }
    if ($language == false || empty($language[0])) {
        $language = sb_get_setting('dialogflow-multilingual') ? sb_get_user_language($user_id) : false;
        $language = $language ? [$language] : ['en'];
    } else {
        $language[0] = sb_dialogflow_language_code($language[0]);
        if (count($language) > 1 && $language[1] == 'language-detection') $response_success['language_detection'] = $language[0];
    }
    $query['queryInput']['languageCode'] = $language[0];

    // Retrive token
    if ($token == -1 || $token === false) {
        $token = sb_dialogflow_get_token();
        if (sb_is_error($token)) return $token;
    }

    // Attachments
    $attachments = sb_json_array($attachments);
    for ($i = 0; $i < count($attachments); $i++) {
        $message .= ' [name:' . $attachments[$i][0] . ',url:' . $attachments[$i][1] . ',extension:' . pathinfo($attachments[$i][0], PATHINFO_EXTENSION) . ']';
    }

    // Events
    if (!empty($event)) {
        $query['queryInput']['event'] = $cx ? ['event' => $event] : ['name' => $event, 'languageCode' => $language[0]];
    }

    // Message
    if (!empty($message)) {
        $query['queryInput']['text'] = ['text' => $message, 'languageCode' => $language[0]];
    }

    // Departments linking
    if (!$project_id && $conversation_id) {
        $departments = sb_get_setting('dialogflow-departments');
        if ($departments && is_array($departments)) {
            $department = sb_db_get('SELECT department FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id, true))['department'];
            for ($i = 0; $i < count($departments); $i++) {
                if ($departments[$i]['dialogflow-departments-id'] == $department) {
                    $project_id = $departments[$i]['dialogflow-departments-agent'];
                    break;
                }
            }
        }
    }

    // Send user message to Dialogflow
    $query = json_encode($query);
    $session_id = $user_id ? $user_id : 'sb';
    $response = sb_dialogflow_curl('/agent/sessions/' . $session_id . ':detectIntent', $query, false, 'POST', $token, $project_id);
    if (is_string($response)) $response = [];
    $response_query = sb_isset($response, 'queryResult', []);
    $messages = sb_isset($response_query, 'fulfillmentMessages', sb_isset($response_query, 'responseMessages', []));
    $unknow_answer = sb_dialogflow_is_unknow($response);
    $results = [];
    if (!$messages && isset($response_query['knowledgeAnswers'])) {
        $messages = sb_isset($response_query['knowledgeAnswers'], 'answers', []);
        for ($i = 0; $i < count($messages); $i++) {
            $messages[$i] = ['text' => ['text' => [$messages[$i]['answer']]]];
        }
    }
    sb_webhooks('SBBotMessage', ['response' => $response, 'message' => $message, 'conversation_id' => $conversation_id]);

    // Parameters
    $parameters = isset($response_query['parameters']) && count($response_query['parameters']) ? $response_query['parameters'] : [];
    if (isset($response_query['outputContexts']) && count($response_query['outputContexts']) && isset($response_query['outputContexts'][0]['parameters'])) {
        for ($i = 0; $i < count($response_query['outputContexts']); $i++) {
            if (isset($response_query['outputContexts'][$i]['parameters'])) {
                $parameters = array_merge($response_query['outputContexts'][$i]['parameters'], $parameters);
            }
        }
    }
    
    // Google search and spelling correction
    if ($unknow_answer && !sb_is_agent()) {
        $google_search_settings = sb_get_setting('dialogflow-google-search');
        if ($google_search_settings && $google_search_settings['dialogflow-google-search-active'] && strlen($message) > 2) {
            $entities = sb_isset($google_search_settings, 'dialogflow-google-search-entities');
            $spelling_correction = $google_search_settings['dialogflow-google-search-spelling-active'];
            $continue = true;
            if (!empty($entities) && is_array($entities)) {
                $continue = false;
                $entities_response = sb_isset(sb_google_analyze_entities($message, $language[0], $token), 'entities', []);
                for ($i = 0; $i < count($entities_response); $i++) {
                	if (in_array($entities_response[$i]['type'], $entities)) {
                        $continue = true;
                        break;
                    }
                } 
            }
            if ($continue || $spelling_correction) {
                $google_search_response = sb_get('https://www.googleapis.com/customsearch/v1?key=' . $google_search_settings['dialogflow-google-search-key'] . '&cx=' . $google_search_settings['dialogflow-google-search-id'] . '&q=' . urlencode($message), true);
                if ($spelling_correction && isset($google_search_response['spelling'])) {
                    return sb_dialogflow_message($conversation_id, $google_search_response['spelling']['correctedQuery'], $token, $language, $attachments, $event, $parameters);
                }
                if ($continue) {
                    $google_search_response = sb_isset($google_search_response, 'items');
                    if ($google_search_response && count($google_search_response)) {
                        $google_search_response = $google_search_response[0];
                        $bot_message = $google_search_response['snippet'];
                        $pos = strrpos($bot_message, '. ');
                        if (!$pos && substr($bot_message, -3) !== '...' && substr($bot_message, -1) === '.') $pos = strlen($bot_message);
                        if ($pos) {
                            $bot_message = substr($bot_message, 0, $pos);
                            $unknow_answer = false;
                            $messages = [['text' => ['text' => [$bot_message]]]];
                            sb_dialogflow_set_active_context('google-search', ['link' => $google_search_response['link']], 2, $token, $user_id, $language[0]);
                        }
                    }
                }
            }
        }
    }

    // Language detection
    if ($unknow_answer && !sb_is_agent()) {
        if (sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active') && count(sb_db_get('SELECT id FROM sb_messages WHERE user_id = ' . $user_id . ' LIMIT 3', false)) < 3) {
            $detected_language = sb_google_language_detection($message, $token);
            if ($detected_language != $language[0] && !empty($detected_language)) {
                $agent = sb_dialogflow_get_agent();
                sb_update_user_value($user_id, 'language', $detected_language);
                $response['queryResult']['action'] = 'sb-language-detection';
                if ($detected_language == sb_isset($agent, 'defaultLanguageCode') || in_array($detected_language, sb_isset($agent, 'supportedLanguageCodes', []))) {
                    return sb_dialogflow_message($conversation_id, $message, $token, [$detected_language, 'language-detection'], $attachments, $event);
                } else {
                    $language_detection_message = sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-message');
                    if (!empty($language_detection_message) && $conversation_id) {
                        $language_name = sb_google_get_language_name($detected_language);
                        $language_detection_message = str_replace('{language_name}', $language_name, sb_translate_string($language_detection_message, $detected_language));
                        $message_id = sb_send_message($bot_id, $conversation_id, $language_detection_message)['id'];
                        return ['token' => $token, 'messages' => [['message' => $language_detection_message, 'attachments' => [], 'payload' => ['language_detection' => true], 'id' => $message_id]], 'response' => $response, 'language_detection_message' => $language_detection_message, 'message_id' => $message_id];
                    }
                }
            }
        }
    }

    // Dialogflow response
    $count = count($messages);
    $is_assistant = true;
    $response['outputAudio'] = '';
    for ($i = 0; $i < $count; $i++) {
        if (isset($messages[$i]['text']) && $messages[$i]['text']['text'][0]) {
            $is_assistant = false;
            break;
        }
    }
    for ($i = 0; $i < $count; $i++) {
        $bot_message = '';

        // Payload
        $payload = sb_isset($messages[$i], 'payload');
        if ($payload && $conversation_id) {
            if (isset($payload['redirect'])) {
                $payload['redirect'] = sb_dialogflow_merge_fields($payload['redirect'], $parameters, $language[0]);
            }
            if (isset($payload['archive-chat'])) {
                sb_update_conversation_status($conversation_id, 3);
                if (sb_get_multi_setting('close-message', 'close-active')) sb_close_message($conversation_id, $bot_id);
                if (sb_get_multi_setting('close-message', 'close-transcript') && sb_isset(sb_get_active_user(), 'email')) {
                    $transcript = sb_transcript($conversation_id);
                    sb_email_create(sb_get_active_user_ID(), sb_get_user_name(), sb_isset(sb_get_active_user(), 'profile_image'), sb_get_multi_setting('transcript', 'transcript-message', ''), [[$transcript, $transcript]], true, $conversation_id);
                    $payload['force-message'] = true;
                }
            }
            if (isset($payload['update-user-details'])) {
                $user = sb_get_user($user_id);
                if (!sb_is_agent($user)) {
                    $user['user_type'] = '';
                    sb_update_user($user_id, array_merge($user, $payload['update-user-details']), sb_isset($payload['update-user-details'], 'extra', []));
                }
            }
        }

        // Google Assistant
        if ($is_assistant) {
            if (isset($messages[$i]['platform']) && $messages[$i]['platform'] == 'ACTIONS_ON_GOOGLE') {
                if (isset($messages[$i]['simpleResponses']) && isset($messages[$i]['simpleResponses']['simpleResponses'])) {
                    $item = $messages[$i]['simpleResponses']['simpleResponses'];
                    if (isset($item[0]['textToSpeech'])) {
                        $bot_message = $item[0]['textToSpeech'];
                    } else if ($item[0]['displayText']) {
                        $bot_message = $item[0]['displayText'];
                    }
                }
            }
        } else if (isset($messages[$i]['text'])) {

            // Message
            $bot_message = $messages[$i]['text']['text'][0];
        }

        // Attachments
        $attachments = [];
        if ($payload) {
            if (isset($payload['attachments'])) {
                $attachments = $payload['attachments'];
                if ($attachments == '' && !is_array($attachments)) {
                    $attachments = [];
                }
            }
        }

        // WooCommerce
        if (defined('SB_WOOCOMMERCE')) {
            $woocommerce = sb_woocommerce_dialogflow_process_message($bot_message, $payload);
            $bot_message = $woocommerce[0];
            $payload = $woocommerce[1];
        }

        // Send the bot message to Support Board or start human takeover
        if ($bot_message || $payload) {
            if ($conversation_id) {
                $is_human_takeover = sb_dialogflow_is_human_takeover($conversation_id);
                if ($human_takeover && $unknow_answer && strlen($message) > 3 && strpos($message, ' ') && !$is_human_takeover) {      
                    if ($human_takeover['dialogflow-human-takeover-auto']) {
                        $human_takeover_messages = sb_dialogflow_human_takeover($conversation_id);
                        for ($j = 0; $j < count($human_takeover_messages); $j++) {
                        	array_push($results, ['message' => $human_takeover_messages[$j]['message'], 'attachments' => [], 'payload' => false, 'id' => $human_takeover_messages[$j]['id']]);
                        }    
                        $response_success['human_takeover'] = true;
                    } else {
                        $human_takeover_message = '[chips id="sb-human-takeover" options="' . sb_rich_value($human_takeover['dialogflow-human-takeover-confirm'], false) . ',' . sb_rich_value($human_takeover['dialogflow-human-takeover-cancel'], false) . '" message="' . sb_rich_value($human_takeover['dialogflow-human-takeover-message']) . '"]';
                        $message_id = sb_send_message($bot_id, $conversation_id, $human_takeover_message)['id'];
                        array_push($results, ['message' => $human_takeover_message, 'attachments' => [], 'payload' => false, 'id' => $message_id]);
                    }      
                } else if (!$is_human_takeover || (!sb_is_user_online(sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id')) && !$unknow_answer) || !empty($payload['force-message'])) {
                    if (!$bot_message && isset($payload['force-message']) && $i > 0 && isset($messages[$i - 1]['text'])) {
                        $bot_message = $messages[$i - 1]['text']['text'][0];
                    }
                    $bot_message = sb_dialogflow_merge_fields($bot_message, $parameters, $language[0]);
                    $message_id = sb_send_message($bot_id, $conversation_id, $bot_message, $attachments, -1, $response)['id'];
                    array_push($results, ['message' => $bot_message, 'attachments' => $attachments, 'payload' => $payload, 'id' => $message_id]);
                } 
            } else {
                array_push($results, ['message' => sb_dialogflow_merge_fields($bot_message, $parameters, $language[0]), 'attachments' => $attachments, 'payload' => $payload]);
            }  
        }
    }

    if (count($results)) {

        // Return the bot messages list
        $response_success['token'] = $token;
        $response_success['messages'] = $results;
        $response_success['response'] = $response;
        return $response_success;
    } else if (isset($response['error']) && $response['error']['status'] == 'UNAUTHENTICATED') {

        // Reload the function and force it to generate a new token
        global $sb_recursion;
        if ($sb_recursion) {
            $sb_recursion = false;
            return sb_dialogflow_message($conversation_id, $message, -1, $language);
        }
    }

    return ['response' => $response];
}

/*
 * -----------------------------------------------------------
 * INTENTS
 * -----------------------------------------------------------
 *
 * 1. Create an Intent
 * 2. Update an existing Intent
 * 3. Create multiple Intents
 * 4. Delete multiple Intents
 * 5. Return all Intents
 *
 */

function sb_dialogflow_create_intent($training_phrases, $bot_responses, $language = '', $conversation_id = false) {
    global $sb_entity_types;
    $training_phrases_api = [];
    $cx = sb_get_setting('dialogflow-edition') == 'cx';
    $sb_entity_types = $cx ? ($sb_entity_types ? $sb_entity_types : sb_isset(sb_dialogflow_curl('/entityTypes', '', false, 'GET'), 'entityTypes', [])) : false;
    $parameters = [];

    // Training phrases and parameters
    if (is_string($bot_responses)) {
        $bot_responses = [['text' => ['text' => $bot_responses]]];
    }
    for ($i = 0; $i < count($training_phrases); $i++) {
        if (is_string($training_phrases[$i])) {
            $parts = ['text' => $training_phrases[$i]];
        } else {
            $parts = $training_phrases[$i]['parts'];
            for ($j = 0; $j < count($parts); $j++) {
                if (empty($parts[$j]['text'])) {
                    array_splice($parts, $j, 1);
                } else if ($cx && isset($parts[$j]['entityType'])) {
                    for ($y = 0; $y < count($sb_entity_types); $y++) {
                    	if ($sb_entity_types[$y]['displayName'] == $parts[$j]['alias']) { 
                            $id = 'parameter_id_' . $y;
                            $parts[$j]['parameterId'] = $id;
                            $new = true;
                            for ($k = 0; $k < count($parameters); $k++) {
                                if ($parameters[$k]['id'] == $id) {
                                    $new = false;
                                    break;
                                }
                            }
                            if ($new) {
                                array_push($parameters, ['id' => $id, 'entityType' => $sb_entity_types[$y]['name']]);
                            }
                            break;
                        }
                    }
                }
            }
        } 
        array_push($training_phrases_api, ['type' => 'TYPE_UNSPECIFIED', 'parts' => $parts, 'repeatCount' => 1]);
    }
    
    // Intent name
    $name = sb_isset($training_phrases_api[0]['parts'], 'text');
    if (!$name) {
        $parts = $training_phrases_api[0]['parts'];
        for ($i = 0; $i < count($parts); $i++) {
            $name .= $parts[$i]['text'];
        }
    }

    // Create the Intent
    $query = ['displayName' => sb_string_slug(strlen($name) > 100 ? substr($name, 0, 99) : $name), 'priority' => 500000, 'webhookState' => 'WEBHOOK_STATE_UNSPECIFIED', 'trainingPhrases' => $training_phrases_api, 'messages' => $bot_responses];
    if ($parameters) $query['parameters'] = $parameters;
    $response = sb_dialogflow_curl('/agent/intents', $query, $language);
    if ($cx) {
        $flow_name = '00000000-0000-0000-0000-000000000000';
        if ($conversation_id) {
            $messages = sb_db_get('SELECT payload FROM sb_messages WHERE conversation_id = ' . sb_db_escape($conversation_id, true) . ' AND payload <> "" ORDER BY id DESC');
            for ($i = 0; $i < count($messages); $i++) {
            	$payload = json_decode($messages['payload'], true);
                if (isset($payload['queryResult']) && isset($payload['queryResult']['currentPage'])) {
                    $flow_name = $payload['queryResult']['currentPage'];
                    $flow_name = substr($flow_name, strpos($flow_name, '/flows/') + 7);
                    if (strpos($flow_name, '/')) $flow_name = substr($flow_name, 0, strpos($flow_name, '/'));
                    break;
                }
            }
        }
        $flow = sb_dialogflow_curl('/flows/' . $flow_name, '', $language, 'GET');
        array_push($flow['transitionRoutes'], ['intent' => $response['name'], 'triggerFulfillment' => ['messages' => $bot_responses]]);
        $response = sb_dialogflow_curl('/flows/' . $flow_name . '?updateMask=transitionRoutes', $flow, $language, 'PATCH');
    }
    if (isset($response['displayName'])) {
        return true;
    }
    return $response;
}

function sb_dialogflow_update_intent($intent_name, $training_phrases, $language = '') {
    $pos = strpos($intent_name, '/intents/');
    $intent_name = $pos ? substr($intent_name, $pos + 9) : $intent_name;
    $intent = sb_dialogflow_get_intents($intent_name, $language);
    if (!isset($intent['trainingPhrases'])) $intent['trainingPhrases'] = [];
    for ($i = 0; $i < count($training_phrases); $i++) {
        array_push($intent['trainingPhrases'], ['type' => 'TYPE_UNSPECIFIED', 'parts' => ['text' => $training_phrases[$i]], 'repeatCount' => 1]);
    }
    return isset(sb_dialogflow_curl('/agent/intents/' . $intent_name . '?updateMask=trainingPhrases', $intent, $language, 'PATCH')['name']);
}

function sb_dialogflow_batch_intents($intents, $language = '') {
    if (sb_get_setting('dialogflow-edition') == 'cx') {
        $response = [];
        for ($i = 0; $i < count($intents); $i++) {
            array_push($response, sb_dialogflow_create_intent($intents[$i]->data['trainingPhrases'], $intents[$i]->data['messages'], $language));
        }
        return $response;
    } else {
        $intents_array = [];
        for ($i = 0; $i < count($intents); $i++) {
            array_push($intents_array, $intents[$i]->data());
        }
        $query = ['intentBatchInline' => ['intents' => $intents_array], 'intentView' => 'INTENT_VIEW_UNSPECIFIED'];
        if (!empty($language)) $query['languageCode'] = $language;
        return sb_dialogflow_curl('/agent/intents:batchUpdate', $query);
    }
}

function sb_dialogflow_batch_intents_delete($intents) {
    return sb_dialogflow_curl('/agent/intents:batchDelete', ['intents' => $intents]);
}

function sb_dialogflow_get_intents($intent_name = false, $language = '') {
    $next_page_token = true;
    $paginatad_items = [];
    $intents = [];
    while ($next_page_token) {
        $items = sb_dialogflow_curl($intent_name ? ('/agent/intents/' . $intent_name . '?intentView=INTENT_VIEW_FULL') : ('/agent/intents?pageSize=1000&intentView=INTENT_VIEW_FULL' . ($next_page_token !== true && $next_page_token !== false ? ('&pageToken=' . $next_page_token) : '')), '', $language, 'GET');
        if ($intent_name) return $items;
        $next_page_token = sb_isset($items, 'nextPageToken');
        if (sb_is_error($next_page_token)) die($next_page_token);
        array_push($paginatad_items, sb_isset($items, 'intents'));
    }
    for ($i = 0; $i < count($paginatad_items); $i++) {
        $items = $paginatad_items[$i];
        if ($items) {
            for ($j = 0; $j < count($items); $j++) {
                if (!empty($items[$j])) array_push($intents, $items[$j]);
            }
        }
    }
    return $intents;
}

/*
 * -----------------------------------------------------------
 * ENTITIES
 * -----------------------------------------------------------
 *
 * Create, get, update, delete a Dialogflow entities
 *
 */

function sb_dialogflow_create_entity($entity_name, $values, $language = '') {
    $response = sb_dialogflow_curl('/agent/entityTypes', is_a($values, 'SBDialogflowEntity') ? $values->data() : (new SBDialogflowEntity($entity_name, $values))->data(), $language);
    if (isset($response['displayName'])) {
        return true;
    } else if (isset($response['error']) && sb_isset($response['error'], 'status') == 'FAILED_PRECONDITION') {
        return new SBValidationError('duplicate-dialogflow-entity');
    }
    return $response;
}

function sb_dialogflow_update_entity($entity_id, $values, $entity_name = false, $language = '') {
    $response = sb_dialogflow_curl('/agent/entityTypes/' . $entity_id, is_a($values, 'SBDialogflowEntity') ? $values->data() : (new SBDialogflowEntity($entity_name, $values))->data(), $language, 'PATCH');
    if (isset($response['displayName'])) {
        return true;
    }
    return $response;
}

function sb_dialogflow_get_entity($entity_id = 'all', $language = '') {
    $entities = sb_dialogflow_curl('/agent/entityTypes', '', $language, 'GET');
    if (isset($entities['entityTypes'])) {
        $entities = $entities['entityTypes'];
        if ($entity_id == 'all') {
            return $entities;
        }
        for ($i = 0; $i < count($entities); $i++) {
            if ($entities[$i]['displayName'] == $entity_id) {
                return $entities[$i];
            }
        }
        return new SBValidationError('entity-not-found');
    } else return $entities;
}

/*
 * -----------------------------------------------------------
 * MISCELLANEOUS
 * -----------------------------------------------------------
 *
 * 1. Get a fresh Dialogflow access token
 * 2. Convert the Dialogflow merge fields to the final values
 * 3. Activate a context in the active conversation
 * 4. Return the details of a Dialogflow agent
 * 5. Chinese language sanatization
 * 6. Dialogflow curl
 * 7. Human takeover
 * 8. Check if human takeover is active
 * 9. Execute payloads
 * 10. Add Intents to saved replies
 * 11. Check if unknow answer
 * 
 */

function sb_dialogflow_get_token() {
    $token = sb_get_setting('dialogflow-token');
    if (empty($token)) {
        return new SBError('dialogflow-refresh-token-not-found', 'sb_dialogflow_get_token');
    }
    $response = sb_download('https://board.support/synch/dialogflow.php?refresh-token=' . $token);
    if ($response != 'api-dialogflow-error' && $response != false) {
        $token = json_decode($response, true);
        if (isset($token['access_token'])) {
            return $token['access_token'];
        }
    }
    return new SBError('dialogflow-refresh-token-error', 'sb_dialogflow_get_token', $response);
}

function sb_dialogflow_merge_fields($message, $parameters, $language = '') {
    if (defined('SB_WOOCOMMERCE')) {
        $message = sb_woocommerce_merge_fields($message, $parameters, $language);
    }
    return $message;
}

function sb_dialogflow_set_active_context($context_name, $parameters = [], $life_span = 5, $token = false, $user_id = false, $language = false) {
    if (!sb_get_setting('dialogflow-active')) return false;
    $project_id = sb_get_setting('dialogflow-project-id');
    $language = $language === false ? (sb_get_setting('dialogflow-multilingual') ? sb_get_user_language($user_id) : '') : $language;
    $session_id = $user_id === false ? sb_isset(sb_get_active_user(), 'id', 'sb') : $user_id;
    $parameters = empty($parameters) ? '' : ', "parameters": ' . (is_string($parameters) ? $parameters : json_encode($parameters));
    $query = '{ "queryInput": { "text": { "languageCode": "' . (empty($language) ? 'en' : $language) . '", "text": "sb-trigger-context" }}, "queryParams": { "contexts": [{ "name": "projects/' . $project_id . '/agent/sessions/' . $session_id . '/contexts/' . $context_name . '", "lifespanCount": ' . $life_span . $parameters . ' }] }}';
    return sb_dialogflow_curl('/agent/sessions/' . $session_id . ':detectIntent', $query, false, 'POST', $token);
}

function sb_dialogflow_get_agent() {
    return sb_dialogflow_curl('/agent', '', '', 'GET');
}

function sb_dialogflow_language_code($language) {
    return $language == 'zh' ? 'zh-CN' : $language;
}

function sb_dialogflow_curl($url_part, $query = '', $language = false, $type = 'POST', $token = false, $project_id = false) {

    // Project ID
    if (!$project_id) {
        $project_id = trim(sb_get_setting('dialogflow-project-id'));
        if (empty($project_id)) {
            return new SBError('project-id-not-found', 'sb_dialogflow_curl');
        }
    }

    // Retrive token
    $token = empty($token) || $token == -1 ? sb_dialogflow_get_token() : $token;
    if (sb_is_error($token)) {
        return new SBError('token-error', 'sb_dialogflow_curl');
    }

    // Language
    if (!empty($language)) {
        $language = (strpos($url_part, '?') ? '&' : '?') . 'languageCode=' . $language;
    }

    // Query
    if (!is_string($query)) {
        $query = json_encode($query);
    }

    // Edition and version
    $edition = sb_get_setting('dialogflow-edition', 'es');
    $version = 'v2beta1/projects/';
    $cx = $edition == 'cx';
    if ($cx) {
        $version = 'v3beta1/';
        $url_part = str_replace('/agent/', '/', $url_part);
    }

    // Location
    $location = sb_get_setting('dialogflow-location', '');
    $location_session = $location && !$cx ? '/locations/' . substr($location, 0, -1) : '';

    // Send
    $url = 'https://' . $location . 'dialogflow.googleapis.com/' . $version . $project_id . $location_session . $url_part . $language;
    $response = sb_curl($url, $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], $type);
    return $type == 'GET' ? json_decode($response, true) : $response;
}

function sb_dialogflow_human_takeover($conversation_id, $auto_messages = false) {
    $human_takeover = sb_get_setting('dialogflow-human-takeover');
    $conversation_id = sb_db_escape($conversation_id, true);
    $bot_id = sb_get_bot_id();
    $data = sb_db_get('SELECT A.id AS `user_id`, A.email, A.first_name, A.last_name, A.profile_image, B.agent_id, B.department, B.status_code FROM sb_users A, sb_conversations B WHERE A.id = B.user_id AND B.id = ' . $conversation_id);
    $user_id = $data['user_id'];
    $messages = sb_db_get('SELECT A.user_id, A.message, A.attachments, A.creation_time, B.first_name, B.last_name, B.profile_image, B.user_type FROM sb_messages A, sb_users B WHERE A.conversation_id = ' . $conversation_id . ' AND A.user_id = B.id AND A.message <> "' . sb_($human_takeover['dialogflow-human-takeover-confirm']) . '" AND A.message NOT LIKE "%sb-human-takeover%" AND A.payload NOT LIKE "%human-takeover%" ORDER BY A.id ASC', false);
    $count = count($messages);
    $last_message = $messages[$count - 1]['message']; 
    $response = [];
    sb_send_message($bot_id, $conversation_id, '', [], false, ['human-takeover' => true]);
    
    // Human takeover message and status code
    $message = sb_($human_takeover['dialogflow-human-takeover-message-confirmation']);
    if (!empty($message)) {
        $message_id = sb_send_message($bot_id, $conversation_id, $message, [], 2, ['human-takeover-message-confirmation' => true, 'preview' => $last_message])['id'];
        array_push($response, ['message' => $message, 'id' => $message_id]);
    } else if ($data['status_code'] != 2) sb_update_conversation_status($conversation_id, 2);


    // Auto messages
    if ($auto_messages) {
        $auto_messages = ['offline', 'follow_up', 'subscribe'];
        for ($i = 0; $i < count($auto_messages); $i++) {
            $auto_message = $i == 0 || empty($data['email']) ? sb_execute_bot_message($auto_messages[$i], $conversation_id, $last_message) : false;
            if ($auto_message) array_push($response, $auto_message);
        }
    }

    // Notifications
    $code = '<div style="max-width:600px;clear:both;">';
    for ($i = ($count - 1); $i > -1; $i--) {
        $message = $messages[$i];
        $css = sb_is_agent($messages[$i]) ? ['left', '0 50px 20px 0', '#F0F0F0'] : ['right', '0 0 20px 50px', '#E6F2FC'];
        $attachments = sb_isset($message, 'attachments', []);
        $code .= '<div style="float:' . $css[0] . ';text-align:' . $css[0] . ';clear:both;margin:' . $css[1] . ';"><span style="background-color:' . $css[2] . ';padding:10px 15px;display:inline-block;border-radius:4px;margin:0;">' . $message['message'] . '</span>';
        if ($attachments) { 
            $code .= '<br>';
            $attachments = json_decode($attachments, true);
            for ($j = 0; $j < count($attachments); $j++) {
                $code .= '<br><a style="color:#626262;text-decoration:underline;" href="' . $attachments[$j][1] . '">' . $attachments[$j][0] . '</a>';
            }
        }
        $code .= '<br><span style="color:rgb(168,168,168);font-size:12px;display:block;margin:10px 0 0 0;">' . $message['first_name'] . ' ' . $message['last_name'] . ' | ' . $message['creation_time'] . '</span></div>';
    }
    $code .= '<div style="clear:both;"></div></div>';
    sb_send_agents_notifications($last_message, str_replace('{T}', sb_get_setting('bot-name', 'Dialogflow'), sb_('This message has been sent because {T} does not know the answer to the user\'s question.')), $conversation_id, false, $data, ['email' => $code]);

    // Slack
    if (defined('SB_SLACK') && sb_get_setting('slack-active')) {
        for ($i = 0; $i < count($messages); $i++) {
        	sb_send_slack_message($user_id, sb_get_user_name($messages[$i]), $messages[$i]['profile_image'], $messages[$i]['message'], sb_isset($messages[$i], 'attachments'), $conversation_id);
        }
    }

    return $response;
}

function sb_dialogflow_is_human_takeover($conversation_id) {
    $name = 'human-takeover-' . $conversation_id;
    if (isset($GLOBALS[$name])) return $GLOBALS[$name];
    $GLOBALS[$name] = sb_db_get('SELECT COUNT(*) AS `count` FROM sb_messages WHERE payload = "{\"human-takeover\":true}" AND conversation_id = ' . sb_db_escape($conversation_id, true) . ' AND creation_time > "' . gmdate('Y-m-d H:i:s', time() - 864000) . '" LIMIT 1')['count'] > 0;
    return $GLOBALS[$name];
}

function sb_dialogflow_payload($payload, $conversation_id, $message = false, $extra = false) {
    if (isset($payload['agent'])) {
        sb_update_conversation_agent($conversation_id, $payload['agent'], $message);
    }
    if (isset($payload['department'])) {
        sb_update_conversation_department($conversation_id, $payload['department'], $message);
    }
    if (isset($payload['human-takeover']) || isset($payload['disable-bot'])) {
        $messages = sb_dialogflow_human_takeover($conversation_id, $extra && isset($extra['source']));
        $source = sb_isset($extra, 'source');
        if ($source) {
            for ($i = 0; $i < count($messages); $i++) {
                $message = $messages[$i]['message'];
                $attachments = sb_isset($messages[$i], 'attachments');
                sb_messaging_platforms_send_message($message, $extra, $messages[$i]['id'], $attachments);
            }
        }
    }
    if (isset($payload['send-email'])) {
        $send_to_active_user = $payload['send-email']['recipient'] == 'active_user';
        sb_email_create($send_to_active_user ? sb_get_active_user_ID() : 'agents', $send_to_active_user ? sb_get_setting('bot-name') : sb_get_user_name(), $send_to_active_user ? sb_get_setting('bot-image') : sb_isset(sb_get_active_user(), 'profile_image'), $payload['send-email']['message'], sb_isset($payload['send-email'], 'attachments'), false, $conversation_id);
    }
    if (isset($payload['redirect']) && $extra) {
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, $payload['redirect']);
        sb_messaging_platforms_send_message($payload['redirect'], $extra, $message_id);
    }
    if (isset($payload['transcript']) && $extra) {
        $transcript_url = sb_transcript($conversation_id);
        $attachments = [[$transcript_url, $transcript_url]];
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, '', $attachments);
        sb_messaging_platforms_send_message($extra['source'] == 'ig' || $extra['source'] == 'fb' ? '' : $transcript_url, $attachments, $message_id);
    }
    if (isset($payload['rating'])) {
        sb_set_rating(['conversation_id' => $conversation_id, 'agent_id' => sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id', sb_get_bot_id()), 'user_id' => sb_get_active_user_ID(), 'message' => '', 'rating' => $payload['rating']]);
    }
}

function sb_dialogflow_saved_replies() {
    $settings = sb_get_settings();
    $saved_replies = sb_get_setting('saved-replies', []);
    $intents = sb_dialogflow_get_intents();
    $count = count($saved_replies);
    for ($i = 0; $i < count($intents); $i++) {
        if (isset($intents[$i]['messages'][0]) && isset($intents[$i]['messages'][0]['text']) && isset($intents[$i]['messages'][0]['text']) && isset($intents[$i]['messages'][0]['text']['text'])) {
            $slug = sb_string_slug($intents[$i]['displayName']);
            $existing = false;
            for ($j = 0; $j < $count; $j++) {
                if ($slug == $saved_replies[$j]['reply-name']) {
                    $existing = true;
                    break;
                }
            }
            if (!$existing) {
                array_push($saved_replies, ['reply-name' => $slug, 'reply-text' => $intents[$i]['messages'][0]['text']['text'][0]]);
            }
        }
    }
    $settings['saved-replies'][0] = $saved_replies;
    return sb_save_settings($settings);
}

function sb_dialogflow_is_unknow($dialogflow_response) {
    $dialogflow_response = sb_isset($dialogflow_response, 'response', $dialogflow_response);
    $query_result = sb_isset($dialogflow_response, 'queryResult', []);
    return sb_isset($query_result, 'action') == 'input.unknown' || (isset($query_result['match']) && $query_result['match']['matchType'] == 'NO_MATCH');
}

/*
 * -----------------------------------------------------------
 * SMART REPLY
 * -----------------------------------------------------------
 *
 * 1. Return the suggestions
 * 2. Update a smart reply conversation with a new message
 * 3. Generate the conversation transcript data for a dataset
 *
 */

function sb_dialogflow_smart_reply($message, $smart_reply_data = false, $language = false, $token = false, $language_detection = false) {
    $suggestions = [];
    $smart_reply_response = false;
    $token = empty($token) ? sb_dialogflow_get_token() : $token;
    $smart_reply = sb_get_multi_setting('dialogflow-smart-reply', 'dialogflow-smart-reply-profile');

    // Bot
    $messages = sb_dialogflow_message(false, $message, $token, $language);
    if (sb_is_error($messages)) return $messages;
    $detected_language_response = false;
    if (!empty($messages['messages']) && !sb_dialogflow_is_unknow($messages['response'])) {
        for ($i = 0; $i < count($messages['messages']); $i++) {
            $value = $messages['messages'][$i]['message'];
            if (!empty($value) && !strpos($value, 'sb-human-takeover')) array_push($suggestions, $value);
        }
    } else if ($language_detection) {
        $detected_language = sb_google_language_detection($message, $token);
        if ($detected_language != $language[0] && !empty($detected_language)) {
            if (in_array($detected_language, sb_isset(sb_dialogflow_get_agent(), 'supportedLanguageCodes', []))) {
                $detected_language_response = $detected_language;
                if (isset($_POST['user_id'])) sb_update_user_value($_POST['user_id'], 'language', $detected_language);
                return sb_dialogflow_smart_reply($message, $smart_reply_data, [$detected_language], $token);
            }
        }
    }

    // Smart Reply
    if (!count($suggestions) && $smart_reply) {
        $query = '{ "textInput": { "text": "' .  str_replace('"', '\"', $message) . '", "languageCode": "' . $language[0] . '" }}';
        $exernal_setting = [];
        if ($smart_reply_data && empty($smart_reply_data['user'])) {
            $exernal_setting = sb_get_external_setting('smart-reply', []);
            $smart_reply_response = sb_isset($exernal_setting, $smart_reply_data['conversation_id']);
        } else {
            $smart_reply_response = $smart_reply_data;
        }
        if (!$smart_reply_response) {
            $query_2 = '{ "conversationProfile": "' . $smart_reply . '" }';
            $project_id = substr($smart_reply, 0, strpos($smart_reply, '/conversationProfiles'));
            $response = sb_curl('https://dialogflow.googleapis.com/v2/' . $project_id . '/conversations', $query_2, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query_2)], 'POST');
            if (isset($response['name'])) {
                $smart_reply_response = ['conversation' => $response['name']];
                for ($i = 0; $i < 2; $i++) {
                    $query_2 = '{ "role": "' . ($i ? 'HUMAN_AGENT' : 'END_USER') . '" }';
                    $smart_reply_response[$i ? 'agent' : 'user'] = sb_isset(sb_curl('https://dialogflow.googleapis.com/v2/' . $response['name'] . '/participants', $query_2, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query_2)], 'POST'), 'name');
                }
                if (isset($smart_reply_data['conversation_id'])) {
                    $exernal_setting[$smart_reply_data['conversation_id']] = $smart_reply_response;
                    sb_save_external_setting('smart-reply', $exernal_setting);
                }
            }
        }
        if (!empty($smart_reply_response['user'])) {
            $response = sb_curl('https://dialogflow.googleapis.com/v2/' . $smart_reply_response['user'] . ':analyzeContent', $query, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], 'POST');
        }
        if (isset($response['humanAgentSuggestionResults'])) {
            $results = $response['humanAgentSuggestionResults'];
            $keys = [['suggestSmartRepliesResponse', 'smartReplyAnswers', 'reply'], ['suggestFaqAnswersResponse', 'faqAnswers', 'answer'], ['suggestArticlesResponse', 'articleAnswers', 'uri']];
            for ($i = 0; $i < count($results); $i++) {
                for ($y = 0; $y < 3; $y++) {
                	if (isset($results[$i][$keys[$y][0]])) {
                        $answers = sb_isset($results[$i][$keys[$y][0]], $keys[$y][1], []);
                        for ($j = 0; $j < count($answers); $j++) {
                            array_push($suggestions, $answers[$j][$keys[$y][2]]);
                        }
                    }
                }
            }
        }
    }

    return ['suggestions' => $suggestions, 'token' => sb_isset($messages, 'token'), 'detected_language' => $detected_language_response, 'smart_reply' => $smart_reply_response];
}

function sb_dialogflow_smart_reply_update($message, $smart_reply_data, $language, $token, $user_type = 'agent') {
    $user = sb_isset($smart_reply_data, $user_type);
    if (empty($user)) {
        $user = sb_isset(sb_isset(sb_get_external_setting('smart-reply', []), $smart_reply_data['conversation_id'], []), $user_type);
    }
    if ($user) {
        $token = empty($token) ? sb_dialogflow_get_token() : $token;
        $query = '{ "textInput": { "text": "' .  str_replace('"', '\"', $message) . '", "languageCode": "' . $language[0] . '" }}';
        return sb_curl('https://dialogflow.googleapis.com/v2/' . $user . ':analyzeContent', $query, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], 'POST');
    }
    return false;
}

function sb_dialogflow_smart_reply_generate_conversations_data() {
    $path = sb_upload_path() . '/conversations-data/';
    if (!file_exists($path)) mkdir($path, 0777, true);
    $conversations = sb_db_get('SELECT id FROM sb_conversations', false);
    for ($i = 0; $i < count($conversations); $i++) {
        $code = '';
        $conversation_id = $conversations[$i]['id'];
        $messages = sb_db_get('SELECT A.message, A.creation_time, B.user_type, B.id FROM sb_messages A, sb_users B WHERE A.user_id = B.id AND B.user_type <> "bot" AND A.conversation_id = ' . $conversation_id . ' ORDER BY A.creation_time ASC', false);
        $count = count($messages);
        if ($count) {
            for ($j = 0; $j < $count; $j++) {
                $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $messages[$j]['creation_time']);
                $code .= '{ "start_timestamp_usec": ' . $datetime->getTimestamp() . ', "text": "' . str_replace('"', '\"', $messages[$j]['message']) . '", "role": " ' . (sb_is_agent($messages[$j]['user_type']) ? 'AGENT' : 'CUSTOMER') . '", "user_id": ' . $messages[$j]['id'] . ' },';
            }
            sb_file($path . 'conversation-' . $conversation_id . '.json', '{"entries": [' . substr($code, 0, -1) . ']}');
        }
    }
    return $path;
}

function sb_dialogflow_knowledge_articles($articles = false, $language = false) {
    $language = $language ? sb_dialogflow_language_code($language) : false;
    if (sb_isset(sb_dialogflow_get_agent(), 'defaultLanguageCode') != 'en') return new SBValidationError('language-not-supported');
    if (!$articles) {
        $articles = sb_get_articles(-1, false, true, false, 'all');
        $articles = $articles[0];
    }    
    if ($articles) {

        // Create articles file
        $faq = [];
        for ($i = 0; $i < count($articles); $i++) {
            $content = strip_tags($articles[$i]['content']);
            if (mb_strlen($content) > 150)  { 
                $content = mb_substr($content, 0, 150);
                $content = mb_substr($content, 0, mb_strrpos($content, ' ') + 1) . '... [button link="#article-' . $articles[$i]['id'] . '" name="' . sb_('Read more') . '" style="link"]';
                $content = str_replace(', ...', '...', $content);
            }
        	array_push($faq, [$articles[$i]['title'], $content]);
        }
        $file_path = sb_upload_path() . '/dialogflow_faq.csv';
        sb_csv($faq, false, 'dialogflow_faq');
        $file = fopen($file_path, 'r');
        $file_bytes = fread($file, filesize($file_path));
        fclose($file);
        unlink($file_path);

        // Create new knowledge if not exist
        $knowledge_base_name = sb_get_external_setting('dialogflow-knowledge', []);
        if (!isset($knowledge_base_name[$language ? $language : 'default'])) {      
            $query = ['displayName' => 'Support Board'];
            if ($language) $query['languageCode'] = $language;
            $name = sb_isset(sb_dialogflow_curl('/knowledgeBases', $query, false, 'POST'), 'name');   
            $name = substr($name, strripos($name, '/') + 1);
            $knowledge_base_name[$language ? $language : 'default'] = $name;
            sb_save_external_setting('dialogflow-knowledge', $knowledge_base_name);
            $knowledge_base_name = $name;
        } else $knowledge_base_name = $knowledge_base_name['default'];

        // Save knowledge in Dialogflow
        $documents = sb_isset(sb_dialogflow_curl('/knowledgeBases/' . $knowledge_base_name . '/documents', '', false, 'GET'), 'documents', []);
        for ($i = 0; $i < count($documents); $i++) {
            $name = $documents[0]['name'];
            $response = sb_dialogflow_curl(substr($name, stripos($name, 'knowledgeBases/') - 1), '', false, 'DELETE');
        }
        $response = sb_dialogflow_curl('/knowledgeBases/' . $knowledge_base_name . '/documents', ['displayName' => 'Support Board', 'mimeType' => 'text/csv', 'knowledgeTypes' => ['FAQ'], 'rawContent' => base64_encode($file_bytes)], false, 'POST');
        if ($response && isset($response['error']) && sb_isset($response['error'], 'status') == 'NOT_FOUND') {
            sb_save_external_setting('dialogflow-knowledge', false);
            return false;
        }
    }
    return true;
}

/*
 * -----------------------------------------------------------
 * GOOGLE
 * -----------------------------------------------------------
 *
 * 1. Detect the language of a string
 * 2. Retrieve the full language name in the desired language
 * 4. Text translation
 * 5. Analyze Entities
 *
 */

function sb_google_language_detection($string, $token = false) {
    if (!strpos(trim($string), ' ')) return false;
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['q' => $string]);
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2/detect', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    return isset($response['data']) ? $response['data']['detections'][0][0]['language'] : false;
}

function sb_google_get_language_name($target_language_code, $token = false) {
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['target' => $target_language_code]);
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2/languages', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    if (isset($response['data'])) {
        $languages = $response['data']['languages'];
        for ($i = 0; $i < count($languages); $i++) {
            if ($languages[$i]['language'] == $target_language_code) {
                return $languages[$i]['name'];
            }
        }
    }
    return $response;
}

function sb_google_translate($strings, $language_code, $token = false) {
    $translations = [];
    $token = $token ? $token : sb_dialogflow_get_token();
    $chunks = array_chunk($strings, 125);
    for ($j = 0; $j < count($chunks); $j++) {
        $strings = $chunks[$j];
    	for ($i = 0; $i < count($strings); $i++) {
            $strings[$i] = nl2br($strings[$i], false);
        }
        $query = json_encode(['q' => $strings, 'target' => $language_code, 'format' => 'text']);
        $response = sb_curl('https://translation.googleapis.com/language/translate/v2', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]); 
        if ($response && isset($response['data'])) {
            $translations_partial = sb_isset($response['data'], 'translations', []);
            for ($i = 0; $i < count($translations_partial); $i++) {
                array_push($translations, str_replace('<br>', PHP_EOL, $translations_partial[$i]));
            }
        }
    }
    return [$translations ? $translations : $response, $token];
}

function sb_google_translate_auto($message, $user_id) {
    if (is_numeric($user_id)) {
        $recipient_language = sb_get_user_language($user_id);
        if (sb_get_setting('google-translation')) {
            $active_user_language = sb_get_user_language(sb_get_active_user_ID());
            if ($recipient_language != $active_user_language) {
                $translation = sb_google_translate([$message], $recipient_language)[0];
                if (count($translation)) return $translation[0]['translatedText'];
            }
        }
    }
    return $message;
}

function sb_google_language_detection_update_user($string, $user_id = false, $token = false) {
    $user_id = $user_id ? $user_id : sb_get_active_user_ID();
    $detected_language = sb_google_language_detection($string, $token);
    $language = sb_get_user_language($user_id);
    if ($detected_language != $language[0] && !empty($detected_language)) {
        return sb_update_user_value($user_id, 'language', $detected_language);
    }
    return false;
}

function sb_google_language_detection_get_user_extra($message) {
    if ($message && sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')) {
        return [sb_google_language_detection($message), 'Language'];
    }
}

function sb_google_analyze_entities($string, $language = false, $token = false) {
    if (!strpos(trim($string), ' ')) return false;
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = ['document' => ['type' => 'PLAIN_TEXT', 'content' => ucwords($string)]];
    if ($language) $query['document']['language'] = $language;
    $query = json_encode($query);
    return sb_curl('https://language.googleapis.com/v1/documents:analyzeEntities', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
}

/*
 * ----------------------------------------------------------
 * DIALOGFLOW INTENT BOX
 * ----------------------------------------------------------
 *
 * Display the form to create a new intent for Dialogflow
 *
 */

function sb_dialogflow_intent_box() { ?>
<div class="sb-lightbox sb-dialogflow-intent-box">
    <div class="sb-info"></div>
    <div class="sb-top-bar">
        <div>Dialogflow Intent</div>
        <div>
            <a class="sb-send sb-btn sb-icon">
                <i class="sb-icon-check"></i><?php sb_e('Send') ?> Intent
            </a>
            <a class="sb-close sb-btn-icon">
                <i class="sb-icon-close"></i>
            </a>
        </div>
    </div>
    <div class="sb-main sb-scroll-area">
        <div class="sb-title sb-intent-add">
            <?php sb_e('Add user expressions') ?>
            <i data-value="add" data-sb-tooltip="<?php sb_e('Add expression') ?>" class="sb-btn-icon sb-icon-plus"></i>
            <i data-value="previous" class="sb-btn-icon sb-icon-arrow-up"></i>
            <i data-value="next" class="sb-btn-icon sb-icon-arrow-down"></i>
        </div>
        <div class="sb-input-setting sb-type-text sb-first">
            <input type="text" />
        </div>
        <div class="sb-title sb-bot-response">
            <?php sb_e('Response from the bot') ?>
        </div>
        <div class="sb-input-setting sb-type-textarea sb-bot-response">
            <textarea></textarea>
        </div>
        <div class="sb-title">
            <?php sb_e('Language') ?>
        </div>
        <?php echo sb_dialogflow_languages_list() ?>
        <div class="sb-title sb-title-search">
            <?php sb_e('Intent') ?>
            <div class="sb-search-btn">
                <i class="sb-icon sb-icon-search"></i>
                <input type="text" autocomplete="false" placeholder="<?php sb_e('Search for Intents...') ?>">
            </div>
            <i id="sb-intent-preview" data-sb-tooltip="<?php sb_e('Preview') ?>" class="sb-icon-help"></i>
        </div>
        <div class="sb-input-setting sb-type-select">
            <select id="sb-intents-select">
                <option value=""><?php sb_e('New Intent') ?></option>
            </select>
        </div>
    </div>
</div>
<?php } ?>