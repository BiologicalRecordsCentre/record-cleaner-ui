<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\FileHelper.
 */

namespace Drupal\record_cleaner\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\record_cleaner\Service\ApiHelper;
use Exception;



class FileHelper {
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

  /**
   * Send the contents of the CSV file to the record cleaner service.
   *
   * Data from the source file, described in $settings['source']['mappings],
   * is submitted to the record cleaner service. The response is written to file
   * in the manner described in $settings['output']['columns'].
   * @param $settings
   *
   * @return
   */
  public function submit($settings) {
    $chunk_size = 100;
    $fileInUri = $settings['source']['uri'];
    $fileOutUri = $settings['output']['uri'];
    $fileInPath = $this->getFilePath($fileInUri);
    $fileOutPath = $this->getFilePath($fileOutUri);

    $recordChunk = [];
    $additionalChunk = [];
    $errors = [];
    $count = 1;

    try {
      $fpIn = fopen($fileInPath, 'r');
      $fpOut = fopen($fileOutPath, 'w');
      // Skip the first line of the input file.
      fgetcsv($fpIn);
      // Write the header to the output file.
      $row = $this->getOutputFileHeader($settings);
      fputcsv($fpOut, $row);

      // Loop through rest of the input file line by line.
      while (($row = fgetcsv($fpIn)) !== FALSE) {
        // Skip empty rows.
        if ($this->isEmptyRow($row)) {
          continue;
        }

        // Format data for submission to API.
        $recordChunk[] = $this->buildRecordSubmission($row, $count, $settings);
        // Save additional data for output file.
        list($id, $value) = $this->getAdditionalData($row, $count, $settings);
        $additionalChunk[$id] = $value;
        // Send to the service in chunks.
        if ($count % $chunk_size == 0) {
          $errors += $this->submitChunk(
            $recordChunk, $additionalChunk, $settings, $fpOut
          );
          $recordChunk = [];
          $additionalChunk = [];
        }
        else {
          $count++;
        }
      }
      // Validate the last partial chunk.
      if ($count % $chunk_size != 0) {
        $errors += $this->submitChunk(
          $recordChunk, $additionalChunk, $settings, $fpOut
        );
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

  public function submitChunk($recordChunk, $additionalChunk, $settings, $fpOut) {
    $errors = [];

    // Submit chunk to relevant service.
    if ($settings['action'] == 'validate') {
      $json = $this->api->validate($recordChunk);
      $records = json_decode($json, TRUE);
    }
    else {
      $pack = [
        'org_group_rules_list' => $settings['org_group_rules'],
        'records' => $recordChunk,
      ];
      $json = $this->api->verify($pack);
      $records = json_decode($json, TRUE)['records'];
    }

    // Loop through results accumulating errors and outputting to file.
    foreach ($records as $record) {
      if ($record['ok'] == FALSE) {
        $errors = array_merge($errors, $record['messages']);
      }
      $idValue = $record['id'];
      $additional = $additionalChunk[$idValue];

      $row = $this->getOutputFileRow($record, $additional, $settings);
      fputcsv($fpOut, $row);
    }
    return $errors;
  }

  public function getAdditionalData($row, $count, $settings) {
    // Attach the same id to the additional data as the validation data
    // so that they can be joined up again after calling the validation service.
    $mappings = $settings['source']['mappings'];
    $idField = $mappings['id_field'] ?? 'auto';
    $idValue = $idField == 'auto' ? $count : $row[$idField];

    // Construct an array of the values to pass through, keyed by output column
    // number. Stage is passed through during validation as we need it for
    // verification.
    $data = [];
    foreach($settings['output']['columns'] as $colNum => $value) {
      if (
        $value['function'] == 'additional' ||
        ($value['function'] == 'stage' && $settings['action'] == 'validate')
      ) {
        $data[$colNum] = $row[$value['column']];
      }
    }

    return [$idValue, $data];
  }

  public function getOutputFileHeader($settings) {
    $row = [];
    foreach($settings['output']['columns'] as $column) {
      $row[] = $column['name'];
    }
    return $row;
  }

  /**
   * Convert a record from the validation service to a CSV row.
   *
   * @param $record  A response from the validation service.
   * @param $settings
   */
  public function getOutputFileRow($record, $additional, $settings) {
    $row = [];
    foreach($settings['output']['columns'] as $colNum => $column) {

      $function = $column['function'];

      switch ($function) {
        case 'id_difficulty':
        case 'messages':
          // Combine array fields into a single string.
          $row[] = implode("\n", $record[$function]);
          break;

        case 'organism':
          if ($settings['organism']['type'] == 'tvk') {
            $row[] = $record['tvk'];
          }
          elseif ($settings['organism']['type'] == 'name') {
            $row[] = $record['name'];
          }
          break;

        case 'coord1':
          if ($settings['sref']['type'] == 'grid') {
            $row[] = $record['sref']['gridref'];
          }
          elseif ($settings['sref']['type'] == 'en') {
            if ($settings['sref']['nr_coords'] == 1) {
              $row[] = $record['sref']['easting'] . ' ' . $record['sref']['northing'];
            }
            else {
              $row[] = $record['sref']['easting'];
            }
          }
          else {
            if ($settings['sref']['nr_coords'] == 1) {
              $row[] = $record['sref']['longitude'] . ' ' . $record['sref']['latitude'];
            }
            else {
              $row[] = $record['sref']['longitude'];
            }
          }
          break;

        case 'coord2':
          if ($settings['sref']['type'] == 'en') {
              $row[] = $record['sref']['northing'];
          }
          else {
              $row[] = $record['sref']['latitude'];
          }
          break;

        case 'additional':
          $row[] = $additional[$colNum];
          break;

        case 'stage':
          if ($settings['action'] == 'validate') {
            // During validation, stage is passed through in additional data.
            $row[] = $additional[$colNum];
          }
          else {
            // During verification, stage is returned in the record.
            $row[] = $record[$function];
          }
          break;

        case 'ok':
          $row[] = $record[$function] ? 'Y' : 'N';
          break;

        default:
          $row[] = $record[$function];
          break;
      }
    }
    return $row;
  }

  public function buildSrefSubmission($row, $settings) {
    // Select the mappings from function to row index.
    $mappings = $settings['source']['mappings'];

    $sref['srid'] = $settings['sref']['srid'];

    if ($settings['sref']['type'] == 'grid') {
      // Gridrefs are simple.
      $sref['gridref'] = $row[$mappings['coord1']];
    }
    else {
      // Determine precision of coordinates.
      $precisionField = $mappings['precision'] ?? 'manual';
      if ($precisionField == 'manual') {
        $accuracy = $settings['sref']['precision_value'];
      }
      else {
        $accuracy = $row[$precisionField];
      }
      $sref['accuracy'] = $accuracy;

      // Unscramble coordinates in to x and y.
      if ($settings['sref']['nr_coords'] == 1) {
        // Try splitting on likely separators.
        $separators = [ ',', ' '];
        $coord1 = $coord2 = NULL;
        foreach ($separators as $separator) {
          $coords = explode($separator, $row[$mappings['coord1']]);
          if (count($coords) == 2) {
            $coord1 = trim($coords[0]);
            $coord2 = trim($coords[1]);
            break;
          }
        }
      }
      else {
        $coord1 = $row[$mappings['coord1']];
        $coord2 = $row[$mappings['coord2']];
      }

      if ($settings['sref_type'] == 'en') {
        $sref['easting'] = $coord1;
        $sref['northing'] = $coord2;
      }
      else {
        $sref['longitude'] = $coord1;
        $sref['latitude'] = $coord2;
      }

    }

    return $sref;
  }

  /**
   * Convert a CSV row to a data structure for the record-cleaner service.
   *
   * @param $row The CSV row as an array.
   * @param $count The row number.
   * @param $settings
   */
  public function buildRecordSubmission($row, $count, $settings) {
    // Select the mappings from function to row index.
    $mappings = $settings['source']['mappings'];

    // Mandatory fields.
    $idField = $mappings['id'] ?? 'auto';
    $record = [
      'id' => $idField == 'auto' ? $count : $row[$idField] ,
      'date' => $row[$mappings['date']],
      'sref' => $this->buildSrefSubmission($row, $settings),
    ];

    if (array_key_exists('tvk', $mappings)) {
      $record['tvk'] = $row[$mappings['tvk']];
    }
    elseif (array_key_exists('name', $mappings)) {
      $record['name'] = $row[$mappings['name']];
    }

    // Optional fields.
    if(array_key_exists('vc', $mappings)) {
      $record['vc'] = $row[$mappings['vc']];
    }

    // Extra optional field for verification.
    if ($settings['action'] == 'verify') {
      if(array_key_exists('stage', $mappings)) {
        $record['stage'] = $row[$mappings['stage']];
      }
    }

    return $record;
  }

  public function isEmptyRow($row) {
    foreach ($row as $value) {
      if ($value !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }
}
