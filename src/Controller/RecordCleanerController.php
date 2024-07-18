<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Controller\RecordCleanerController.
 */

namespace Drupal\record_cleaner\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\record_cleaner\Service\ApiHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for record cleaner pages.
 */
class RecordCleanerController extends ControllerBase {

  // The themeable element.
  protected $element = [];


  public function __construct(
    protected LoggerChannelInterface $logger,
    protected ApiHelper $api,
  ) {
  }

  public static function create(ContainerInterface $container) {
    return new static (
      $container->get('record_cleaner.logger_channel'),
      $container->get('record_cleaner.api_helper'),
    );
  }

  /**
   * Returns an overview page.
   * This callback is linked to the path /record_cleaner
   */
  public function overview() {
    $element = [
      '#markup' => '<p>A helpful introduction.</p>',
    ];
    return $element;
  }

  /**
   * Returns a status page.
   * This callback is linked to the path /record_cleaner/status
   */
  public function status() {
    $element = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => $this->api->status(),
    ];
    return $element;
  }

}
