<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Controller\RecordCleanerController.
 */

namespace Drupal\record_cleaner\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\record_cleaner\Service\RecordCleanerService;

/**
 * Controller for record cleaner pages.
 */
class RecordCleanerController {

  // The themeable element.
  protected $element = [];

  /**
   * Returns a status page.
   * This callback is linked to the path /record_cleaner/status
   */
  public function status() {
    $RecordCleanerService = \Drupal::service('record_cleaner.record_cleaner_service');
    $element = $RecordCleanerService->status();
    return $element;
  }

}