<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\UiHelper.
 */

 namespace Drupal\record_cleaner\Service;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;


class UiHelper {

  use stringTranslationTrait;
  use DependencySerializationTrait;


  /**
   * Constructs a new FileExampleReadWriteForm page.
   *
   * @param \Drupal\file_example\FileExampleStateHelper $stateHelper
   *   The file example state helper.
   * @param \Drupal\file_example\FileExampleFileHelper $fileHelper
   *   The file example file helper.
   * @param \Drupal\file_example\FileExampleSessionHelperWrapper $sessionHelperWrapper
   *   The file example session helper wrapper.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   *
   * @see https://php.watch/versions/8.0/constructor-property-promotion
   */
  public function __construct(
    protected LoggerChannelInterface $logger,
    //protected FileExampleStateHelper $stateHelper,
    //protected FileExampleFileHelper $fileHelper,
    //protected FileExampleSessionHelperWrapper $sessionHelperWrapper,
    //protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger
    //protected FileRepositoryInterface $fileRepository,
    //protected FileSystemInterface $fileSystem,
    //protected EventDispatcherInterface $eventDispatcher
  ) {
  }

  /**
   * Submit handler to write a managed file.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function handleUpload(array &$form, FormStateInterface $form_state) {

    $fid = $form_state->getValue['file_upload'][0];
    //$file_storage = $this->entityTypeManager->getStorage('file');
    //$file = $file_storage->load($fid);
    //$file = \Drupal\file\Entity\File::load($file_id);

  }



  /**
   * Submit handler to validate a file.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function handleValidate(array &$form, FormStateInterface $form_state) {
    $this->messenger->addMessage(
      $this->t('Do Validation.'));
}

  /**
   * Submit handler to verify a file.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function handleVerify(array &$form, FormStateInterface $form_state) {
    $this->messenger->addMessage(
      $this->t('Do Verification!!!'));
  }

}
