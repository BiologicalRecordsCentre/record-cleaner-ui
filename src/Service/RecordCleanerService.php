<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\RecordCleanerService.
 */

namespace Drupal\record_cleaner\Service;

/**
 * Sevice layer for record cleaner.
 */
class RecordCleanerService {
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
