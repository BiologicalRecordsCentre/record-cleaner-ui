<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\ApiHelper.
 */

namespace Drupal\record_cleaner\Service;

use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class RecordCleanerApiException extends \Exception {}

/**
 *
 */
class ApiHelper {
  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  protected $config;
  protected $client;

  public function __construct(
    protected LoggerChannelInterface $logger,
    ConfigFactoryInterface $configFactory,
    ClientFactory $clientFactory
  )
  {
    $this->config = $configFactory->get('record_cleaner.settings');
    $service_url = $this->config->get('record_cleaner.service_url');
    $this->client = $clientFactory->fromOptions(['base_uri' => $service_url]);
  }

  public function token() {
    $username = $this->config->get('record_cleaner.username');
    $password = $this->config->get('record_cleaner.password');

    $data = [
      'username' => $username,
      'password' => $password,
      'grant_type' => '',
      'scope' => '',
      'client_id' => '',
      'client_secret' => '',
    ];
    $options = [
      'form_params' => $data,
    ];

    $request = $this->client->post('token', $options);
    $json = $request->getBody()->getContents();
    $response = json_decode($json);
    return $response->access_token;
  }

  public function request($method, $path = '/', $options = [], $auth = FALSE) {
    try {
      if ($auth) {
        $token = $this->token();
        $options['headers']['Authorization'] = 'Bearer ' . $token;
      }
      $response = $this->client->request($method, $path, $options);
      $json = $response->getBody()->getContents();
      return $json;
    }
    catch (ConnectException $e) {
      $msg = $e->getMessage();
      $this->logger->error($msg);
      $msg = $this->t("Could not connect to service. Please try again later.");
      throw new RecordCleanerApiException($msg);
    }
    catch (RequestException $e) {
      $msg = $e->getResponse()->getBody()->getContents();
      $this->logger->error($msg);
      throw new RecordCleanerApiException($msg);
    }

  }
  public function status() {
    try {
      $json = $this->request('GET');
      return json_encode(json_decode($json), JSON_PRETTY_PRINT);
    }
    catch (RecordCleanerApiException $e) {
      return $e->getMessage();
    }
  }

  public function validate($data) {
    // Do validation in chunks to help manage memory and display progress.
    // Catch exceptions at the higher level in order to stop looping through
    // all the chunks if there is an error.
    $options = ['json' => $data];
    return $this->request('POST', '/validate/records_by_tvk', $options, TRUE);
  }
}
