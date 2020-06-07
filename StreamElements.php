<?php

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * Created by PhpStorm.
 * User: Lx
 * Date: 19.06.2018
 * Time: 18:00
 */
class StreamElements
{
  /**
   * URL to SE API
   * @var string
   */
  protected $url = 'https://api.streamelements.com/kappa/v2';

  /**
   * oAuth2 handler
   * @var string
   */
  protected $provider;

  /**
   * Auth type
   * @var string
   */
  protected $auth;

  /**
   * Token
   * @var string
   */
  protected $token;

  /**
   * API limits
   * @var string
   */
  protected $apiLimits;
  /**
   * CURL Options array
   * @var array
   */
  protected $options;
  /**
   * @var \GuzzleHttp\Client
   */
  protected $api;
  /**
   *
   * @var string
   */
  public $channelName;
  /**
   * Profile information received on handshake
   * @var object
   */
  public $profile;
  /**
   * Channel id received on handshake
   * @var int
   */
  public $channelId;
  /**
   * bot data
   * @var array
   */
  public $botInfo;

  /**
   * StreamElements constructor.
   * @param $token - OAuth/JWT Token
   * @param $auth - Authorization method (Bearer,OAuth)
   * @return boolean
   * @throws Exception
   */
  public function __construct($token, $auth)
  {
    $this->api = new GuzzleHttp\Client();
    $this->auth = $auth;
    $this->options = array(
      "headers" => array(
        "Accept" => "application/json",
        "Authorization" => $auth . " " . $token
      ),
      "debug" => false,
      "verify" => false);
    if (!$this->getInfo()) {
      throw new Exception("Invalid credentials");
    }
  }

  /**
   *
   */
  public function __destruct()
  {}

  /**
   * @param $method
   * @param $endpoint
   * @param array $params
   * @param bool $isUpload
   * @return bool|mixed
   */
  protected function sendRequest($method, $endpoint, $params = array(), $isUpload = false)
  {
    if (!is_null($this->apiLimits) && is_array($this->apiLimits['global']))
    {
        if ($this->apiLimits['global']['reset'] > new DateTime() && !$this->apiLimits['global']['remaining']) return false;
    }

    $url = $this->url . '/' . $endpoint;
    $options = $this->options;
    if (is_array($params))
    {
      $options['json'] = $params;
    }
    if ($isUpload)
    {
      $options['multipart'][] = $params;
      unset($options['json']);
    }

    $res = array();
    try
    {
      if ($method == 'GET')
      {
        $res = $this->api->get($url, $this->options);
      }
      else if ($method == 'POST')
      {
        $res = $this->api->post($url, $options);

      }
      else if ($method == 'PUT')
      {
        $res = $this->api->put($url, $options);
      }
      else if ($method == 'DELETE')
      {
        $res = $this->api->delete($url, $options);
      }
      $headers = $res->getHeaders();
      $scope = $headers['x-ratelimit-bucket'][0];
      $this->apiLimits[$scope] = [
        'limit' => $headers['x-ratelimit-limit'][0],
        'remaining' => $headers['x-ratelimit-remaining'][0],
        'reset' => new DateTime()
      ];
      $this->apiLimits[$scope]['reset']->setTimestamp((int)($headers['x-ratelimit-reset'][0] / 1000));

    }
    catch (GuzzleHttp\Exception\ClientException $e)
    {
      if ($e->hasResponse())
      {
        return (json_decode($e->getResponse()->getBody()->getContents(), 1));
      }
      return false;
    }
    //var_dump($res);
    //die();
    $response = json_decode($res->getBody()->getContents(), true);
    return $response;
  }

  /**
   * @return bool
   */
  public function getInfo()
  {
    $endpoint = 'channels/me';
    try
    {
      $res = $this->sendRequest('GET', $endpoint);
    }
    catch (Exception $e)
    {
      echo($e->getMessage());
      $this->__destruct();
      return false;
    }
    $this->profile = $res;
    if (!isset($this->profile["username"]) || !isset($this->profile["_id"])) return false;
    $this->channelName = $this->profile["username"];
    $this->channelId = $this->profile["_id"];
    return true;
  }

// BOT

  /**
   * Gather bot information
   * @return bool|mixed
   */
  public function botInit()
  {
    $url = 'bot/' . $this->channelId;
    $res = $this->sendRequest('GET', $url);
    $this->botInfo = $res['bot'];
    return $res;
  }

  /**
   * Sends a message to channel as bot
   * @param $message
   * @return bool|mixed
   */
  public function botSay($message)
  {

    if (!$this->botInfo) $this->botInit();
    if (!$this->botInfo['joined']) return false;
    if ($this->botInfo['muted']) return false;

    $url = 'bot/' . $this->channelId . '/say';
    $params = array('message' => $message);

    $res = $this->sendRequest('POST', $url, $params);

    return $res;
  }

// CONTEST

  /**
   * Get list of contests
   * @return bool|mixed
   */
  public function contestsList()
  {
    $url = 'contests/' . $this->channelId;

    $res = $this->sendRequest('GET', $url);

    return $res;
  }

  /**
   * Creates new contest
   * @param bool $botResponses
   * @param string $title
   * @param int $minBet
   * @param int $maxBet
   * @param int $duration
   * @param array $options
   * @return bool|mixed
   */
  public function contestCreate($botResponses, $title, $minBet, $maxBet, $duration, $options)
  {
    /*
    $options = array(
      array(
        'title' => 'Option 1',
        'command' => 'option_1'
      ),
      array(
       'title' => 'Option 2',
       'command' => 'option_2'
      )
    );
    */
    $url = 'contests/' . $this->channelId;
    $params = array(
      'botResponses' => $botResponses,
      'title' => $title,
      'minBet' => $minBet,
      'maxBet' => $maxBet,
      'duration' => $duration,
      'options' => $options
    );

    $res = $this->sendRequest('POST', $url, $params);

    return $res;
  }

  /**
   * Starts created contest
   * @param string $contestId
   * @return bool|mixed
   */
  public function contestStart($contestId)
  {
    $url = 'contests/' . $this->channelId . '/' . $contestId . '/start';

    $res = $this->sendRequest('PUT', $url);

    return $res;
  }

  /**
   * Closes running contest
   * @param string $contestId
   * @return bool|mixed
   */
  public function contestClose($contestId)
  {
    $url = 'contests/' . $this->channelId . '/' . $contestId . '/close';

    $res = $this->sendRequest('DELETE', $url);

    return $res;
  }

  /**
   * Draws winning option of contest
   * @param string $contestId
   * @param string $winnerId
   * @return bool|mixed
   */
  public function contestDraw($contestId, $winnerId)
  {
    $url = 'contests/' . $this->channelId . '/' . $contestId . '/winner';
    $params = array('winnerId' => $winnerId);

    $res = $this->sendRequest('PUT', $url, $params);

    return $res;
  }

  /**
   * Refunds contest
   * @param string $contestId
   * @return bool|mixed
   */
  public function contestRefund($contestId)
  {
    $url = 'contests/' . $this->channelId . '/' . $contestId . '/refund';

    $res = $this->sendRequest('DELETE', $url);

    return $res;
  }
}
