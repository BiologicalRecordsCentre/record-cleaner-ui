<?php

namespace Drupal\record_cleaner\Form;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\record_cleaner\Service\CsvHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CSV file upload form.
 */
class RecordCleanerUpload extends FormBase {

  use DependencySerializationTrait;

  public $steps =  [
    'upload', 'mapping', 'sref'
  ];

  /**
   * Constructs a new FileExampleReadWriteForm object.
   *
   * @param \Drupal\file_example\FileExampleStateHelper $stateHelper
   *   The file example state helper.
   * @param \Drupal\record_cleaner\Service\CsvHelper $csvHelper
   *   The record_cleaner submit handler helper.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   *
   * @see https://php.watch/versions/8.0/constructor-property-promotion
   */
  public function __construct(
    // protected FileExampleStateHelper $stateHelper,
    protected CsvHelper $csvHelper,
    protected LoggerChannelInterface $logger,
    protected AccountProxyInterface $currentUser
    //protected FileSystemInterface $fileSystem
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static (
      $container->get('record_cleaner.csv_helper'),
      $container->get('record_cleaner.logger_channel'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
      return 'record_cleaner_upload';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    if (!$form_state->has('step_num')) {
      $form_state->set('step_num', 0);
    }

    $step = $this->steps[$form_state->get('step_num')];

    switch ($step) {
      case 'upload':
        return $this->buildUploadForm($form, $form_state);
        break;
      case 'mapping':
        return $this->buildMappingForm($form, $form_state);
        break;
      case 'sref':
        return $this->buildSrefForm($form, $form_state);
        break;
    }
  }

/********************* FILE UPLOAD FORM *********************/

  public function buildUploadForm(array $form, FormStateInterface $form_state) {
    $form['file_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Data File'),
      '#description' => $this->t("Please select a CSV file containing your data.
      The first row must be a header with the column names. The file must
      contain at least a date, a location and a taxon name or taxon version key.
      "),
      '#required' => TRUE,
       '#default_value' =>  $form_state->getValue('file_upload'),
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'csv',
        ],
        // Implement an EventSubscriber to add your custom validation code that
        // can add to the ConstraintViolationList.
        // https://www.drupal.org/node/3363700
        //'FileIsCsv' => [],
      ],
      //'#upload_location' => 'public://',
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      '#submit' => ['::forwardFromUploadForm'],
      //'#validate' => ['::validateUploadForm'],
    ];

    return $form;
  }

  public function forwardFromUploadForm(array &$form, FormStateInterface $form_state) {
    $fid = $form_state->getValue('file_upload')[0];
    $file = $form['file_upload']['#files'][$fid];
    $fileColumns = $this->csvHelper->getColumns($fid);
    $form_state->set('file_columns', $fileColumns);

    $this->logger->notice(
      $this->t("File uploaded: %file (fid=%fid)"),
      ['%file' => $file->getFileUri(), '%fid' => $fid]);

    // Store upload values.
    $form_state
      ->set('upload_values', [
        'file_upload' => $form_state->getValue('file_upload')
      ]);

    // Advance to the next step.
    $this->moveForward($form_state);
  }

/********************* FIELD MAPPING FORM *********************/

  public function buildMappingForm(array $form, FormStateInterface $form_state) {

    // Each of the selectors which map columns in the CSV file to the
    // required fields has its options recalculated every time the form is
    // built. The options are keyed by column number and the value is the
    // column heading from the first row of the CSV file.
    $fileColumns = $form_state->get('file_columns');
    $unusedColumns = $this->getUnusedColumns($form_state);

    $key = $form_state->getValue('id_field');
    $option = isset($key) && $key != 'auto'?
      [$key => $fileColumns[$key]] : [];
    $idFieldOptions = $option + $unusedColumns;
    ksort($idFieldOptions);

    $key = $form_state->getValue('date_field');
    $option = isset($key) ?
      [$key => $fileColumns[$key]] : [];
    $dateFieldOptions = $option + $unusedColumns;
    ksort($dateFieldOptions);

    $key = $form_state->getValue('tvk_field');
    $option = isset($key) ?
      [$key => $fileColumns[$key]] : [];
    $tvkFieldOptions = $option + $unusedColumns;
    ksort($tvkFieldOptions);

    // Wrap the field mapping inputs in a container as a target for AJAX.
    $form['mappings'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'record_cleaner_mappings',
      ],
    ];

    $form['mappings']['id_field'] = [
      '#type' => 'select',
      '#title' => $this->t("Unique Record Key Field"),
      '#description' => $this->t("Please select the field in the source data
      which represents the unique record key."),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' =>
        ['auto' => $this->t('Auto Row Number')] + $idFieldOptions,
      '#default_value' => $form_state->getValue('id_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $form['mappings']['date_field'] = [
      '#type' => 'select',
      '#title' => $this->t("Date"),
      '#description' => $this->t("Please select the field in the source data
      which represents the date."),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => $dateFieldOptions,
      '#default_value' => $form_state->getValue('date_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $form['mappings']['tvk_field'] = [
      '#type' => 'select',
      '#title' => $this->t("Taxon Version Key"),
      '#description' => $this->t("Please select the field in the source data
      which holds the taxon version key."),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => $tvkFieldOptions,
      '#default_value' => $form_state->getValue('tvk_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backFromMappingForm'],
      //#limit_validation_errors will break things.
    ];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      '#submit' => ['::forwardFromMappingForm'],
      //'#validate' => ['::validateMappingForm'],
    ];

    return $form;
  }

  public function backFromMappingForm(array &$form, FormStateInterface $form_state) {
    $this->saveMappingValues($form_state);
    $this->moveBack($form_state);
  }

  public function forwardFromMappingForm(array &$form, FormStateInterface $form_state) {
    $this->saveMappingValues($form_state);
    $this->moveForward($form_state);
  }

  public function saveMappingValues(FormStateInterface $form_state) {
    $form_state->set('mapping_values', [
      'id_field' => $form_state->getValue('id_field'),
      'date_field' => $form_state->getValue('date_field'),
      'tvk_field' => $form_state->getValue('tvk_field'),
    ]);
  }


/********************* SPATIAL REFERENCE FORM *********************/

  public function buildSrefForm(array $form, FormStateInterface $form_state) {

    $srefType = $form_state->getValue('sref_type');
    $nrCoords = $form_state->getValue('nr_coords');

    // Determine title and description for coordinate form elements.
    $title1 = $title2 = $description1 = $description2 = '';
    switch ($srefType) {
      case 'grid':
        $title1 = $this->t("Grid Reference");
        $description1 = $this->t("Please select the column in the source data
        which contains the grid reference.");
          break;
      case 'en':
        if ($nrCoords == '2') {
          $title1 = $this->t("Easting");
          $description1 = $this->t("Please select the column in the source data
          which contains the eastings.");
          $title2 = $this->t("Northing");
          $description2 = $this->t("Please select the column in the source data
          which contains the northings.");
        }
        else {
            $title1 = $this->t("Coordinates");
            $description1 = $this->t("Please select the column in the source data
            which contains the coordinates, eastings followed by northings.");
        }
        break;
      case 'latlon':
        if ($nrCoords == '2') {
          $title1 = $this->t("Longitude");
          $description1 = $this->t("Please select the column in the source data
          which contains the longitude.");
          $title2 = $this->t("Latitude");
          $description2 = $this->t("Please select the column in the source data
          which contains the latitude.");
        }
        else {
          $title1 = $this->t("Coordinates");
          $description1 = $this->t("Please select the column in the source data
          which contains the coordinates, longitude followed by latitude, in
          decimaldegrees.");
        }
        break;
    }

    // Each of the selectors which map columns in the CSV file to the
    // required fields has its options recalculated every time the form is
    // built. The options are keyed by column number and the value is the
    // column heading from the first row of the CSV file.
    $fileColumns = $form_state->get('file_columns');
    $unusedColumns = $this->getUnusedColumns($form_state);

    // Create sorted list of options for coord1.
    $key = $form_state->getValue('coord1_field');
    $option = isset($key) ?
      [$key => $fileColumns[$key]] : [];
    $coord1FieldOptions = $option + $unusedColumns;
    ksort($coord1FieldOptions);

    // Create sorted list of options for coord2.
    $key = $form_state->getValue('coord2_field');
    $option = isset($key) ?
      [$key => $fileColumns[$key]] : [];
    $coord2FieldOptions = $option + $unusedColumns;
    ksort($coord2FieldOptions);

    // Create sorted list of options for precision.
    $key = $form_state->getValue('precision_field');
    $option = isset($key) && $key != 'manual' ?
      [$key => $fileColumns[$key]] : [];
    $precisionFieldOptions = $option + $unusedColumns;
    ksort($precisionFieldOptions);


    // Create each of the form elements.
    $selectSrefType = [
      '#type' => 'select',
      '#title' => $this->t("Spatial Reference Type"),
      '#description' => $this->t("Please select the spatial reference type."),
      '#required' => TRUE,
      '#options' => [
        'grid' => $this->t('Grid Reference (e.g.SM123456)'),
        'en' => $this->t('Easting and Northing (e.g. 612300, 545600)'),
        'latlon' => $this->t('Longitude and Latitude (e.g. -3.833, 52.789)'),
      ],
      '#default_value' => $form_state->getValue('sref_type'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $containerMappings = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'record_cleaner_mappings',
      ],
    ];

    $selectGrid = [
      '#type' => 'select',
      '#title' => $this->t("Grid Reference System"),
      '#description' => $this->t("Please select the grid reference type."),
      '#required' => TRUE,
      '#options' => [
        '27700' => $this->t('British gridref (e.g.SM123456)'),
        '29903' => $this->t('Irish gridref (e.g. G123456)'),
        '23030' => $this->t('Channel Islands gridref (e.g. WA/WV)'),
        '0' => $this->t('British, Irish or CI gridref'),
      ],
      '#default_value' => $form_state->getValue('sref_grid'),
    ];

    $selectEn = [
      '#type' => 'select',
      '#title' => $this->t("Coordinate System"),
      '#description' => $this->t("Please select the coordinate system."),
      '#required' => TRUE,
      '#options' => [
        '27700' => $this->t('British'),
        '29903' => $this->t('Irish'),
      ],
      '#default_value' => $form_state->getValue('sref_en'),
    ];

    $selectLatLon = [
      '#type' => 'select',
      '#title' => $this->t("Latitude and Longitude System"),
      '#description' => $this->t("Please select the latitude and longitude type."),
      '#required' => TRUE,
      '#options' => [
        '4326' => $this->t('World Geodetic System (WGS84)'),
      ],
      '#default_value' => $form_state->getValue('sref_latlon'),
    ];

    $selectNrCoords = [
      '#type' => 'select',
      '#title' => $this->t("Number of columns"),
      '#description' => $this->t("Please select the number of columns containing
      the coordinates."),
      '#options' => [
        '1' => $this->t('1'),
        '2' => $this->t('2'),
      ],
      '#default_value' => $form_state->getValue('nr_coords'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $selectCoord1 = [
      '#type' => 'select',
      '#title' => $title1,
      '#description' => $description1,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => $coord1FieldOptions,
      '#default_value' => $form_state->getValue('coord1_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $selectCoord2 = [
      '#type' => 'select',
      '#title' => $title2,
      '#description' => $description2,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => $coord2FieldOptions,
      '#default_value' => $form_state->getValue('coord2_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $selectPrecision = [
      '#type' => 'select',
      '#title' => $this->t("Precision Source"),
      '#description' => $this->t("Please select the source for coordinate
      precision. This may be a column in the source data or a manually entered
      value."),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' =>
        ['manual' => $this->t('Set manual precision')] +
        $precisionFieldOptions,
      '#default_value' => $form_state->getValue('precision_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    $textPrecision = [
      '#type' => 'textfield',
      '#title' => $this->t("Manual Precision"),
      '#description' => $this->t("Please enter a precision value which will
      apply to all coordinates. This is an integer value in metres."),
      '#default_value' => $form_state->getValue('precision_value') ?? '1000',
      '#maxlength' => 6,
      '#pattern' => '[0-9]+',
      '#states' => [
        'visible' => [
          ':input[name="precision_field"]' => ['value' => 'manual']
        ],
        'required' => [
          ':input[name="precision_field"]' => ['value' => 'manual']
        ],
      ],
    ];

    // Build the form according to current selections.
    $form['sref_type'] = $selectSrefType;
    $form['mappings'] = $containerMappings;

    if ($srefType == 'grid') {
      $form['mappings']['sref_grid'] = $selectGrid;
      $form['mappings']['coord1_field'] = $selectCoord1;
    }
    elseif ($srefType == 'en') {
      $form['mappings']['sref_en'] = $selectEn;
    }
    elseif ($srefType == 'latlon') {
      $form['mappings']['sref_latlon'] = $selectLatLon;
    }

    if ($srefType == 'en' || $srefType == 'latlon') {
      $form['mappings']['nr_coords'] = $selectNrCoords;
      $form['mappings']['coord1_field'] = $selectCoord1;
      if ($nrCoords == '2') {
        $form['mappings']['coord2_field'] = $selectCoord2;
      }
      $form['mappings']['precision_field'] = $selectPrecision;
      $form['mappings']['precision_value'] = $textPrecision;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backFromSrefForm'],
      //#limit_validation_errors will break things.
    ];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Validate'),
      '#submit' => ['::forwardFromSrefForm'],
      //'#validate' => ['::validateSrefForm'],
    ];

    return $form;
  }

  public function backFromSrefForm(array &$form, FormStateInterface $form_state) {
    $this->saveSrefValues($form_state);
    $this->moveBack($form_state);
  }

  public function forwardFromSrefForm(array &$form, FormStateInterface $form_state) {
    $this->saveSrefValues($form_state);
    $this->moveForward($form_state);
  }

  public function saveSrefValues(FormStateInterface $form_state) {
    $form_state->set('sref_values', [
      'sref_type' => $form_state->getValue('sref_type'),
      'sref_grid' => $form_state->getValue('sref_grid'),
      'sref_en' => $form_state->getValue('sref_en'),
      'sref_latlon' => $form_state->getValue('sref_latlon'),
      'nr_coords' => $form_state->getValue('nr_coords'),
      'coord1_field' => $form_state->getValue('coord1_field'),
      'coord2_field' => $form_state->getValue('coord2_field'),
      'precision_field' => $form_state->getValue('precision_field'),
      'precision_value' => $form_state->getValue('precision_value'),
    ]);
  }

/********************* SOMETHING ELSE *********************/



/********************* UTILITY FUNCTIONS *********************/

  /**
   * Callback function for AJAX.
   *
   * Rebuilds the mapping field selectors.
   */
  public function mappingChangeCallback(array &$form, FormStateInterface $form_state) {
    return $form['mappings'];
  }

  public function moveForward(FormStateInterface $form_state) {
    $this->move($form_state, 1);
  }

  public function moveBack(FormStateInterface $form_state) {
    $this->move($form_state, -1);
  }

  public function move(FormStateInterface $form_state, int $increment) {
    $stepNum = $form_state->get('step_num') + $increment;
    $step = $this->steps[$stepNum];

    // Restore form values previously set.
    if ($form_state->has("{$step}_values")) {
      $form_state->setValues($form_state->get("{$step}_values"));
    }

    // Change step.
    $form_state
      ->set('step_num', $stepNum)
      ->setRebuild(TRUE);
  }

  public function getUnusedColumns(FormStateInterface $form_state) {

    // Each of the selectors which map columns in the CSV file to the
    // required fields has its options recalculated every time the form is
    // built. The options are keyed by column number and the value is the
    // column heading from the first row of the CSV file.
    $unusedColumns = $form_state->get('file_columns');
    $step = $this->steps[$form_state->get('step_num')];

    switch ($step) {
      case 'mapping':
        $idFieldKey = $form_state->getValue('id_field');
        $dateFieldKey = $form_state->getValue('date_field');
        $tvkFieldKey = $form_state->getValue('tvk_field');
        $coord1FieldKey = $form_state->get(['sref_values', 'coord1_field']);
        $coord2FieldKey = $form_state->get(['sref_values', 'coord2_field']);
        $precisionFieldKey = $form_state->get(['sref_values', 'precision_field']);
        break;
      case 'sref':
        $idFieldKey = $form_state->get(['mapping_values', 'id_field']);
        $dateFieldKey = $form_state->get(['mapping_values', 'date_field']);
        $tvkFieldKey = $form_state->get(['mapping_values', 'tvk_field']);
        $coord1FieldKey = $form_state->getValue('coord1_field');
        $coord2FieldKey = $form_state->getValue('coord2_field');
        $precisionFieldKey = $form_state->getValue(['precision_field']);
        break;
    }

    if (isset($idFieldKey) && $idFieldKey != 'auto') {
      unset($unusedColumns[$idFieldKey]);
    }
    if (isset($dateFieldKey)) {
      unset($unusedColumns[$dateFieldKey]);
    }
    if (isset($tvkFieldKey)) {
      unset($unusedColumns[$tvkFieldKey]);
    }
    if (isset($coord1FieldKey)) {
      unset($unusedColumns[$coord1FieldKey]);
    }
    if (isset($coord2FieldKey)) {
      unset($unusedColumns[$coord2FieldKey]);
    }
    if (isset($precisionFieldKey) && $precisionFieldKey != 'manual') {
      unset($unusedColumns[$precisionFieldKey]);
    }

    return $unusedColumns;
  }

  /**
   * {@inheritdoc}
   *
   * Receives a file object ID in $form_state['values'] that represents the ID
   * of the new file in the {file_managed} table
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    //parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Receives a file object ID in $form_state['values'] that represents the ID
   * of the new file in the {file_managed} table
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Submissions are handled by the submitHandlerHelper service.
  }
}
