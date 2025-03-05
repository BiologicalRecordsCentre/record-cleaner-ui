<?php

/**
 * @file
 * Contains \Drupal\record_cleaner\Controller\RecordCleanerController.
 */

namespace Drupal\record_cleaner\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\record_cleaner\Service\ApiHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for record cleaner pages.
 */
class RecordCleanerController extends ControllerBase {

  // The themeable element.
  protected $element = [];


  public function __construct(
    protected LoggerChannelInterface $logger,
    protected ApiHelper $api,
  ) {
  }

  public static function create(ContainerInterface $container) {
    return new static (
      $container->get('record_cleaner.logger_channel'),
      $container->get('record_cleaner.api_helper'),
    );
  }

  /**
   * Returns an overview page.
   * This callback is linked to the path /record_cleaner.
   * You can create a normal Drupal page with a url alias of /record_cleaner
   * and it will take precedence over this if you want to customise the content.
   */
  public function overview() {
    $output = '<p>';
    $output .= $this->t("Record Cleaner allows you to upload a file of species
      records for");
    $output .= '<ul><li>';
    $output .= '<b>' . $this->t("validation") . '</b>';
    $output .= $this->t(" - checking the format is correct,");
    $output .= '</li><li>';
    $output .= '<b>' . $this->t("verification") . '</b>';
    $output .= $this->t(" - checking the record is consistent with
     rules such as where and when it is known to be found.");
    $output .= '</li></ul>';
    $output .= '</p>';

    $output .= '<p>';
    $output .= $this->t("These pages are a user interface to a service that
     performs the checking. Go to the <a href=':status'>status page</a> to check
     the service is available.", [
      ':status' => Url::fromRoute('record_cleaner.status')->toString()
    ]);
    $output .= '</p>';

    $output .= '<p>';
    $output .= $this->t("To start start using the service, go to the
    <a href=':ui'>cleaning wizard</a>. and upload a file containing your
    records", [
      ':ui' => Url::fromRoute('record_cleaner.ui')->toString()
    ]);
    $output .= '</p>';

    $output .= '<p>';
    $output .= $this->t("The file must be in CSV format and contain a header row
    with column names. Each row needs to contain at least the following
    columns:");
    $output .= '<ul><li>';
    $output .= '<b>' . $this->t("Observation date. ") . '</b>';
    $output .= $this->t("This can be a specific date, or a date range.
<a href=:dates>Read more</a>.", [
      ':dates' => Url::fromUri('https://biologicalrecordscentre.github.io/record-cleaner-service/validate/date.html')->toString()
]);
    $output .= '</li><li>';
    $output .= '<b>' . $this->t("Taxon version key or taxon name. ") . '</b>';
    $output .= $this->t("This identifes the organism that was observed. A taxon
version key (TVK) is unambiguous whereas you may need to clarify the exact
meaning of a taxon name.");
    $output .= '</li><li>';
    $output .= '<b>' . $this->t("Spatial reference. ") . '</b>';
    $output .= $this->t("This is where you made the observation. It might be a grid
reference or a latitude and longitude.");
    $output .= '</li></ul>';

    $element = [
      '#markup' => $output,
    ];
    return $element;
  }

  /**
   * Returns a status page.
   * This callback is linked to the path /record_cleaner/status
   */
  public function status() {
    $element = [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => $this->api->status(),
    ];
    return $element;
  }

}
