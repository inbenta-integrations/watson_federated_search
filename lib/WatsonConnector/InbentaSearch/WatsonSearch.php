<?php

namespace Inbenta\WatsonConnector\InbentaSearch;

use GuzzleHttp\Client as Guzzle;
use \Firebase\JWT\JWT;
use Exception;

class WatsonSearch
{
    protected $apiBase = "https://api.inbenta.io";
    protected $apiVersion = "v1";
    protected $authUrl = '';
    protected $refreshTokenUrl = '';
    protected $searchUrl = '';
    protected $apiKey = '';
    protected $secret = '';
    protected $accessToken = '';
    protected $session;
    protected $lang;

    public function __construct($key = null, $secret = null, $session, $lang)
    {
        $this->authUrl = $this->apiBase . "/" . $this->apiVersion . "/auth";
        $this->refreshTokenUrl = $this->apiBase . "/" . $this->apiVersion . "/refreshToken";

        $this->apiKey = $key;
        $this->secret = $secret;
        $this->session = $session;
        $this->lang = $lang;

        if ($this->session->get('searchUrl', '') !== '') {
            $this->searchUrl = $this->session->get('searchUrl');
        }
        if ($this->session->get('accessTokenSearch', '') !== '') {
            $this->accessToken = $this->session->get('accessTokenSearch');
            $this->validateAccessToken();
        }
        if (($this->accessToken === '' && $this->searchUrl === '')) {
            $this->makeAuth();
        }
    }

    /**
     * Validate if the access token is still valid
     */
    protected function validateAccessToken()
    {
        $isValid = false;
        if ($this->accessToken !== '' && !is_null($this->accessToken)) {
            $jwt = $this->accessToken;
            $elements = explode('.', $jwt);
            $bodyb64 = isset($elements[1]) ? $elements[1] : '';
            if ($bodyb64 !== '') {
                $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));
                if (isset($payload->exp) && $payload->exp > 0) {
                    if ($payload->exp - 5 > time()) {
                        $isValid = true;
                    } else {
                        //refresh token
                        $isValid = $this->refreshToken();
                    }
                }
            }
        }
        if (!$isValid) {
            $this->accessToken = '';
            $this->knowledgeUrl = '';
        }
    }

    /**
     * Make the API request
     * @param string $url
     * @param string $method
     * @param array $params
     * @param array $headers
     * @param string $dataResponse
     */
    private function apiRequest(string $url, string $method, array $params, array $extraHeaders = [], string $dataResponse = "")
    {
        $response = null;

        $client = new Guzzle();

        $headers = ['x-inbenta-key' => $this->apiKey];
        if (count($extraHeaders) > 0) {
            $headers = array_merge($headers, $extraHeaders);
        }
        $clientParams = ['headers' => $headers];

        if ($method !== 'get') {
            $clientParams['body'] = json_encode($params);
        } else {
            $url .= '?' . http_build_query($params);
        }

        $serverOutput = $client->$method($url, $clientParams);

        if (method_exists($serverOutput, 'getBody')) {
            $responseBody = $serverOutput->getBody();

            if (method_exists($responseBody, 'getContents')) {
                $result = json_decode($responseBody->getContents());

                if ($dataResponse == "") {
                    $response = $result;
                } else if (isset($result->$dataResponse)) {
                    $response = $result->$dataResponse;
                }
            }
        }
        return $response;
    }

    /**
     * Make the authorizationn on the instance
     */
    private function makeAuth()
    {
        if ($this->authUrl !== "" && $this->apiKey !== "" && $this->secret !== "") {
            $params = [
                "secret" => $this->secret
            ];

            $response = $this->apiRequest($this->authUrl, "post", $params);

            $this->accessToken = isset($response->accessToken) ? $response->accessToken : null;
            $this->searchUrl = isset($response->apis) ? $response->apis->search . "/" . $this->apiVersion : null;

            $this->session->set('accessTokenSearch', $this->accessToken);
            $this->session->set('searchUrl', $this->searchUrl);
        }
    }

    /**
     * Refresh the token
     * @return bool
     */
    private function refreshToken()
    {
        if ($this->refreshTokenUrl !== "" && $this->apiKey !== "" && $this->accessToken !== "") {
            $headers = ['Authorization' => 'Bearer ' . $this->accessToken];
            try {
                $response = $this->apiRequest($this->refreshTokenUrl, "post", [], $headers);
                $this->accessToken = isset($response->accessToken) ? $response->accessToken : '';
                if ($this->accessToken !== '') {
                    $this->session->set('accessTokenSearch', $this->accessToken);
                    return true;
                }
            } catch (Exception $e) {
                $this->makeAuth();
                return true;
            }
        }
        return false;
    }

    /**
     * Process the search result
     */
    private function searchProcessResult($result)
    {
        $title = $result->highlightedTitle;
        $attributes = $result->attributes;
        $urls = isset($attributes->URL) ? $attributes->URL : [];

        $dynabs = isset($attributes->BEST_DYNABS) ? $attributes->BEST_DYNABS : [];
        $dynabs_string = "";
        foreach ($dynabs as $dynab) {
            $dynabs_string .= $dynab . "\n";
        }

        return [
            'url' => $urls[0],
            'highlight' => [
                'title' => [$title],
                'body' => [$dynabs_string]
            ]
        ];
    }

    /**
     * Execute the search
     * @param string $userQuestion
     */
    public function searchProcessQuestion(string $userQuestion)
    {
        $payload = ["query" => $userQuestion];
        $final_results = [];

        $headers = ['Authorization' => 'Bearer ' . $this->accessToken];
        $results = $this->apiRequest($this->searchUrl . "/federated-search", "post", $payload, $headers, "results");

        if (!is_null($results)) {
            foreach ($results as $result) {
                $final_result = $this->searchProcessResult($result);
                if ($final_result) $final_results[] = $final_result;
            }
        } else {
            $final_results = [];
        }

        return [
            "response_type" => "search",
            "header"         => $this->lang->translate('search-results'),
            "results"        => $final_results
        ];
    }
}
