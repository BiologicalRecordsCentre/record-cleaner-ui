<?php

namespace Drupal\record_cleaner\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\record_cleaner\Service\CsvHelper;
use Drupal\record_cleaner\Service\ApiHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CSV file upload form.
 */
class RecordCleanerUI extends FormBase {

  use DependencySerializationTrait;

  public $steps =  [
    'upload', 'mapping', 'sref', 'additional', 'validate', 'verify'
  ];

  /**
   * Constructs a new FileExampleReadWriteForm object.
   *
   * @param \Drupal\record_cleaner\Service\ApiHelper $apiHelper
   *   The record_cleaner API helper service.
   * @param \Drupal\record_cleaner\Service\CsvHelper $csvHelper
   *   The record_cleaner csv file service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger service for logging messages.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\File\FileUrlGenerator $fileUrlGenerator
   *   The file URL generator service.
   *
   * @see https://php.watch/versions/8.0/constructor-property-promotion
   */
  public function __construct(
    protected ApiHelper $apiHelper,
    protected CsvHelper $csvHelper,
    protected LoggerChannelInterface $logger,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManager $entityTypeManager,
    protected FileUrlGenerator $fileUrlGenerator,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static (
      $container->get('record_cleaner.api_helper'),
      $container->get('record_cleaner.csv_helper'),
      $container->get('record_cleaner.logger_channel'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
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
      case 'additional':
        return $this->buildAdditionalForm($form, $form_state);
        break;
      case 'validate':
        return $this->buildValidateForm($form, $form_state);
        break;
      case 'verify':
        return $this->buildVerifyForm($form, $form_state);
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
      '#upload_location' => 'private://' . $this->currentUser->id(),
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
    $fileUri = $file->getFileUri();

    // Store upload values.
    $form_state->set('upload_values', [
      'file_upload' => $form_state->getValue('file_upload'),
      'fid' => $fid,
      'uri' => $fileUri,
    ]);

    // Get a list of columns in the file.
    $fileColumns = $this->csvHelper->getColumns($fileUri);
    $form_state->set('file_columns', $fileColumns);

    // Log the uploaded file.
    $this->logger->notice(
      $this->t("File uploaded: %file (fid=%fid)"),
      ['%file' => $fileUri, '%fid' => $fid]
    );

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

    $key = $form_state->getValue('vc_field');
    $option = isset($key) ?
      [$key => $fileColumns[$key]] : [];
    $vcFieldOptions = $option + $unusedColumns;
    ksort($vcFieldOptions);

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
      '#description' => $this->t("If present, please select the field in the
      source data which represents the unique record key. If not present,
      select Auto Row Number."),
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

    $form['mappings']['vc_field'] = [
      '#type' => 'select',
      '#title' => $this->t("Vice County"),
      '#description' => $this->t("If present, please select the field in the
      source data which holds the vice county. This can be a valid name or
      number."),
      '#empty_option' => $this->t('- Select -'),
      '#options' => $vcFieldOptions,
      '#default_value' => $form_state->getValue('vc_field'),
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
      'vc_field' => $form_state->getValue('vc_field'),
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
      '#value' => $this->t('Next'),
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

/********************* ADDITIONAL FIELDS FORM *********************/
  public function buildAdditionalForm(array $form, FormStateInterface $form_state) {
    $unusedColumns = $this->getUnusedColumns($form_state);
    if (count($unusedColumns) == 0) {
      $form = [
        '#type' => 'item',
        '#title' => $this->t('Optional Fields'),
        '#description' => $this->t('There are no additional fields in the file
        for selection. Please proceed to the next step.'),
      ];
    }
    else {
      // TODO: Possible bug if column zero is unused.
      // See https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Render%21Element%21Checkboxes.php/class/Checkboxes/10
      $form['additional_fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Optional Fields'),
        '#description' => $this->t('Please select any additional fields from the
        file that you would like included in the output dataset.'),
        '#options' => $unusedColumns,
       '#default_value' => $form_state->getValue('additional_fields', []),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backFromAdditionalForm'],
      //#limit_validation_errors will break things.
    ];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      '#submit' => ['::forwardFromAdditionalForm'],
      //'#validate' => ['::validateSrefForm'],
    ];

    return $form;

  }

  public function backFromAdditionalForm(array &$form, FormStateInterface $form_state) {
    $this->saveAdditionalValues($form_state);
    $this->moveBack($form_state);
  }

  public function forwardFromAdditionalForm(array &$form, FormStateInterface $form_state) {
    $this->saveAdditionalValues($form_state);
    $this->moveForward($form_state);
  }

  public function saveAdditionalValues(FormStateInterface $form_state) {
    $form_state->set('additional_values', [
      'additional_fields' => $form_state->getValue('additional_fields'),
    ]);
  }

/********************* VALIDATION FORM *********************/
  public function buildValidateForm(array $form, FormStateInterface $form_state) {

    // Check for a file entity to store validated results.
    if (!$form_state->has('file_validate')) {
      // Obtain the input file URI (private://{userid}/{filename}).
      $fileInUri =  $form_state->get(['upload_values', 'uri']);
      // Create an output file URI by appending _validate to the input URI.
      // -4 means before the '.csv' characters.
      $fileOutUri = substr_replace($fileInUri, '_validate', -4, 0);
      // Create a file entity for the output file.
      $fileOut = File::create([
        'uri' => $fileOutUri,
      ]);
      $fileOut->setOwnerId($this->currentUser->id());
      $fileOut->save();
      // Save the output file information in the form_state storage as there
      // are no inputs to propagate it in form_state values.
      $form_state->set('file_validate', [
        'fid' => $fileOut->id(),
        'uri' => $fileOutUri,
      ]);
    }

    $form['validate'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'record_cleaner_validate',
      ],
    ];

    $form['validate']['result'] = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => print_r(
        $form_state->get('mapping_values') +
        $form_state->get('sref_values'), TRUE
      ),
    ];

    // Add a link to the validated file.
    $url = $this->fileUrlGenerator->generateAbsoluteString(
      $form_state->get(['file_validate', 'uri'])
    );
    $form['validate']['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Validated file'),
      '#url' => Url::fromUri($url),
      '#states' => ['visible' =>
        ['input[name="validated"]' => ['value' => '1']],
      ],
    ];

    // Add a hidden input to control state of other items.
    $form['validate']['validated'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->getValue('validated', '0'),
    ];

    // 'actions' are within the 'validate' container in order for the
    // Next button to change state after the validation callback.
    $form['validate']['actions'] = [
      '#type' => 'actions',
    ];

    $form['validate']['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backFromValidateForm'],
    ];

    $form['validate']['actions']['validate'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Validate'),
      '#ajax' => [
        'callback' => '::validateCallback',
        'wrapper' => 'record_cleaner_validate',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Validating...'),
        ],
      ],
      //'#submit' => ['::validate'],
      //'#validate' => ['::validateSrefForm'],
    ];

    $form['validate']['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      '#states' => ['enabled' =>
        ['input[name="validated"]' => ['value' => '1']]
      ],
      '#submit' => ['::forwardFromValidateForm'],
    ];

    return $form;
  }

  public function backFromValidateForm(array &$form, FormStateInterface $form_state) {
    $this->saveValidateValues($form_state);
    $this->moveBack($form_state);
  }

  public function forwardFromValidateForm(array &$form, FormStateInterface $form_state) {
    $this->saveValidateValues($form_state);
    $this->moveForward($form_state);
  }

  public function saveValidateValues(FormStateInterface $form_state) {
    $form_state->set('validate_values', [
      'validated' => $form_state->getValue('validated'),
    ]);
  }

  public function validateCallback(array &$form, FormStateInterface $form_state) {
    // Bundle all settings needed for validation.
    $settings['upload'] = $form_state->get('upload_values');
    $settings['validate'] = $form_state->get('file_validate');
    $settings += $form_state->get('mapping_values') +
      $form_state->get('sref_values');

    // Send to the csv helper service.
    $errors = $this->csvHelper->validate($settings);

    // Output results.
    if (count($errors) == 0) {
      $result = 'Validation successful.';
      $validated = '1';
    }
    else {
      $result = print_r($errors, TRUE);
      $validated = '0';
    }

    $form_state->setValue('validated', $validated);

    $form['validate']['result']['#value'] = $result;
    $form['validate']['validated']['#value'] = $validated;
    return $form['validate'];

  }

/********************* VERIFICATION FORM *********************/
  public function buildVerifyForm(array $form, FormStateInterface $form_state) {

    // Check for a file entity to store verified results.
    if (!$form_state->has('file_verify')) {
      // Obtain the input file URI (private://{userid}/{filename}).
      $fileInUri =  $form_state->get(['upload_values', 'uri']);
      // Create an output file URI by appending _verify to the input URI.
      // -4 means before the '.csv' characters.
      $fileOutUri = substr_replace($fileInUri, '_verify', -4, 0);
      // Create a file entity for the output file.
      $fileOut = File::create([
        'uri' => $fileOutUri,
      ]);
      $fileOut->setOwnerId($this->currentUser->id());
      $fileOut->save();
      // Save the output file information in the form_state storage as there
      // are no inputs to propagate it in form_state values.
      $form_state->set('file_verify', [
        'fid' => $fileOut->id(),
        'uri' => $fileOutUri,
      ]);
    }

    // Container for all org group rules.
    // Tree ensures naming of elements is unique.
    $form['rules'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rules'),
      '#description' => $this->t('Select the verification tests you want to run.'),
      '#description_display' => 'before',
      '#attributes' => [
        'class' => ['record-cleaner-organisation-container'],
      ],
      '#tree' => TRUE,
    ];
    // Attach CSS to format checkbox hierarchy.
    $form['rules']['#attached']['library'][] = 'record_cleaner/record_cleaner';

    // Get the organisation-group-rules from the service.
    $orgGroupRules = $this->apiHelper->orgGroupRules();

    // We build a hierarchy of checkboxes for organisation, groups and rules.
    // We use #ajax to deselect and children when the parent is unchecked.
    foreach ($orgGroupRules as $organisation => $groupRules) {

      // Create an id for the group container for Ajax to target.
      // Id must not contain spaces or ajax fails.
      $groupContainer = "$organisation groups";
      $groupContainerId = Html::getId($groupContainer);

      // Organisation checkbox.
      $orgValue = $form_state->getValue(['rules', $organisation]);
      $form['rules'][$organisation] = [
        '#type' => 'checkbox',
        '#title' => $organisation,
        '#default_value' => $orgValue,
        '#ajax' => [
          'callback' => '::uncheckChildren',
          'event' => 'change',
          'wrapper' => $groupContainerId,
        ]
      ];
      $orgInput = "input[name=\"rules[$organisation]\"]";

      // Container for groups of organisation.
      $form['rules'][$groupContainer] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => $groupContainerId,
          'class' => ['record-cleaner-group-container'],
        ],
      ];

      // Hide group container if organisation is deselected.
      if (!$orgValue) {
        $form['rules'][$groupContainer]['#attributes']['class'][] = 'hidden';
      }

      foreach ($groupRules as $group => $rules) {

        // Create an id for the rule container for Ajax to target.
        // Id must not contain spaces or ajax fails.
        $ruleContainer = "$group rules";
        $ruleContainerId = Html::getId($ruleContainer);

        // Group checkbox.
        $groupValue =  $form_state->getValue(
          ['rules', "$organisation groups", $group]
        );
        $form['rules'][$groupContainer][$group] = [
          '#type' => 'checkbox',
          '#title' => $group,
          '#default_value' => $groupValue,
          '#ajax' => [
            'callback' => '::uncheckChildren',
            'event' => 'change',
            'wrapper' => $ruleContainerId,
          ]
        ];
        $groupInput = "input[name=\"rules[$groupContainer][$group]\"]";

        // Deselect group if organisation is deselected.
        if (!$orgValue) {
          $form['rules'][$groupContainer][$group]['#value'] = 0;
        }

        // Container for rules of group.
        $form['rules'][$groupContainer][$ruleContainer] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => $ruleContainerId,
            'class' => ['record-cleaner-rule-container'],
          ],
        ];

        // Hide rule container if organisation or group is deselected.
        if (!$orgValue ||!$groupValue) {
          $form['rules'][$groupContainer][$ruleContainer]['#attributes']['class'][] = 'hidden';
        }

        foreach ($rules as $rule) {
          // Rule checkbox.
          $form['rules'][$groupContainer][$ruleContainer][$rule] = [
            '#type' => 'checkbox',
            '#title' => $rule,
            '#default_value' => $form_state->getValue(
              ['rules', "$organisation groups", "$group rules", $rule]
            ),
          ];

          // Deselect rules if group or organisation is deselected.
          if (!$orgValue ||!$groupValue) {
            $form['rules'][$groupContainer][$ruleContainer][$rule]['#value'] = 0;
          }
        }
      }
    }

    $form['verify'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'record_cleaner_verify',
      ],
    ];

    $form['verify']['result'] = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => print_r(
        $form_state->get('mapping_values') +
        $form_state->get('sref_values'), TRUE
      ),
    ];

    // Add a link to the verified file.
    $url = $this->fileUrlGenerator->generateAbsoluteString(
      $form_state->get(['file_verify', 'uri'])
    );
    $form['verify']['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Verified file'),
      '#url' => Url::fromUri($url),
      '#states' => ['visible' =>
        ['input[name="verified"]' => ['value' => '1']],
      ],
    ];

    // Add a hidden input to control state of other items.
    $form['verify']['verified'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->getValue('verified', '0'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backFromVerifyForm'],
    ];

    $form['actions']['verify'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Verify'),
      '#ajax' => [
        'callback' => '::verifyCallback',
        'wrapper' => 'record_cleaner_verify',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Verifying...'),
        ],
      ],
      //'#submit' => ['::validate'],
      //'#validate' => ['::validateSrefForm'],
    ];

    return $form;
  }

  public function uncheckChildren(array &$form, FormStateInterface $form_state) {
    $triggeredElement = $form_state->getTriggeringElement();
    // Locate the container of the child elements.
    $parents = $triggeredElement['#array_parents'];
    if (count($parents) == 2) {
      $container = $form[$parents[0]][$parents[1] . ' groups'];
    }
    elseif (count($parents) == 3) {
      $container = $form[$parents[0]][$parents[1]][$parents[2] . ' rules'];
    }

    return $container;
  }

  public function backFromVerifyForm(array &$form, FormStateInterface $form_state) {
    $this->saveVerifyValues($form_state);
    $this->moveBack($form_state);
  }

  public function saveVerifyValues(FormStateInterface $form_state) {
    $form_state->set('verify_values', [
      'rules' => $form_state->getValue('rules'),
      'verified' => $form_state->getValue('verified'),
    ]);
  }

  public function verifyCallback(array &$form, FormStateInterface $form_state) {

    // Construct org_group_rules_list required by API.
    $orgGroupRules = [];
    $orgContainer = $form['rules'];
    foreach(Element::children($orgContainer) as $orgElementKey) {
      $orgElement = $orgContainer[$orgElementKey];
      if ($orgElement['#type'] == 'checkbox' && $orgElement['#value'] == 1) {
        $organisation = $orgElementKey;

        $groupContainer = $form['rules']["$organisation groups"];
        foreach(Element::children($groupContainer) as $groupElementKey) {
          $groupElement = $groupContainer[$groupElementKey];
          if ($groupElement['#type'] == 'checkbox' && $groupElement['#value'] == 1) {
            $group = $groupElementKey;

            $ruleContainer = $groupContainer["$group rules"];
            $rules = [];
            foreach(Element::children($ruleContainer) as $ruleElementKey) {
              $ruleElement = $ruleContainer[$ruleElementKey];
              if ($ruleElement['#type'] == 'checkbox' && $ruleElement['#value'] == 1) {
                // Convert '{Ruletype} Rule' to {ruletype}
                $rule = strtolower(explode(' ', $ruleElementKey)[0]);
                $rules[] = $rule;
              }
            }

            if (count($rules) > 0) {
              $orgGroupRules[] = [
                'organisation' => $organisation,
                'group' => $group,
                'rules' => $rules,
              ];
            }
          }
        }
      }
    }

    // Bundle all settings needed for verification.
    $settings['validate'] = $form_state->get('file_validate');
    $settings['verify'] = $form_state->get('file_verify');
    $settings['org_group_rules'] = $orgGroupRules;
    $settings += $form_state->get('mapping_values');
    $settings += $form_state->get('sref_values') ;

  // Send to the csv helper service.
  $errors = $this->csvHelper->verify($settings);

  // Output results.
  if (count($errors) == 0) {
    $result = 'Verification successful.';
    $verified = '1';
  }
  else {
    $result = print_r($errors, TRUE);
    $verified = '0';
  }

  $form_state->setValue('verified', $verified);

  $form['verify']['result']['#value'] = $result;
  $form['verify']['verified']['#value'] = $verified;
  return $form['verify'];

}
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

    $idFieldKey = $form_state->getValue('id_field') ??
      $form_state->get(['mapping_values', 'id_field']);
    $dateFieldKey = $form_state->getValue('date_field') ??
      $form_state->get(['mapping_values', 'date_field']);
    $tvkFieldKey = $form_state->getValue('tvk_field') ??
      $form_state->get(['mapping_values', 'tvk_field']);
    $vcFieldKey = $form_state->getValue('vc_field') ??
      $form_state->get(['mapping_values', 'vc_field']);
    $coord1FieldKey = $form_state->getValue('coord1_field') ??
      $form_state->get(['sref_values', 'coord1_field']);
    $coord2FieldKey = $form_state->getValue('coord2_field') ??
      $form_state->get(['sref_values', 'coord2_field']);
    $precisionFieldKey = $form_state->getValue(['precision_field']) ??
      $form_state->get(['sref_values', 'precision_field']);

    if (isset($idFieldKey) && $idFieldKey != 'auto') {
      unset($unusedColumns[$idFieldKey]);
    }
    if (isset($dateFieldKey)) {
      unset($unusedColumns[$dateFieldKey]);
    }
    if (isset($tvkFieldKey)) {
      unset($unusedColumns[$tvkFieldKey]);
    }
    if (isset($vcFieldKey)) {
      unset($unusedColumns[$vcFieldKey]);
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

    // On the additional step we want to show any columns that are not selected
    // as mapping on to a standard field. However, if we select them on
    // the additional step, we don't want them being available for selection
    // on previous steps.
    $step = $this->steps[$form_state->get('step_num')];
    if ($step != 'additional') {
      $additionalFieldsKeys = $form_state->get(['additional_values', 'additional_fields']);
      if (isset($additionalFieldsKeys)) {
        foreach ($additionalFieldsKeys as $key) {
          unset($unusedColumns[$key]);
        }
      }
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
