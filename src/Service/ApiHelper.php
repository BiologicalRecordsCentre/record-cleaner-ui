<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\ApiHelper.
 */

namespace Drupal\record_cleaner\Service;

use \Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

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
    protected CacheBackendInterface $cache,
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
      'grant_type' => 'password',
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
    catch (ClientException $e) {
      // 400 level errors
      $msg = $e->getResponse()->getBody()->getContents();
      $this->logger->error($msg);
      throw new RecordCleanerApiException($msg);
    }
    catch (ServerException $e) {
      // 500 level errors
      $msg = $e->getMessage();
      $this->logger->error($msg);
      $msg = $this->t("Unable to process request. Please check its validity.");
      throw new RecordCleanerApiException($msg);
    }
    catch (RequestException $e) {
      // Eg. An SSL certificate error.
      $msg = $e->getMessage();
      $this->logger->error($msg);
      $msg = $this->t("Could not connect to service. Please try again later.");
      throw new RecordCleanerApiException($msg);
    }

  }

 /**
  * Return the full service status as JSON.
  */
  public function status() {
    try {
      $json = $this->request('GET');
      return json_encode(json_decode($json), JSON_PRETTY_PRINT);
    }
    catch (RecordCleanerApiException $e) {
      return $e->getMessage();
    }
  }

  /**
   * Return a simple service status.
   *
   * @return array [ok|maintenance|fail, message]
   */
  public function summaryStatus() {
    try {
      $json = $this->request('GET');
      $status = json_decode($json, TRUE);
      if ($status['maintenance_mode'] === TRUE) {
        return ['maintenance', $status['maintenance_message']];
      }
      else {
        return ['ok', 'Service up.'];
      }
    }
    catch (RecordCleanerApiException $e) {
      return ['fail', $e->getMessage()];
    }
  }

  /**
   * Build a hierarchical list of organisations, groups and rules.
   *
   * Calls the record cleaner service to obtain the current list but then caches
   * the result for 24 hours.
   *
   * @return array Each element is keyed by organisation name and contains an
   * array. The sub-array is keyed by group name and contains an array of rule
   * names.
   */
  public function orgGroupRules() {
    // Return cached value if available.
    $cid = 'record_cleaner.api.org_group_rules';
    $data = $this->cache->get($cid);
    if ($data) {
      return $data->data;
    }

    // Otherwise build the list and cache it.
    $orgGroupRules = [];
    try {
      $json = $this->request('GET', '/rules/org-groups', [], TRUE);
      $orgGroups = json_decode($json, TRUE);
      foreach ($orgGroups as $orgGroup) {
        $organisation = $orgGroup['organisation'];
        if (!array_key_exists($organisation, $orgGroupRules)) {
          $orgGroupRules[$organisation] = [];
        }
        $group = $orgGroup['group'];
        if (!array_key_exists($group, $orgGroupRules[$organisation])) {
          $orgGroupRules[$organisation][$group] = [];
        }
        $json = $this->request('GET', '/rules/org-groups/' . $orgGroup['id'], [], TRUE);
        $details = json_decode($json, TRUE);
        foreach ($details as $key => $value) {
          // $keys of interest are like {rule type}_rule_update.
          // A null $value indicates no rules of that type are present.
          // Difficulty rules are used in validation not verification.
          $keyParts = explode('_', $key);
          if (
            count($keyParts) == 3 &&
            $keyParts[1] == 'rule' &&
            $keyParts[0] != 'difficulty' &&
            $value != NULL
            ) {
            $rule = ucfirst($keyParts[0]) . ' Rule';
            $orgGroupRules[$organisation][$group][] = $rule;
          }
        }
      }

      $expire = time() + 24 * 60 * 60;
      $this->cache->set($cid, $orgGroupRules, $expire);
      return $orgGroupRules;
    }
    catch (RecordCleanerApiException $e) {
      $msg = $e->getMessage();
      $this->logger->error($msg);
      return [];
    }
  }

  public function validate($data) {
    // Do validation in chunks to help manage memory and display progress.
    // Catch exceptions at the higher level in order to stop looping through
    // all the chunks if there is an error.
    $options = ['json' => $data];
    return $this->request('POST', '/validate', $options, TRUE);
  }

  public function verify($data) {
    // Do validation in chunks to help manage memory and display progress.
    // Catch exceptions at the higher level in order to stop looping through
    // all the chunks if there is an error.
    $options = ['json' => $data];
    return $this->request('POST', '/verify', $options, TRUE);
  }
}

