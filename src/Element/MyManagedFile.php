<?php

namespace Drupal\record_cleaner\Element;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Element\ManagedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class MyManagedFile.
 *
 * Overrides the standard ManagedFile class in order to extend the
 * Ajax callback on file upload. The next button is only enabled after a file
 * has been uploaded.
 *
 * @FormElement("record_cleaner_managed_file")
 */
class MyManagedFile extends ManagedFile {

  /**
   * {@inheritdoc}
   */
  public static function uploadAjaxCallback(&$form, FormStateInterface &$form_state, Request $request)
  {
    $whole_form = $form;

    // Call the parent method first. It modifies $form so it just contains the
    // array for the file element.
    $response = parent::uploadAjaxCallback($form, $form_state, $request);

    // If the parent method didn't return an AjaxResponse (e.g. error), just
    // return it.
    if (!$response instanceof AjaxResponse) {
      return $response;
    }

    // Check for custom Ajax.
    $callback = NULL;
    if (!empty($form['#custom_ajax'])) {
      $settings = $form['#custom_ajax'];
      $callback = $settings['callback'] ?? NULL;
      $wrapper = $settings['wrapper'] ?? NULL;
    }

    if ($callback) {
      // Do custom Ajax.
      $callback_resolved = $form_state->prepareCallback($callback);
      $args = [&$whole_form, $form_state];
      if (is_callable($callback_resolved)) {
        $result = call_user_func_array($callback_resolved, $args);

        // Handle the result
        if ($result instanceof AjaxResponse) {
          // Merge commands if the user returned an AjaxResponse
          foreach ($result->getCommands() as $command) {
            $response->addCommand($command);
          }
        }
        elseif (is_array($result) && $wrapper) {
          // If a render array is returned, replace the specified wrapper
          $response->addCommand(new ReplaceCommand('#' . $wrapper, $result));
        }
      }
    }

    return $response;
  }
}
