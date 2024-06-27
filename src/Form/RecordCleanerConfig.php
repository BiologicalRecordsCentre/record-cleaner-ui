<?php

namespace Drupal\record_cleaner\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Module configuration form.
 */
class RecordCleanerConfig extends ConfigFormBase {

    public function getFormId() {
        return 'record_cleaner_config';
    }

    public function buildForm(array $form, FormStateInterface $form_state) {
      // Form constructor.
      $form = parent::buildForm($form, $form_state);
      // Get current settings.
      $config = $this->config('record_cleaner.settings');

      // Add form elements.
      $form['service_url'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Service URL'),
        '#default_value' => $config->get('record_cleaner.service_url'),
        '#description' => $this->t('The base URL of the record cleaner service.'),
        '#required' => TRUE,
      ];
      $form['username'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Username'),
        '#default_value' => $config->get('record_cleaner.username'),
        '#description' => $this->t('The username for your record cleaner account.'),
      ];
      $form['password'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Password'),
        '#default_value' => $config->get('record_cleaner.password'),
        '#description' => $this->t('The password for your record cleaner account.'),
      ];

      return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state)
    {
      // TODO implement validation
      if ($form_state->getValue('service_url') == NULL) {
        $form_state->setErrorByName(
          'service_url',
          $this->t('The service URL is required.')
        );
      }
    }

    public function submitForm(array &$form, FormStateInterface $form_state) {
      $config = $this->config('record_cleaner.settings');
      $config->set('record_cleaner.service_url', $form_state->getValue('service_url'));
      $config->set('record_cleaner.username', $form_state->getValue('username'));
      $config->set('record_cleaner.password', $form_state->getValue('password'));
      $config->save();

      return parent::submitForm($form, $form_state);
    }

    public function getEditableConfigNames() {
        return [
            'record_cleaner.settings',
        ];
    }
}


