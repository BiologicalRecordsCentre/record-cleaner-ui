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
    'upload', 'mapping', 'organism', 'sref', 'additional', 'validate', 'verify'
  ];

  public $gridSystems;

  public $enSystems;

  public $latlonSystems;

  /**
   * Constructs a new RecordCleanerUI object.
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
    $this->gridSystems = [
      '27700' => $this->t('British gridref (e.g.SM123456)'),
      '29903' => $this->t('Irish gridref (e.g. G123456)'),
      '23030' => $this->t('Channel Islands gridref (e.g. WA/WV)'),
      '0' => $this->t('British, Irish or CI gridref'),
    ];

    $this->enSystems = [
      '27700' => $this->t('British'),
      '29903' => $this->t('Irish'),
    ];

    $this->latlonSystems = [
      '4326' => $this->t('World Geodetic System (WGS84)'),
    ];
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
      case 'organism':
        return $this->buildOrganismForm($form, $form_state);
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
    ]);
    $form_state->set('file_upload', [
      'fid' => $fid,
      'uri' => $fileUri,
    ]);

    // Get a list of columns in the file.
    $fileColumns = $this->csvHelper->getColumns($fileUri);
    $form_state->set(['file_upload', 'columns'], $fileColumns);

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
    $fileColumns = $form_state->get(['file_upload', 'columns']);
    $unusedColumns = $this->getUnusedColumns($form_state);

    $key = $form_state->getValue('id_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $idFieldOptions = $option + $unusedColumns;
    ksort($idFieldOptions);

    $key = $form_state->getValue('date_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $dateFieldOptions = $option + $unusedColumns;
    ksort($dateFieldOptions);

    $key = $form_state->getValue('vc_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $vcFieldOptions = $option + $unusedColumns;
    ksort($vcFieldOptions);

    $key = $form_state->getValue('stage_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $stageFieldOptions = $option + $unusedColumns;
    ksort($stageFieldOptions);

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

    $form['mappings']['stage_field'] = [
      '#type' => 'select',
      '#title' => $this->t("Life Stage"),
      '#description' => $this->t("If present, please select the field in the
      source data which holds the life stage. This can be a valid name or
      number."),
      '#empty_option' => $this->t('- Select -'),
      '#options' => $stageFieldOptions,
      '#default_value' => $form_state->getValue('stage_field'),
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
      'vc_field' => $form_state->getValue('vc_field'),
      'stage_field' => $form_state->getValue('stage_field'),
    ]);
  }

/********************* ORGANISM FORM *********************/
  public function buildOrganismForm(array $form, FormStateInterface $form_state) {

    $organismType = $form_state->getValue('organism_type');

    // Determine title and description for organism form elements.
    $title = $description = '';
    switch ($organismType) {
      case 'tvk':
        $title = $this->t("TVK");
        $description = $this->t("Please select the column in the source data
        which contains the taxon version key.");
          break;
      case 'name':
        $title = $this->t("Name");
        $description = $this->t("Please select the column in the source data
        which contains the taxon name.");
          break;
    }

    // Each of the selectors which map columns in the CSV file to the
    // required fields has its options recalculated every time the form is
    // built. The options are keyed by column number and the value is the
    // column heading from the first row of the CSV file.
    $fileColumns = $form_state->get(['file_upload', 'columns']);
    $unusedColumns = $this->getUnusedColumns($form_state);

    // Create sorted list of options for tvk.
    $key = $form_state->getValue('organism_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $OrganismFieldOptions = $option + $unusedColumns;
    ksort($OrganismFieldOptions);

    // Create each of the form elements.
    $selectOrganismType = [
      '#type' => 'select',
      '#title' => $this->t("Taxon Field Type"),
      '#description' => $this->t("Please select the taxon field type."),
      '#required' => TRUE,
      '#options' => [
        'tvk' => $this->t('Taxon Version Key (e.g.NHMSYS0000530739)'),
        'name' => $this->t('Taxon Name (e.g. Erithacus rubecula or Robin)'),
      ],
      '#default_value' => $form_state->getValue('organism_type'),
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

    $selectOrganism = [
      '#type' => 'select',
      '#title' => $title,
      '#description' => $description,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => $OrganismFieldOptions,
      '#default_value' => $form_state->getValue('organism_field'),
      '#ajax' => [
        'callback' => '::mappingChangeCallback',
        'event' => 'change',
        'wrapper' => 'record_cleaner_mappings',
      ]
    ];

    // Build the form according to current selections.
    $form['organism_type'] = $selectOrganismType;
    $form['mappings'] = $containerMappings;

    if (isset($organismType)) {
      $form['mappings']['organism_field'] = $selectOrganism;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backFromOrganismForm'],
      //#limit_validation_errors will break things.
    ];
    $form['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      '#submit' => ['::forwardFromOrganismForm'],
      //'#validate' => ['::validateOrganismForm'],
    ];

    return $form;
  }

  public function backFromOrganismForm(array &$form, FormStateInterface $form_state) {
    $this->saveOrganismValues($form_state);
    $this->moveBack($form_state);
  }

  public function forwardFromOrganismForm(array &$form, FormStateInterface $form_state) {
    $this->saveOrganismValues($form_state);
    $this->moveForward($form_state);
  }

  public function saveOrganismValues(FormStateInterface $form_state) {
    $form_state->set('organism_values', [
      'organism_type' => $form_state->getValue('organism_type'),
      'organism_field' => $form_state->getValue('organism_field'),
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
    $fileColumns = $form_state->get(['file_upload', 'columns']);
    $unusedColumns = $this->getUnusedColumns($form_state);

    // Create sorted list of options for coord1.
    $key = $form_state->getValue('coord1_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $coord1FieldOptions = $option + $unusedColumns;
    ksort($coord1FieldOptions);

    // Create sorted list of options for coord2.
    $key = $form_state->getValue('coord2_field');
    $option = is_numeric($key) ?
      [$key => $fileColumns[$key]] : [];
    $coord2FieldOptions = $option + $unusedColumns;
    ksort($coord2FieldOptions);

    // Create sorted list of options for precision.
    $key = $form_state->getValue('precision_field');
    $option = is_numeric($key)  ?
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
      '#options' => $this->gridSystems,
      '#default_value' => $form_state->getValue('sref_grid'),
    ];

    $selectEn = [
      '#type' => 'select',
      '#title' => $this->t("Coordinate System"),
      '#description' => $this->t("Please select the coordinate system."),
      '#required' => TRUE,
      '#options' => $this->enSystems,
      '#default_value' => $form_state->getValue('sref_en'),
    ];

    $selectLatLon = [
      '#type' => 'select',
      '#title' => $this->t("Latitude and Longitude System"),
      '#description' => $this->t("Please select the latitude and longitude type."),
      '#required' => TRUE,
      '#options' => $this->latlonSystems,
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
      $fileInUri =  $form_state->get(['file_upload', 'uri']);
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

    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Settings Summary'),
      '#open' =>  TRUE,
    ];

    $form['settings']['summary'] = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['record-cleaner-table'],
      ],
      '#header' => ['Setting', 'Value'],
      '#rows' => $this->getSettingsSummary($form_state),
    ];
    // Attach CSS to format table.
    $form['settings']['#attached']['library'][] = 'record_cleaner/record_cleaner';

    $form['validate'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'record_cleaner_validate',
      ],
    ];

    $form['validate']['output'] = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => '',
    ];

    // Add a hidden input to control state of other items.
    // There is no change event for this input but it is taken in to account
    // when Ajax content is loaded and initial states are set.
    // It has a value of 0 which is changed to pass or fail after validation.
    $form['validate']['result'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->getValue('result', '0'),
    ];

    // Add a link to the validated file shown after validation.
    $url = $this->fileUrlGenerator->generateAbsoluteString(
      $form_state->get(['file_validate', 'uri'])
    );
    $form['validate']['link'] = [
      '#type' => 'link',
      '#title' => $this->t('Validated file'),
      '#url' => Url::fromUri($url),
      '#states' => ['visible' =>
        ['input[name="result"]' => ['!value' => '0']],
      ],
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
    ];

    $form['validate']['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      '#states' => ['enabled' =>
        ['input[name="result"]' => ['value' => 'pass']]
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
      'result' => $form_state->getValue('result'),
    ]);
  }

  public function validateCallback(array &$form, FormStateInterface $form_state) {
    // Store mappings of function to column in uploaded file.
    // Note, this is not sticking in the form_state.
    $mappings = $this->getUploadMappings($form_state);
    $form_state->set(['file_upload', 'mappings'], $mappings);
    // Determine columns in output file.
    $columns = $this->getValidateColumns($form_state);
    $form_state->set(['file_validate', 'columns'], $columns);

    return $this->submitCallback($form, $form_state, 'validate');
  }

/********************* VERIFICATION FORM *********************/
  public function buildVerifyForm(array $form, FormStateInterface $form_state) {

    // Check for a file entity to store verified results.
    if (!$form_state->has('file_verify')) {
      // Obtain the input file URI (private://{userid}/{filename}).
      $fileInUri =  $form_state->get(['file_upload', 'uri']);
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

    $form['verify']['output'] = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => '',
    ];

    // Add a hidden input to control state of other items.
    // There is no change event for this input but it is taken in to account
    // when Ajax content is loaded and initial states are set.
    // It has a value of 0 which is changed to pass or fail after verification.
    $form['verify']['result'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->getValue('result', '0'),
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
        ['input[name="result"]' => ['!value' => '0']],
      ],
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
      'result' => $form_state->getValue('result'),
    ]);
  }

  public function verifyCallback(array &$form, FormStateInterface $form_state) {
    // Store mappings of function to column in validated file.
    // Note, I wasn't expecting to have to do calculate columns again but it is
    // not sticking in the form_state when calculated for validation.
    $validateColumns = $this->getValidateColumns($form_state);
    $mappings = $this->getValidateMappings($validateColumns);
    $form_state->set(['file_validate', 'mappings'], $mappings);
    // Determine columns in output file.
    $columns = $this->getVerifyColumns($validateColumns);
    $form_state->set(['file_verify', 'columns'], $columns);

    return $this->submitCallback($form, $form_state, 'verify');
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

  public function submitCallback(
    array &$form, FormStateInterface $form_state, $action) {
    if ($action == 'validate') {
      $source = 'file_upload';
      $output = 'file_validate';
    }
    else {
      $source = 'file_validate';
      $output = 'file_verify';
    }

    // Bundle all settings needed for submitting to service..
    $settings['action'] = $action;
    $settings['source'] = $form_state->get($source);
    $settings['output'] = $form_state->get($output);
    $settings['sref'] = [
      'type' => $form_state->get(['sref_values', 'sref_type']),
      'nr_coords' => $form_state->get(['sref_values', 'nr_coords']),
      'precision_value' => $form_state->get(['sref_values', 'precision_value']),
    ];
    switch ($settings['sref']['type']) {
      case 'grid':
        $srid = $form_state->get(['sref_values', 'sref_grid']);
        break;
      case 'en':
        $srid = $form_state->get(['sref_values', 'sref_en']);
        break;
      case 'latlon':
        $srid = $form_state->get(['sref_values', 'sref_latlon']);
        break;
    }
    $settings['sref']['srid'] = $srid;

    if ($action == 'verify') {
      $settings['org_group_rules'] = $this->getOrgGroupRules($form);
    }

    // Send to the csv helper service.
    $errors = $this->csvHelper->submit($settings);

    // Output results.
    if (count($errors) == 0) {
      $output = "$action successful.";
      $result = 'pass';
    }
    else {
      $output = print_r($errors, TRUE);
      $result = 'fail';
    }

    $form_state->setValue('result', $result);

    $form[$action]['output']['#value'] = $output;
    $form[$action]['result']['#value'] = $result;
    return $form[$action];

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
    $unusedColumns = $form_state->get(['file_upload', 'columns']);

    $idFieldKey = $form_state->getValue('id_field') ??
      $form_state->get(['mapping_values', 'id_field']);
    $dateFieldKey = $form_state->getValue('date_field') ??
      $form_state->get(['mapping_values', 'date_field']);
    $vcFieldKey = $form_state->getValue('vc_field') ??
      $form_state->get(['mapping_values', 'vc_field']);
    $stageFieldKey = $form_state->getValue('stage_field') ??
      $form_state->get(['mapping_values', 'stage_field']);
    $organismFieldKey = $form_state->getValue('organism_field') ??
      $form_state->get(['organism_values', 'organism_field']);
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
    if (isset($vcFieldKey)) {
      unset($unusedColumns[$vcFieldKey]);
    }
    if (isset($stageFieldKey)) {
      unset($unusedColumns[$stageFieldKey]);
    }
    if (isset($organismFieldKey)) {
      unset($unusedColumns[$organismFieldKey]);
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

  public function getSettingsSummary(FormStateInterface $form_state) {
    $summary = [];
    $fileColumns = $form_state->get(['file_upload', 'columns']);

    // MAPPING VALUES.
    $title = $this->t("Unique Record Key Field");
    $colNum = $form_state->get(['mapping_values', 'id_field']);
    $value = (($colNum == 'auto') ?
      $this->t('Auto Row Number') : $fileColumns[$colNum]
    );
    $summary[] = [$title, $value];

    $title = $this->t("Date Field");
    $colNum = $form_state->get(['mapping_values', 'date_field']);
    $summary[] = [$title, $fileColumns[$colNum]];

    $title = $this->t("Vice County Field");
    $colNum = $form_state->get(['mapping_values', 'vc_field']);
    if (is_numeric($colNum)) {
      $summary[] = [$title, $fileColumns[$colNum]];
    }

    $title = $this->t("Life Stage Field");
    $colNum = $form_state->get(['mapping_values', 'stage_field']);
    if (is_numeric($colNum)) {
      $summary[] = [$title, $fileColumns[$colNum]];
    }

    // ORGANISM VALUES.
    $organismType = $form_state->get(['organism_values', 'organism_type']);
    switch ($organismType) {
      case 'tvk':
        $title = $this->t("Organism Field Type");
        $summary[] = [$title, $this->t('Taxon Version Key')];
        $title = $this->t("TVK Field");
        $colNum = $form_state->get(['organism_values', 'organism_field']);
        $summary[] = [$title, $fileColumns[$colNum]];
        break;
      case 'name':
        $title = $this->t("Organism Field Type");
        $summary[] = [$title, $this->t('Taxon Name')];
        $title = $this->t("Taxon Name Field");
        $colNum = $form_state->get(['organism_values', 'organism_field']);
        $summary[] = [$title, $fileColumns[$colNum]];
        break;
    }

    // SREF VALUES.
    $srefType = $form_state->get(['sref_values', 'sref_type']);
    switch ($srefType) {
      case 'grid':
        $title = $this->t("Spatial Reference Type");
        $summary[] = [$title, $this->t('Grid Reference')];

        $title = $this->t("Grid Reference System");
        $system = $form_state->get(['sref_values', 'sref_grid']);
        $summary[] = [$title,$this->gridSystems[$system]];

        $title = $this->t("Grid Reference Field");
        $colNum = $form_state->get(['sref_values', 'coord1_field']);
        $summary[] = [$title, $fileColumns[$colNum]];

        break;
      case 'en':
        $title = $this->t("Spatial Reference Type");
        $summary[] = [$title, $this->t('Easting and Northing')];

        $title = $this->t("Coordinate System");
        $system = $form_state->get(['sref_values', 'sref_en']);
        $summary[] = [$title, $this->enSystems[$system]];

        switch ($form_state->get(['sref_values', 'nr_coords'])) {
          case '1':
            $title = $this->t("Coordinate Field");
            $colNum = $form_state->get(['sref_values', 'coord1_field']);
            $summary[] = [$title, $fileColumns[$colNum]];
            break;
          case '2':
            $title = $this->t("Easting Field");
            $colNum = $form_state->get(['sref_values', 'coord1_field']);
            $summary[] = [$title, $fileColumns[$colNum]];

            $title = $this->t("Northing Field");
            $colNum = $form_state->get(['sref_values', 'coord2_field']);
            $summary[] = [$title, $fileColumns[$colNum]];
            break;
        }

        $colNum = $form_state->get(['sref_values', 'precision_field']);
        if ($colNum == 'manual') {
          $title = $this->t("Manual Precision");
          $value = $form_state->get(['sref_values', 'precision_value']);
          $summary[] = [$title, $value];
        }
        else {
          $title = $this->t("Precision Field");
          $summary[] = [$title, $fileColumns[$colNum]];
        }
        break;
      case 'latlon':
        $title = $this->t("Spatial Reference Type");
        $summary[] = [$title, $this->t('Lat/Lon')];


        $title = $this->t("Coordinate System");
        $system = $form_state->get(['sref_values', 'sref_latlon']);
        $summary[] = [$title, $this->latlonSystems[$system]];

        switch ($form_state->get(['sref_values', 'nr_coords'])) {
          case '1':
            $title = $this->t("Coordinate Field");
            $colNum = $form_state->get(['sref_values', 'coord1_field']);
            $summary[] = [$title, $fileColumns[$colNum]];
            break;
          case '2':
            $title = $this->t("Longitude Field");
            $colNum = $form_state->get(['sref_values', 'coord1_field']);
            $summary[] = [$title, $fileColumns[$colNum]];

            $title = $this->t("Latitude Field");
            $colNum = $form_state->get(['sref_values', 'coord2_field']);
            $summary[] = [$title, $fileColumns[$colNum]];
            break;
        }

        $colNum = $form_state->get(['sref_values', 'precision_field']);
        if ($colNum == 'manual') {
          $title = $this->t("Manual Precision");
          $value = $form_state->get(['sref_values', 'precision_value']);
          $summary[] = [$title, $value];
        }
        else {
          $title = $this->t("Precision Field");
          $summary[] = [$title, $fileColumns[$colNum]];
        }
        break;
    }

    // ADDITIONAL VALUES.
    $title = $this->t("Additional Fields");
    $colNums = $form_state->get(['additional_values', 'additional_fields']);
    $values = [];
    foreach($colNums as $colNum) {
      if (is_numeric($colNum) && is_string($colNum)) {
        $values[] = $fileColumns[$colNum];
      }
    }
    $value = implode(", ", $values);
    if (strlen($value) > 0) {
      $summary[] = [$title, $value];
    }

    return $summary;
  }

  /**
   * Determine the columns in the validation output file.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array An array of columns in the order they appear in the file.
   * Each element is an array [
   *   'name' => string The column title.
   *   'function' => string The purpose of the column.
   *   'column' => int|NULL The index of the column in the upload file.
   * ]
   *
   */
  public function getValidateColumns(FormStateInterface $form_state) {
    $mappings = [];
    $fields = [
      'id' => 'mapping_values',
      'date' => 'mapping_values',
      'vc' => 'mapping_values',
      'stage' => 'mapping_values',
      'organism' => 'organism_values',
      'coord1' =>'sref_values',
      'coord2' => 'sref_values',
      'precision' => 'sref_values',
    ];

    $organismType = $form_state->get(['organism_values', 'organism_type']);

    // Create a mappings array where the key is the column number in the source
    // file and the value is an array of the column name and its function.
    foreach($fields as $field => $store) {
      $colNum = $form_state->get([$store, "{$field}_field"]);
      // A select value of '0' is valid but '' indicates not set.
      // An id of 'auto' or a precision of 'manual' means there is no mapping
      // for those fields.
      if (is_numeric($colNum)) {
        // Organism is a special case. We want to be able to pick out the tvk
        // column when it comes round to verification, whether it came from the
        // original file or the validation service.
        if ($field == 'organism' && $organismType == 'tvk') {
          $function = 'tvk';
        }
        elseif ($field == 'organism' && $organismType == 'name') {
          $function = 'name';
        }
        else {
          $function = $field;
        }

        $mappings[$colNum] = [
          'name' => $form_state->get(['file_upload', 'columns', $colNum]),
          'function' => $function,
        ];
      }
    }

    // Add mappings for additional fields.
    foreach($form_state->get(['additional_values', 'additional_fields']) as $colNum) {
      // A checkbox value of '0' is valid but 0 (int) indicates not set.
      if (is_string($colNum) && is_numeric($colNum)) {
        $mappings[$colNum] = [
          'name' => $form_state->get(['file_upload', 'columns', $colNum]),
          'function' => 'additional',
        ];
      }
    }

    // Sort mappings by key so columns are output in same order as uploaded.
    ksort($mappings);

    // Build the full list of columns in validate file. The value is an array of
    // the column name, its function and its column number in the source file.
    $columns = [];

    // If auto-numbering rows, insert ID column first.
    if ($form_state->get(['mapping_values', 'id_field']) == 'auto') {
      $columns[] = [
        'name' => 'Id',
        'function' => 'id',
        'column' => NULL,
      ];
    }

    // Next append all the columns from the uploaded file.
    foreach($mappings as $colNum => $value) {
      $columns[] = $value + ['column' => $colNum];
    }

    // Append the columns returned from the service.
    if ($organismType == 'tvk') {
      $columns[] = [
        'name' => 'Name',
        'function' => 'name',
        'column' => NULL,
      ];
    }
    elseif ($organismType == 'name') {
      $columns[] = [
        'name' => 'TVK',
        'function' => 'tvk',
        'column' => NULL,
      ];
    }

    $columns[] = [
      'name' => 'Id Difficulty',
      'function' => 'id_difficulty',
      'column' => NULL,
    ];

    $columns[] = [
      'name' => 'Pass',
      'function' => 'ok',
      'column' => NULL,
    ];

    $columns[] = [
      'name' => 'Messages',
      'function' => 'messages',
      'column' => NULL,
    ];

    return $columns;
  }

  /**
   * Determine the columns in the verification output file.
   *
   * Validation and verification files are very similar.
   *
   * @param array $validateColumns
   *
   * @return array An array of columns in the order they appear in the file.
   * Each element is an array [
   *   'name' => string The column title.
   *   'function' => string The purpose of the column.
   *   'column' => int|NULL The index of the column in the validate file.
   * ]
   *
   */
  public function getVerifyColumns(array $validateColumns) {
    $columns = [];
    foreach($validateColumns as $colNum => $column) {
      // Omit ID difficulty from verification file.
      if ($column['function'] == 'id_difficulty') {
        continue;
      }
      $column['column'] = $colNum;
      $columns[] = $column;
    }

    return $columns;
  }

  /**
   * Create mapping from field to column number in validate file.
   *
   * This is needed to pick the correct data from the validated file to insert
   * in to the submission to the verification service.
   */
  public function getValidateMappings($columns) {
    $mappings = [];
    $functions = [
      'id', 'date', 'tvk', 'vc', 'stage', 'coord1', 'coord2', 'precision'
    ];
    foreach($columns as $colNum => $column) {
      $colFunction = $column['function'];
      if (in_array($colFunction, $functions)) {
        $mappings[$colFunction . '_field'] = $colNum;
      }
    }
    return $mappings;
  }

  /**
   * Create mapping from field to column number in uploaded file.
   *
   * This is needed to pick the correct data from the uploaded file to insert
   * in to the submission to the validation service.
   */
  public function getUploadMappings(FormStateInterface $form_state) {
    $mappings = [];
    $fields = [
      'id_field' => 'mapping_values',
      'date_field' => 'mapping_values',
      'vc_field' => 'mapping_values',
      'stage_field' => 'mapping_values',
      'organism_field' => 'organism_values',
      'coord1_field' =>'sref_values',
      'coord2_field' => 'sref_values',
      'precision_field' => 'sref_values',
    ];

    $organismType = $form_state->get(['organism_values', 'organism_type']);

    // Create a mappings array where the key is the function and the value is
    // the column number in the source file
    foreach($fields as $field => $store) {
      $colNum = $form_state->get([$store, "$field"]);
      // A select value of '0' is valid but '' indicates not set.
      // An id of 'auto' or a precision of 'manual' means there is no mapping
      // for those fields.
      if (is_numeric($colNum)) {
        // Organism is a special case where we rename fields.
        // Sorry this seems to have got quite convoluted.
        if ($field == 'organism_field' && $organismType == 'tvk') {
          $field = 'tvk_field';
        }
        elseif ($field == 'organism_field' && $organismType == 'name') {
          $field = 'name_field';
        }

        $mappings[$field] = $colNum;
      }
    }
    return $mappings;
  }

  public function getOrgGroupRules(array &$form) {
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

    return $orgGroupRules;
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
