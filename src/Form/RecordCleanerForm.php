<?php

namespace Drupal\record_cleaner\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Module configuration form.
 */
class RecordCleanerForm extends ConfigFormBase {

    public function getFormId() {
        return 'record_cleaner_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state) {
        $form = parent::buildForm($form, $form_state);
        return $form;
    }

    public function getEditableConfigNames() {  
        return [
            'record_cleaner.settings',
        ];
    }
}


