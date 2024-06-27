<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\CsvHelper.
 */

namespace Drupal\record_cleaner\Service;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;


class CsvHelper {
  public function __construct(
    protected LoggerChannelInterface $logger,
    protected EntityTypeManager $entityTypeManager,
    protected StreamWrapperManager $streamWrapperManager,
  )
  {}

  public function getColumns($fid) {
    // Load the file entity.
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    // Obtain the file location.
    $fileUri = $file->getFileUri();
    $wrapper = $this->streamWrapperManager->getViaUri($fileUri);
    $filePath = $wrapper->realpath();
    // Read the first line of the file.
    $fp = fopen($filePath, 'r');
    $columns = fgetcsv($fp);
    fclose($fp);
    return $columns;
  }

}
