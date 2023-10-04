<?php

namespace Drupal\services_status_block\Plugin\Block;

use Drupal\Component\Utility\UrlHelper; // Consider removing library - depends on 'link' field type
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link; // Consider removing 
use Drupal\Core\Url; //Consider removing
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\Routing\Exception\RouteNotFoundException; // Remove on Prod

/**
 * Provides a block to render a summary of key service statuses
 *
 * @Block(
 *   id = "service_status_block",
 *   admin_label = @Translation("Service Status summary"),
 *   category = @Translation("LocalGov Services: Status"),
 * )
 */
class ServiceStatus extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    $config = $this->getConfiguration();

    $list = "<div class='service-status-tl'>";
    $list .= "<ul>";

    /**
     * Find all Services that were selected in block config
     * then find highest current (published + shown on landing page) status
     */
    // Get list of services (either from config or default/all)
    if (!empty($config['service_status_services'])) {
      $services = $config['service_status_services'];
    }
    else {
      // This bit doesn't seem to work, as array never empty
      $services = array();
      // Get list of all services if no config options selected
      // Get all top level services landing pages (localgov_services_landing)
      $query = \Drupal::entityQuery('node')
          ->condition('type', 'localgov_services_landing')
          ->condition('status', 1);
      $results = $query->execute();
      $service_list = \Drupal\node\Entity\Node::loadMultiple($results);
      foreach ($servicelist as $service) {
        $services[$service->id()] = $service->title->value;
      }
    }

    $db = \Drupal::database();

    foreach ($services AS $service_id) {
      
      // Zero value assigned by Form API for checkboxes not checked (?)
      if ($service_id != '0') {
        $service = \Drupal\node\Entity\Node::load($service_id);
        
        // Check service still exists
        if (isset($service)) {
          $service_name = $service->label();

          // Check if Service currently published
          $published = FALSE;
          if (\Drupal::service('module_handler')->moduleExists('content_moderation')) {
            if ($service->get('moderation_state')->getString() === 'published') {
              $published = TRUE;
            }
          }
          else {
            // Assume default Drupal published y/n is set
            if ($service->status->getString() == 1) {
              $published = TRUE;
            }
          }

          if ($published) {
            // Default "Normal" service status
            $service_status = "<i class='status-normal fa-sharp fa-solid fa-circle-check'></i>";
            
            $service_link = Link::fromTextAndUrl($service_name, Url::fromRoute('entity.node.canonical', ['node' => $service_id]))->toString();

            //Find most extreme related, valid status for current service
            $status_query = $db->select('node__localgov_service_status', 's');
            $status_query->join('node__localgov_services_parent', 'p', 'p.entity_id = s.entity_id');
            $status_query->join('node_field_data', 'n', 'p.entity_id = n.nid');
            $status_query
                ->fields('s', array('localgov_service_status_value'))
                ->condition('p.localgov_services_parent_target_id', $service_id)
                ->condition('n.status', 1)
                ->orderBy('s.localgov_service_status_value', 'ASC')
                ->range(0, 1);
            //Take first value of results
            $status_result = $status_query->execute()->fetchField(0);

            if (isset($status_result)) {
              if ($status_result == '0-severe-impact') {
                $service_status = "<i class='status-severe fa-sharp fa-solid fa-triangle-exclamation'>Severe</i>";
              }
              elseif ($status_result == '1-has-issues') {
                $service_status = "<i class='status-issues fa-solid fa-circle-info'>Issues</i>";
              }
            }

            $list .= "<li>" . $service_status . " " . $service_link . "</li>";
          }
        }
      }
    }

    $list .= "</ul>";

    // Get config values to build 'More detail" link
    if (!empty($config['service_status_link_text'])) {
      $link_text = $config['service_status_link_text'];
    }

    if (!empty($config['service_status_link'])) {
      $link = $config['service_status_link'];
    }
    // Check if internal URL (ie, NOT Absolute) and is valid
    if (UrlHelper::isValid($link, FALSE)) {
      // Strip any nonsense out, just in case
      $target = UrlHelper::stripDangerousProtocols($link);
    } else {
      // If fails validation set target to same page
      $target = "#";
      \Drupal::logger("Service Status Block")->notice("Invalid link: " . $link);
    }


    $list .= "<div class='status-detail btn'>"
        . "<a href='" 
        . $target
        . "' class='call-out-box__link' "
        . "'title='"
        . t($link_text)
        . "'>"
        . t($link_text)
        . "</a></div>";
    $list .= "</div>";

    return [
      '#type' => 'markup',
      '#markup' => $list,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'service_status_link_text' => $this->t('More detail about status updates.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['service_status_link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('More detail link label'),
      '#description' => $this->t('What text do you want as label for link to detailed status updates page?'),
      '#default_value' => $this->configuration['service_status_link_text'],
    ];

    $form['service_status_link'] = [
      // Entity autocomplete approach - generates incorrect route. Obvs done something wrong here
//      '#type' => 'entity_autocomplete',
//      '#target_type' => 'view',
//      '#title' => $this->t('Full status list'),
//      '#placeholder' => t('Detailed status overview'),
//      '#description' => $this->t('Views page that has a detailed list of all current Service Statuses'),
//      '#element_validate' => [
//        [
//          'Drupal\link\Plugin\Field\FieldWidget\LinkWidget',
//          'validateUriElement',
//        ],
//      ],
//      '#attributes' => [
//        'data-autocomplete-first-character-blacklist' => '/#?',
//      ],
//      '#process_default_value' => FALSE,
//      '#default_value' => isset($this->configuration['service_status_link']) ? $this->configuration['service_status_link'] : '',
      
      
      
      // Linkit approach - cannot reference Views pages by default with Linkit
      // See https://www.drupal.org/project/linkit/issues/2867647
//      '#type' => 'linkit',
//      '#title' => $this->t('Select link target'),
//      '#description' => $this->t('Start typing to see a list of results. Click to select.'),
//      '#autocomplete_route_name' => 'linkit.autocomplete',
//      '#autocomplete_route_parameters' => [
//        'linkit_profile' => 'default',
//      ],

      
      // Text field approach - potential security vulnerability
      '#type' => 'textfield',
      '#title' => $this->t('Link target'),
      '#placeholder' => t('Detailed status overview page'),
      '#description' => $this->t('Relative (internal) URL to page that has a detailed list of all current Service Statuses. Requires leading slash'),
      '#required' => TRUE,
      '#maxlength' => 256,
      '#default_value' => isset($this->configuration['service_status_link']) ? $this->configuration['service_status_link'] : '/service-status',
      
    ];

    $form['service_status_services'] = array(
      '#type' => 'checkboxes',
      '#title' => t('Select services to display'),
      '#options' => (array) null,
      '#default_value' => $this->configuration['service_status_services']
    );

    // Get all top level services landing pages (localgov_services_landing)
    $query = \Drupal::entityQuery('node')
        ->condition('type', 'localgov_services_landing')
        ->condition('status', 1);
    $results = $query->execute();
    $services = \Drupal\node\Entity\Node::loadMultiple($results);
    foreach ($services as $service) {
      $form['service_status_services']['#options'][$service->id()] = $service->title->value;
    }



    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) {
    // Check if not internal/relative URL
    $intlink = $form_state->getValue('service_status_link');
    if(!UrlHelper::isValid($intlink, FALSE) || !str_starts_with($intlink, "/")) {
      $form_state->setErrorByName('service_status_link', $this->t('Not a valid internal URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->configuration['service_status_link_text'] = $values['service_status_link_text'];
    $this->configuration['service_status_link'] = $values['service_status_link'];
    $this->configuration['service_status_services'] = $values['service_status_services'];
  }
/**
 * Remove function on Prod - only needed to check valid routes
 * @param type $route_to_check
 * @return type
 */
  public function routeExists($route_to_check) {
    try {
      \Drupal::service('router.route_provider')
          ->getRouteByName($route_to_check);
    } catch (RouteNotFoundException $exception) {
      return NULL;
    }
  }
}
