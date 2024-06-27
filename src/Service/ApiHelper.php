<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\ApiHelper.
 */

namespace Drupal\record_cleaner\Service;

use Drupal\Core\Url;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Sevice layer for record cleaner.
 */
class ApiHelper {
  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  public function __construct(
    protected LoggerChannelInterface $logger
  )
  {}

  public function overview() {
    $markup = "<p>";
    $markup .= $this->t("<a href=':status'>Status</a>", [
      ':status' => Url::fromRoute('record_cleaner.status')->toString(),
    ]);
    $markup .="</p>";

    $element = [
      '#type' => 'markup',
      '#markup' => $markup,
    ];
    return $element;

  }

  public function status() {
    // Obtain settings
    $config = \Drupal::config('record_cleaner.settings');
    $service_url = $config->get('record_cleaner.service_url');

    try {
      $client = \Drupal::httpClient();
      $request = $client->get($service_url);
      $res_txt = $request->getBody()->getContents();
    }
    catch (\Exception $e) {
      $res_txt = $e->getMessage();
    }

    $element = [
        '#type' => 'plain_text',
        '#plain_text' => $res_txt,
    ];
    return $element;
  }

}
