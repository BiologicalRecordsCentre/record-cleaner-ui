<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\CsvHelper.
 */

namespace Drupal\record_cleaner\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\record_cleaner\Service\ApiHelper;



class CsvHelper {
  public function __construct(
    protected LoggerChannelInterface $logger,
    protected StreamWrapperManager $streamWrapperManager,
    protected ApiHelper $api,
  )
  {}

  /**
   * Get the absolute path of a file.
   *
   * @param string $fileUri The URI of the file.
   *
   * @return string The absolute path of the file.
   */
  public function getFilePath($fileUri) {
    $wrapper = $this->streamWrapperManager->getViaUri($fileUri);
    return $wrapper->realpath();
  }

  /**
   * Get the number of lines in a file.
   *
   * @param string $filePath The absolute path of the file.
   *
   * @return int The number of lines in the file.
   */
  public function getLength($filePath) {
    // Count from -1 to omit the header.
    $lines = -1;
    $buffer = '';
    $fp = fopen($filePath, 'rb');

    while (!feof($fp)) {
        $buffer = fread($fp, 8192);
        $lines += substr_count($buffer, "\n");
    }

    fclose($fp);
    // Include any last line without a newline termination.
    if (strlen($buffer) > 0 && $buffer[-1] != "\n") {
        ++$lines;
    }
    return $lines;
  }

  /**
   * Get the columns in a CSV file from the header row.
   *
   * @param string $fileUri The URI of the file.
   *
   * @return array The columns in the file.
   */
  public function getColumns($fileUri) {
    $filePath = $this->getFilePath($fileUri);
    $fp = fopen($filePath, 'r');
    $columns = fgetcsv($fp);
    fclose($fp);
    return $columns;
  }

  public function setColumns($settings) {
    $row = [];
    $i = 0;
    $row[$i++] = 'id';
    $row[$i++] = 'date';
    $row[$i++] = 'gridref';
    $row[$i++] = 'tvk';
    $row[$i++] = 'name';
    $row[$i++] = 'id_difficulty';
    $row[$i++] = 'vc';
    $row[$i++] = 'messages';
    return $row;

  }

  /**
   * Send the contents of the CSV file to the validation service.
   * @param $settings
   *
   * @return
   */
  public function validate($settings) {
    $chunk_size = 100;
    $fileInUri = $settings['upload']['uri'];
    $fileOutUri = $settings['validate']['uri'];
    $fileInPath = $this->getFilePath($fileInUri);
    $fileOutPath = $this->getFilePath($fileOutUri);

    $chunk = [];
    $errors = [];
    $count = 1;

    try {
      $fpIn = fopen($fileInPath, 'r');
      $fpOut = fopen($fileOutPath, 'w');
      // Skip the first line of the input file.
      fgetcsv($fpIn);
      // Write the header to the output file.
      $row = $this->setColumns($settings);
      fputcsv($fpOut, $row);

      // Loop through rest of the input file line by line.
      while (($row = fgetcsv($fpIn)) !== FALSE) {
        // Format data for validation.
        $chunk[] = $this->toValidate($row, $count, $settings);
        // Send to the service in chunks.
        if ($count % $chunk_size == 0) {
          $errors += $this->validateChunk($chunk, $settings, $fpOut);
          $chunk = [];
        }
        else {
          $count++;
        }
      }
      // Validate the last partial chunk.
      if ($count % $chunk_size != 0) {
        $errors += $this->validateChunk($chunk, $settings, $fpOut);
      }
    }
    catch (\Exception $e) {
      $errors[] = $e->getMessage();
    }
    finally {
      fclose($fpIn);
      fclose($fpOut);
      return $errors;
    }
  }

  /**
   * Convert a CSV row to a data structure for the validation service.
   *
   * @param $row The CSV row as an array.
   * @param $count The row number.
   * @param $settings
   */
  public function toValidate($row, $count, $settings) {
    // Create the Sref sub-structure first.
    if ($settings['sref_type'] == 'grid') {
      $sref = [
        'srid' => $settings['sref_grid'],
        'gridref' => $row[$settings['coord1_field']],
      ];
    }
    else {
      if ($settings['sref_nr_coords'] == 1) {
        // Try splitting on likely separators.
        $separators = [',', ' '];
        $coord1 = $coord2 = NULL;
        foreach ($separators as $separator) {
          $coords = explode($separator, $row[$settings['coord1_field']]);
          if (count($coords) == 2) {
            $coord1 = trim($coords[0]);
            $coord2 = trim($coords[1]);
            break;
          }
        }
      }
      else {
        $coord1 = ($settings['coord1_field']);
        $coord2 = ($settings['coord2_field']);
      }

      $precisionField = $settings['precision_field'];
      if ($precisionField == 'manual') {
        $accuracy = $settings['precision_value'];
      }
      else {
        $accuracy = $row[$precisionField];
      }

      if ($settings['sref_type'] == 'en') {
        $sref = [
          'srid' => $settings['sref_en'],
          'easting' => $coord1,
          'northing' => $coord2,
          'accuracy' => $$accuracy
        ];
      }
      else {
        $sref = [
          'srid' => $settings['sref_latlon'],
          'longitude' => $coord1,
          'latitude' => $coord2,
          'accuracy' => $$accuracy
        ];
      }
    }

    // Now assemble the validation structure.
    $idField = $settings['id_field'];
    $validate = [
      'id' => $idField == 'auto' ? $count : $row[$idField] ,
      'date' => $row[$settings['date_field']],
      'sref' => $sref,
      'tvk' => $row[$settings['tvk_field']],
    ];

    $vcField = $settings['vc_field'];
    if ($vcField != '') {
      $validate['vc'] = $row[$vcField];
    }

    return $validate;
  }

  public function validateChunk($chunk, $settings, $fpOut) {
    $errors = [];
    $json = $this->api->validate($chunk);
    $array = json_decode($json, TRUE);
    foreach ($array as $record) {
      if ($record['ok'] == FALSE) {
        $errors += $record['messages'];
      }
      $row = $this->toCsv($record, $settings);
      fputcsv($fpOut, $row);
    }
    return $errors;
  }


  /**
   * Convert a record from the validation service to a CSV row.
   *
   * @param $record  A response from the validation service.
   * @param $settings
   */
  public function toCsv($record, $settings) {
      $row = [];
      $i = 0;
      $row[$i++] = $record['id'];
      $row[$i++] = $record['date'];
      $row[$i++] = $record['sref']['gridref'];
      $row[$i++] = $record['tvk'];
      $row[$i++] = $record['name'];
      $row[$i++] = implode('\n', $record['id_difficulty']);
      $row[$i++] = $record['vc'];
      $row[$i++] = implode('\n',$record['messages']);
      return $row;
  }

}
