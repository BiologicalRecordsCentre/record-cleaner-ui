<?php

namespace Drupal\record_cleaner\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\record_cleaner\Service\ApiHelper;
use Drupal\record_cleaner\Service\CookieHelper;
use Drupal\record_cleaner\Service\FileHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RecordCleanerValidated extends FormBase {

  use DependencySerializationTrait;

  public $steps =  [
    'validated', 'verify'
  ];

  /**
   * Constructs a new RecordCleanerValidated object.
   *
   * @param \Drupal\record_cleaner\Service\ApiHelper $apiHelper
   *   The record_cleaner API helper service.
   * @param \Drupal\record_cleaner\Service\CookieHelper $cookieHelper
   *   The cookie helper service.
   * @param \Drupal\record_cleaner\Service\FileHelper $fileHelper
   *   The record_cleaner file service.
   * @param \Drupal\Core\File\FileUrlGenerator $fileUrlGenerator
   *   The file URL generator service.
   *
   * @see https://php.watch/versions/8.0/constructor-property-promotion
   */
  public function __construct(
    protected ApiHelper $apiHelper,
    protected CookieHelper $cookieHelper,
    protected FileHelper $fileHelper,
    protected FileUrlGenerator $fileUrlGenerator,
  ) {}
    /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static (
      $container->get('record_cleaner.api_helper'),
      $container->get('record_cleaner.cookie_helper'),
      $container->get('record_cleaner.file_helper'),
      $container->get('file_url_generator'),
    );
  }

  public function getFormId() {
      return 'record_cleaner_validated';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // Proceed through multi-step form.
    if (!$form_state->has('step_num')) {
      $form_state->set('step_num', 0);
    }

    $step = $this->steps[$form_state->get('step_num')];

    switch ($step) {
      case 'validated':
        return $this->buildValidatedForm($form, $form_state);
        break;
      case 'verify':
        return $this->buildVerifyForm($form, $form_state);
        break;
      }
  }

/********************* VALIDATED FORM *********************/
  public function buildValidatedForm(array $form, FormStateInterface $form_state) {
    // Load Validation results from session.
    $request = $this->getRequest();
    $session = $request->getSession();
    $validateResult = $session->get('record_cleaner_validate_result');

    // Handle errors.
    $error = '';
    if (!$validateResult) {
      // No results in session.
      $error = $this->t("There are no validation results to display.");
    }
    elseif (array_key_exists('error', $validateResult)) {
      // An exception occurred in batch processing.
      $error =  $this->t("There was an error processing the file. If the problem
        persists, please contact the administrator. {$validateResult['error']}");
    }
    if ($error) {
      // Return to start
      $this->messenger()->addError($error);
      $url = Url::fromRoute('record_cleaner.ui')->toString();
      return new RedirectResponse($url);
    }

    $success = $validateResult['success'];
    $counts = $validateResult['counts'];
    $messages = $validateResult['messages'];

     // Display results.
    $form['validate']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' =>  'Validate ' .  ($success ? 'Passed' : 'Failed'),
    ];
    $form['validate']['count'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => "{$counts['total']} records were checked. <br/>" .
        "{$counts['pass']} records passed. <br/>" .
        "{$counts['warn']} records had warnings. <br/>" .
        "{$counts['fail']} records failed.",
      ];
    $form['validate']['messages'] = $this->fileHelper->getMessageSummary($messages);

    // Load form_state from session.
    $sess_state = $session->get('record_cleaner_state');
    if (!$sess_state) {
      $url = Url::fromRoute('record_cleaner.ui')->toString();
      return new RedirectResponse($url);
    }

    // Display a link to the output file.
    $url = $this->fileUrlGenerator->generateAbsoluteString(
      $sess_state['file_validate']['uri']
    );
    $form['validate']['link'] = [
      '#type' => 'link',
      '#url' => Url::fromUri($url),
      '#prefix' => '<p>' . $this->t('Please download the '),
      '#title' => $this->t("validate file"),
      '#suffix' => $this->t(' for more information. If you have errors, edit
        your data and re-upload to complete the checking process.') . '</p>',
    ];

    if (!$success){
      $form['validate']['continue'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Continue to verification'),
        '#description' => $this->t('Proceed with verification, dropping invalid
        records.'),
        '#default_value' => 0,
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Next'),
      // '#disabled' => !$success,
      '#states' => ['enabled' =>
        ['input[name="continue"]' => ['checked' => TRUE]]
      ],
      '#submit' => ['::forwardFromValidateForm'],
    ];

    $form['actions']['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start again'),
      '#submit' => ['::returnToStart'],
    ];

    return $form;

  }

  public function forwardFromValidateForm(array &$form, FormStateInterface $form_state) {
    // Nothing to save going forward.
    $this->moveForward($form_state);
  }

/********************* VERIFY FORM *********************/
  public function buildVerifyForm(array $form, FormStateInterface $form_state) {

    // Load form_state from session.
    $request = $this->getRequest();
    $session = $request->getSession();
     $sess_state = $session->get('record_cleaner_state');
    if (!$sess_state) {
      $url = Url::fromRoute('record_cleaner.ui')->toString();
      return new RedirectResponse($url);
    }

    // Check for a file entity to store verified results.
    if (!array_key_exists('file_verify', $sess_state) ||
        !array_key_exists('uri', $sess_state['file_verify'])) {
      // Obtain the input file URI (private://record-cleaner/{userid}/{filename}).
      $fileInUri =  $sess_state['file_upload']['uri'];
      // Create an output file URI by removing any extension and appending
      // _verify.csv to the input URI.
      $extPos = strrpos($fileInUri, '.', );
      if ($extPos === false) {
        // No extension.
        $fileOutUri = $fileInUri . '_verify.csv';
      }
      else {
        $fileOutUri = substr($fileInUri, 0, $extPos) . '_verify.csv';
      }
      // Create a file entity for the output file.
      $fileOut = File::create([
        'uri' => $fileOutUri,
      ]);
      $fileOut->setOwnerId($this->currentUser()->id());
      $fileOut->save();

      // If the user is anonymous, allow them to see the file during the
      // current session.
      // Ref. \Drupal\file\FileAccessControlHandler::checkAccess().
      if ($this->currentUser()->isAnonymous()) {
        $session = $this->getRequest()->getSession();
        $allowed_temp_files = $session->get('anonymous_allowed_file_ids', []);
        $allowed_temp_files[$fileOut->id()] = $fileOut->id();
        $session->set('anonymous_allowed_file_ids', $allowed_temp_files);
      }

      // Save the output file information in the session.
      $sess_state['file_verify']['fid'] = $fileOut->id();
      $sess_state['file_verify']['uri'] = $fileOutUri;
      $session->set('record_cleaner_state', $sess_state);
    }

    // All rules checkbox.
    $form['no_difficulty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Omit ID difficulty messages'),
      '#description' => $this->t(
        "Uncheck to output messages containing ID difficulty text."
      ),
      '#default_value' => $form_state->getValue('no_difficulty', 1),
    ];

    // All rules checkbox.
    $allValue = $form_state->getValue('all', 1);
    $form['all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use all rules'),
      '#description' => $this->t(
        "Uncheck to limit rules to selected organisations and selected rule
        types."
      ),
      '#default_value' => $allValue,
      '#ajax' => [
        'callback' => '::changeAll',
        'event' => 'change',
        'wrapper' => 'organisations',
      ]
    ];

    // Container for all org group rules.
    // Tree ensures naming of elements is unique.
    $form['rules'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rules'),
      '#description' => $this->t('Select the verification tests you want to run.'),
      '#description_display' => 'before',
      '#attributes' => [
        'id' => 'organisations',
        'class' => ['record-cleaner-organisation-container'],
      ],
      '#tree' => TRUE,
    ];
    // Attach CSS to format checkbox hierarchy.
    $form['rules']['#attached']['library'][] = 'record_cleaner/record_cleaner';

    // Get the organisation-group-rules from the service.
    $orgGroupRules = $this->apiHelper->orgGroupRules();

    // Hide organisation container if all rules is selected.
    if ($allValue) {
      $form['rules']['#attributes']['class'][] = 'hidden';
    }

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
          'callback' => '::changeSelection',
          'event' => 'change',
          'wrapper' => $groupContainerId,
        ]
      ];

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
          ['rules', $groupContainer, $group]
        );
        $form['rules'][$groupContainer][$group] = [
          '#type' => 'checkbox',
          '#title' => $group,
          '#default_value' => $groupValue,
          '#ajax' => [
            'callback' => '::changeSelection',
            'event' => 'change',
            'wrapper' => $ruleContainerId,
          ]
        ];

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
              ['rules', $groupContainer, $ruleContainer, $rule]
            ),
          ];
        }
      }
    }

    // Add a hidden input to control state of other items.
    // There is no change event for this input but it is taken in to account
    // when Ajax content is loaded and initial states are set.
    // It has a value of 0 which is changed to pass or fail after verification.
    $form['verify']['verify-result'] = [
      '#type' => 'hidden',
      '#default_value' => $form_state->getValue('verify-result', '0'),
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
      '#submit' => ['::verify'],
    ];

    $form['actions']['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start again'),
      '#submit' => ['::returnToStart'],
    ];

    return $form;
  }

  /**
   * Ajax callback for changing use-all-rules checkbox.
   */
  public function changeAll(array &$form, FormStateInterface $form_state) {
    return $form['rules'];
  }

  /**
   * Ajax callback for changing rule selection.
   */
  public function changeSelection(array &$form, FormStateInterface $form_state) {
    $triggeredElement = $form_state->getTriggeringElement();
    $parents = $triggeredElement['#array_parents'];
    $selection = $form_state->getValue($parents);
    $values = $form_state->getValues();

    if (count($parents) == 2) {
      // Organisation in ['rules'][$organisation] changed.
      $organisation = $parents[1];
      $groupContainer = "$organisation groups";
      $container = $form['rules'][$groupContainer];

      // Check or clear all the descendant checkboxes.
      $groupItems = $values['rules'][$groupContainer];
      foreach($groupItems as $item => $value) {
        if (is_int($value)) {
          // Found a group checkbox
          $container[$item]['#checked'] = $selection;
        }
        else {
          // Found a rule container.
          foreach(array_keys($value) as $rule) {
            $container[$item][$rule]['#checked'] = $selection;
          }
          // Make it visible.
          foreach($container[$item]['#attributes']['class'] as $idx => $class) {
            if ($class == 'hidden') {
              unset($container[$item]['#attributes']['class'][$idx]);
            }
          }
        }
      }
    }
    elseif (count($parents) == 3) {
      // Group in ['rules'][$groupContainer][$group] changed.
      $groupContainer = $parents[1];
      $group = $parents[2];
      $ruleContainer = "$group rules";
      $container = $form['rules'][$groupContainer][$ruleContainer];

      // Check or clear all the descendant checkboxes.
      $rules = $values['rules'][$groupContainer][$ruleContainer];
      foreach(array_keys($rules) as $rule) {
        $container[$rule]['#checked'] = $selection;
      }
    }

    return $container;
  }

  /**
   * Submit handler for the Back button.
   */
  public function backFromVerifyForm(array &$form, FormStateInterface $form_state) {
    $form_state->set('verify_values', [
      'rules' => $form_state->getValue('rules'),
      'all' => $form_state->getValue('all'),
      'no_difficulty' => $form_state->getValue('no_difficulty'),
    ]);
    $this->moveBack($form_state);
  }

  /**
   * Submit handler for the Verify button.
   */
  public function verify(array &$form, FormStateInterface $form_state) {
    // Preserve form state values for use on other forms.
    $request = $this->getRequest();
    $session = $request->getSession();
    $sess_state = $session->get('record_cleaner_state');

    // Bundle all settings needed for submitting to service.
    $settings['action'] = 'verify';
    $settings['source'] = $sess_state['file_validate'];
    $settings['output'] = $sess_state['file_verify'];
    $settings['sref'] = [
      'type' => $sess_state['sref_values']['sref_type'],
      'nr_coords' => $sess_state['sref_values']['nr_coords'],
      'precision_value' => $sess_state['sref_values']['precision_value'],
    ];
    switch ($settings['sref']['type']) {
      case 'grid':
        $srid = $sess_state['sref_values']['sref_grid'];
        break;
      case 'en':
        $srid = $sess_state['sref_values']['sref_en'];
        break;
      case 'latlon':
        $srid = $sess_state['sref_values']['sref_latlon'];
        break;
    }
    $settings['sref']['srid'] = $srid;

    $settings['org_group_rules'] = $this->getOrgGroupRules($form_state);

    if ($form_state->getValue('no_difficulty') == 1) {
      $settings['verbose'] = 0;
    }
    else {
      $settings['verbose'] = 1;
    }

    $batch = new BatchBuilder();
    $batch->setTitle($this->t("Record Cleaner - Verifying"));
    $batch->setProgressMessage('Processing');
    $batch->addOperation([$this->fileHelper, 'batchProcess'], [$settings]);
    $batch->setFinishCallback([$this->fileHelper, 'batchFinished']);

    batch_set($batch->toArray());

    $form_state->setRedirect('record_cleaner.verified');
  }

/********************* UTILITY FUNCTIONS *********************/
  public function moveForward(FormStateInterface $form_state) {
    $this->move($form_state, 1);
  }

  public function moveBack(FormStateInterface $form_state) {
    $this->move($form_state, -1);
  }

  public function returnToStart(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('record_cleaner.ui');
  }

  public function move(FormStateInterface $form_state, int $increment) {
    $stepNum = $form_state->get('step_num') + $increment;
    $step = $this->steps[$stepNum];

    // Restore form values previously set.
    if ($form_state->has("{$step}_values")) {
      $form_state->setValues($form_state->get("{$step}_values"));
    }
    else {
      $settings = $this->cookieHelper->getCookie();
      // Or stored in a cookie from a previous occasion
      if (isset($settings) && isset($settings[$step])) {
        $form_state->setValues($settings[$step]);
      }
    }

    // Change step.
    $form_state
      ->set('step_num', $stepNum)
      ->setRebuild(TRUE);
  }

  public function getOrgGroupRules(FormStateInterface $form_state) {
    // Construct org_group_rules_list required by API.

    if ($form_state->getValue('all') == 1) {
      // Early return if 'all' is checked.
      return [];
    }

    $orgGroupRules = [];
    $checkboxes = $form_state->getValue('rules');
    // Build array of selected org group rules.
    foreach($checkboxes as $organisation => $value) {
      // Seek checked organisations.
      if ($value == 1) {
        $groupContainer = "$organisation groups";
        foreach($checkboxes[$groupContainer] as $group => $value) {
          // Seek checked groups.
          if ($value == 1) {
            $ruleContainer = "$group rules";
            $rules = [];
            foreach($checkboxes[$groupContainer][$ruleContainer] as $item => $value) {
              // Seek checked rules.
              if ($value == 1) {
                // Convert '{Ruletype} Rule' to {ruletype}
                $rule = strtolower(explode(' ', $item)[0]);
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

  public function submitForm(array &$form, FormStateInterface $form_state) {}


}

