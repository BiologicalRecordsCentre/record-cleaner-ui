<?php

/**
 * @file
 * Contains
 *   \Drupal\record_cleaner\Service\FileHelper and
 *   \Drupal\record_cleaner\Service\MyReadFilter.
 */

namespace Drupal\record_cleaner\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\record_cleaner\Service\ApiHelper;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\CellIterator;
use Exception;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

/**
 * Filter to load a block of rows and columns.
 */
class MyReadFilter implements IReadFilter {
  private $startRow;
  private $endRow;
  private $startCol;
  private $endCol;

  /**
   * Constructor for row filter.
   *
   * @param int $startRow
   *   The first row to read.
   * @param int $endRow
   *   The last row to read. If 0, read to end.
   * @param int $startCol
   *   The first column to read.
   * @param int $endCol
   *   The last column to read. If 0, read to end.
   */
  public function __construct(
    int $startRow = 1,
    int $endRow = 0,
    int $startCol = 1,
    int $endCol = 0,
  ) {
    $this->startRow = $startRow;
    $this->endRow = $endRow;
    $this->startCol = $startCol;
    $this->endCol = $endCol;
  }

  public function readCell(string $col, int $row, string $sheet = ''): bool {
    if ($row >= $this->startRow) {
      if ($this->endRow > 0 && $row > $this->endRow) {
        // After endRow
        return FALSE;
      }
      // In row limits
      $col = Coordinate::columnIndexFromString($col);
      if ($col >= $this->startCol) {
        if ($this->endCol > 0 && $col > $this->endCol) {
          // After endCol
          return FALSE;
        }
        // In col limits
        return TRUE;
      }
      // Before startCol
      return FALSE;
    }
    // Before startRow
    return FALSE;
  }
}


/**
 * Service providing functions to help process files for record cleaning.
*/
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
   * Get the number of lines in a file of records.
   *
   * @param string $filePath The absolute path of the file.
   *
   * @return int The number of lines in the file.
   */
  public function getLength($filePath) {
    $reader = IOFactory::createReaderForFile($filePath,
      [IOFactory::READER_CSV, IOFactory::READER_XLSX]
    );
    $spreadsheet = $reader->load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    $lines = $worksheet->getHighestDataRow();
    // Omit header from count
    return $lines - 1;
  }

  /**
   * Get the columns in a file from the header row.
   *
   * @param string $fileUri The URI of the file.
   *
   * @return array The columns in the file.
   */
  public function getColumns($fileUri) {
    $filePath = $this->getFilePath($fileUri);

    // Limit to csv, xlsx.
    $reader = IOFactory::createReaderForFile($filePath,
      [IOFactory::READER_CSV, IOFactory::READER_XLSX]
    );
    // Filter to first row.
    $reader->setReadFilter(new MyReadFilter(1, 1));
    $spreadsheet = $reader->load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    // Convert to 2D array
    $array = $worksheet->toArray();
    // Return first row.
    return $array[0];
  }

  /**
   * Send the contents of a file to the record cleaner service.
   *
   * Data from the source file, described in $settings['source']['mappings],
   * is submitted to the record cleaner service. The response is written to file
   * in the manner described in $settings['output']['columns'].
   * @param $settings
   *
   * @return
   */
  public function submit($settings) {
    $fileInUri = $settings['source']['uri'];
    $fileOutUri = $settings['output']['uri'];
    $fileInPath = $this->getFilePath($fileInUri);
    $fileOutPath = $this->getFilePath($fileOutUri);

    $success = TRUE;
    $counts = ['fail' => 0, 'warn' => 0, 'pass' => 0];
    $messages = [];

    try {

      $fpOut = fopen($fileOutPath, 'w');
      if ($fpOut === FALSE) {
        $this->logger->error(
          'Unable to open output file, %fileOutPath.',
          ['%fileOutPath' => $fileOutPath]
        );
        throw new Exception("Unable to open output file, $fileOutPath.");
      }
      // Write the header to the output file.
      $row = $this->getOutputFileHeader($settings);
      fputcsv($fpOut, $row);

      $reader = IOFactory::createReaderForFile($fileInPath,
        [IOFactory::READER_CSV, IOFactory::READER_XLSX]
      );

      // Filter to read no more columns than we need.
      $maxCol = count($settings['source']['columns']);
      // And skip header row.
      $reader->setReadFilter(new MyReadFilter(2, 0, 1, $maxCol));
      $spreadsheet = $reader->load($fileInPath, IReader::IGNORE_ROWS_WITH_NO_CELLS);
      $worksheet = $spreadsheet->getActiveSheet();

      list($success, $counts, $messages) = $this->submitFileChunk($worksheet, 0, $settings, $fpOut);

    }
    catch (\Exception $e) {
      $messages[] = $e->getMessage();
    }
    finally {
      fclose($fpOut);
      return [$success, $counts, $messages];
    }
  }

  /**
   * Break records from a file into chunks for the record cleaner service.
   *
   * May be called multiple times if the input file is large.
   *
   * @param object $worksheet A PHPSpreadsheet worksheet holding the lines.
   * @param int $count Number of records already processed.
   * @param array $settings The array of settings.
   * @param resource $fpOut A file pointer to the output file.
   *
   * @return array An array of success, counts, and messages.
   *   $success: Boolean indicating overall success. True if all records pass.
   *   $counts: An array of counts of records that pass, warn, and fail.
   *   $messages: An array of all messages returned by the service.
   */
  protected function submitFileChunk($worksheet, $count, $settings, $fpOut) {
    $apiChunkSize = 100;
    $success = TRUE;
    $counts = ['fail' => 0, 'warn' => 0, 'pass' => 0];
    $messages = [];
    $recordChunk = [];
    $additionalChunk = [];

    // Loop through rest of the input file line by line.
    foreach ($worksheet->getRowIterator() as $row) {
      // Skip rows with cells but no data.
      if ($row->isEmpty(
        CellIterator::TREAT_EMPTY_STRING_AS_EMPTY_CELL |
        CellIterator::TREAT_NULL_VALUE_AS_EMPTY_CELL)
        ) {
        continue;
      }
      // Extract data from $row into array.
      $rowArray = [];
      foreach ($row->getCellIterator() as $cell) {
        $rowArray[] = $cell->getFormattedValue();
      }
      // Skip validation failures during verification.
      if ($this->isValidationFailure($rowArray, $settings)) {
        continue;
      }

      // Keep count of lines processed.
      $count++;
      // Format data for submission to API.
      $recordChunk[] = $this->buildRecordSubmission($rowArray, $count, $settings);
      // Save additional data for output file.
      list($id, $value) = $this->getAdditionalData($rowArray, $count, $settings);
      $additionalChunk[$id] = $value;
      // Send to the service in chunks.
      if ($count % $apiChunkSize == 0) {
        list($chunkSuccess, $chunkCounts, $chunkMessages) = $this->submitApiChunk(
          $recordChunk, $additionalChunk, $settings, $fpOut
        );
        // Accumulate results from chunks.
        $success = $success && $chunkSuccess;
        foreach ($chunkCounts as $key => $value) {
          $counts[$key] += $value;
        }
        $messages = array_merge($messages, $chunkMessages);
        // Reset chunk.
        $recordChunk = [];
        $additionalChunk = [];
      }
    }
    // Validate the last partial chunk.
    if ($count % $apiChunkSize != 0) {
      list($chunkSuccess, $chunkCounts, $chunkMessages) = $this->submitApiChunk(
        $recordChunk, $additionalChunk, $settings, $fpOut
      );
      // Accumulate results from partial chunk.
      $success = $success && $chunkSuccess;
      foreach ($chunkCounts as $key => $value) {
        $counts[$key] += $value;
      }
      $messages = array_merge($messages, $chunkMessages);
    }

    $counts['total'] = $count;

    return [$success, $counts, $messages];
  }

  /**
   * Send a chunk of records to the API.
   *
   * @param array $recordChunk An array of record data to send to the service.
   * @param array $additionalChunk An array of additional record data to attach
   * to to records returning from the service and saved to file.
   * @param array $settings The array of settings.
   * @param resource $fpOut A file pointer to the output file.
   *
   * @return array An array of success, counts, and messages.
   *   $success: Boolean indicating overall success. True if all records pass.
   *   $counts: An array of counts of records that pass, warn, and fail.
   *   $messages: An array of all messages returned by the service.
   */
  protected function submitApiChunk($recordChunk, $additionalChunk, $settings, $fpOut) {
    $success = TRUE;
    $counts = ['fail' => 0, 'warn' => 0, 'pass' => 0];
    $messages = [];

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

    // Loop through results accumulating messages and outputting to file.
    foreach ($records as $record) {
      if ($record['result'] == 'fail') {
        $success = FALSE;
      }
      $counts[$record['result']]++;
      $messages = array_merge($messages, $record['messages']);
      $idValue = $record['id'];
      $additional = $additionalChunk[$idValue];

      $row = $this->getOutputFileRow($record, $additional, $settings);
      fputcsv($fpOut, $row);
    }
    return [$success, $counts, $messages];
  }

  public function getAdditionalData($row, $count, $settings) {
    // Attach the same id to the additional data as the validation data
    // so that they can be joined up again after calling the service.
    $mappings = $settings['source']['mappings'];
    $idField = $mappings['id'] ?? 'auto';
    $idValue = $idField == 'auto' ? $count : $row[$idField];

    // Construct an array of the values to pass through, keyed by output column
    // number. Stage is passed through during validation as we need it for
    // verification. VC is passed trhough during verification.
    $data = [];
    foreach($settings['output']['columns'] as $colNum => $value) {
      if (
        $value['function'] == 'additional' ||
        ($value['function'] == 'stage' && $settings['action'] == 'validate') ||
        ($value['function'] == 'vc' && $settings['action'] == 'verify')
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

          case 'vc':
            if ($settings['action'] == 'validate') {
              // During validation, vc is returned in the record.
              $row[] = $record[$function];
            }
            else {
              // During verification, vc is passed through in additional data.
              $row[] = $additional[$colNum];
            }
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

  /**
   * Determine if $row has failed validation.
   */
  public function isValidationFailure($row, $settings) {
    if ($settings['action'] == 'validate') {
      // Not yet validated so return early.
      return FALSE;
    }

    // Find the index of the result field.
    $resultField = $settings['source']['mappings']['result'];
    if ($row[$resultField] == 'fail') {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

}
