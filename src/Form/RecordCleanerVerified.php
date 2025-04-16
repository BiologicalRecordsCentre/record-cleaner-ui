<?php

namespace Drupal\record_cleaner\Form;

use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\record_cleaner\Service\ApiHelper;
use Drupal\record_cleaner\Service\CookieHelper;
use Drupal\record_cleaner\Service\FileHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RecordCleanerVerified extends FormBase {

  /**
   * Constructs a new RecordCleanerVerified object.
   *
   * @param \Drupal\record_cleaner\Service\ApiHelper $apiHelper
   *   The record_cleaner API helper service.
   * @param \Drupal\record_cleaner\Service\CookieHelper $cookieHelper
   *   The cookie helper service.
   * @param \Drupal\record_cleaner\Service\FileHelper $fileHelper
   *   The record_cleaner file service.
   * @param \Drupal\Core\File\FileUrlGenerator $fileUrlGenerator
   *   The file URL generator service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
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
      return 'record_cleaner_verified';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load Verification results from session.
    $request = $this->getRequest();
    $session = $request->getSession();
    $verifyResult = $session->get('record_cleaner_verify_result');

    // Handle errors.
    $error = '';
    if (!$verifyResult) {
      // No results in session.
      $error = $this->t("There are no validation results to display.");
    }
    elseif (array_key_exists('error', $verifyResult)) {
      // An exception occurred in batch processing.
      $error =  $this->t("There was an error processing the file. If the problem
        persists, please contact the administrator. {$verifyResult['error']}");
    }
    if ($error) {
      // Return to start
      $this->messenger()->addError($error);
      $url = Url::fromRoute('record_cleaner.ui')->toString();
      return new RedirectResponse($url);
    }

    $success = $verifyResult['success'];
    $counts = $verifyResult['counts'];
    $messages = $verifyResult['messages'];

    // Display results.
    $result = $success ? 'pass' : 'fail';

    $form['verify']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' =>  'Verify ' .  ucfirst($result) . 'ed',
    ];
    $form['verify']['count'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => "{$counts['total']} records were checked. <br/>" .
        "{$counts['pass']} records passed. <br/>" .
        "{$counts['warn']} records had warnings. <br/>" .
        "{$counts['fail']} records failed.",
      ];
    $form['verify']['messages'] = $this->fileHelper->getMessageSummary($messages);

    // Load form_state from session.
    $sess_state = $session->get('record_cleaner_state');
    if (!$sess_state) {
      $url = Url::fromRoute('record_cleaner.ui')->toString();
      return new RedirectResponse($url);
    }

     // Get result of batch process.
      // Display a link to the output file.
     $url = $this->fileUrlGenerator->generateAbsoluteString(
       $sess_state['file_verify']['uri']
     );
     $form['verify']['link'] = [
      '#type' => 'link',
      '#url' => Url::fromUri($url),
      '#prefix' => '<p>' . $this->t('Please download the '),
      '#title' => $this->t("verify file"),
      '#suffix' => $this->t(' for more information. If you have errors, edit
        your data and re-upload to complete the checking process.') . '</p>',
    ];

     $form['actions']['restart'] = [
       '#type' => 'submit',
       '#value' => $this->t('Start again'),
       '#submit' => ['::returnToStart'],
     ];

    return $form;
  }

  public function returnToStart(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('record_cleaner.ui');
  }
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}

