<?php

namespace Inbenta\WatsonConnector;

use \Exception;

use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\WatsonConnector\InbentaSearch\WatsonSearch;
use \Firebase\JWT\JWT;

class WatsonConnector extends ChatbotConnector
{
    private $messages;

    public function __construct($appPath)
    {
        // Initialize and configure specific components for Watson
        try {
            parent::__construct($appPath);

            //Validity check
            $this->validityCheck();

            // Initialize base components
            $externalId = $this->getExternalIdFromRequest();
            $this->session = new SessionManager($externalId);

            $this->search = new WatsonSearch($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $this->lang);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Get the external id from request
     *
     * @return String 
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a Watson message request
        $externalId = $this->buildExternalIdFromRequest();
        if (is_null($externalId)) {
            session_write_close();
            throw new Exception('Invalid request!');
        }
        return $externalId;
    }

    /**
     * Create the external id
     */
    protected function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));

        if (!$request) {
            $request = (object)$_GET;
        }
        if (isset($request->payload->context->global->system->user_id)) {
            return str_replace("anonymous_", "", $request->payload->context->global->system->user_id);
        } else {
            return null;
        }
    }

    /**
     * Validate if the request is correct
     */
    protected function validityCheck()
    {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            throw new Exception('Invalid request, no Watson JWT');
        }

        $jwt = $headers['Authorization'];
        try {
            $decoded = JWT::decode($jwt, $this->conf->get('configuration.token'), array('HS256'));
            if ($decoded) {
                return true;
            }
            echo json_encode(["error" => "Incorrect Secret."]);
            die;
        } catch (Exception $e) {
            echo json_encode(["error" => "Incorrect Secret."]);
            die;
        }
    }

    /**
     * Validate the threshold
     */
    protected function inbentaThreshold($request)
    {
        if ($this->session->get('expecting_reply', false)) {
            $this->session->set('expecting_reply', false);
            return true;
        }
        if (isset($request->payload->output->debug->turn_events) && count($request->payload->output->debug->turn_events) > 0) {
            foreach ($request->payload->output->debug->turn_events as $turn_event) {
                if (isset($turn_event->source->action)) {
                    if ($turn_event->source->action == "anything_else") {
                        return true;
                    } elseif ($turn_event->source->action == "welcome") {
                        return false;
                    }
                }
            }
        }
        if (isset($request->payload->output->debug->nodes_visited) && count($request->payload->output->debug->nodes_visited) > 0) {
            foreach ($request->payload->output->debug->nodes_visited as $node_visited) {
                if ($node_visited->title == "Anything else") {
                    return true;
                } elseif ($node_visited->title == "Welcome") {
                    return false;
                }
            }
        }
        if (isset($request->payload->output->intents) && count($request->payload->output->intents) > 0) {
            foreach ($request->payload->output->intents as $intent) {
                if ($intent->confidence >= $this->conf->get('configuration.threshold')) {
                    return false;
                }
            }
        }
        if (isset($request->payload->output->entities) && count($request->payload->output->entities) > 0) {
            foreach ($request->payload->output->entities as $entity) {
                if ($entity->confidence >= $this->conf->get('configuration.threshold')) {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Handle input
     */
    public function handleInput($request)
    {
        $this->session->set('user_input', $request);
        return $request;
    }

    /**
     * Handle output
     */
    public function handleOutput($request)
    {
        // Translate the request into a ChatbotAPI request
        $lastMessage = $this->session->get('user_input');

        if ($this->inbentaThreshold($request) && !empty(trim($lastMessage->payload->input->text))) {

            $externalRequest = trim($lastMessage->payload->input->text);
            if ($externalRequest === "") return $request;

            $this->messages = $this->search->searchProcessQuestion($externalRequest);

            // Send all messages
            return $this->sendMessages($request);
        }
        return $request;
    }

    /**
     * Overwritten
     */
    public function handleRequest()
    {
        try {
            $request = json_decode(file_get_contents('php://input'));

            if (isset($request->payload->input)) {
                return $this->handleInput($request);
            } elseif (isset($request->payload->output)) {
                return $this->handleOutput($request);
            } else {
                throw new Exception("Invalid Request (neither input nor output)");
            }
        } catch (Exception $e) {
            echo json_encode(["error" => "Error calling Federated Search. Check that your authentication matches..."]);
            return $request;
        }
    }

    /**
     * Print the message that Watson can process
     */
    public function sendMessages($request)
    {
        if (isset($this->messages["results"]) && count($this->messages["results"]) !== 0) {
            $request->payload->output->generic = [$this->messages];
        }
        return $request;
    }
}
