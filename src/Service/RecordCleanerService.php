<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Service\RecordCleanerService.
 */

namespace Drupal\record_cleaner\Service;

/**
 * Sevice layer for record cleaner.
 */
class RecordCleanerService {
    public function status() {
        $element = [
            '#type' => 'plain_text',
            '#plain_text' => 'Hello World',
        ];
        return $element;
    }
}
