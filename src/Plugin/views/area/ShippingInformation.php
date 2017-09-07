<?php

namespace Drupal\commerce_cp\Plugin\views\area;

use Drupal\commerce_checkout\CheckoutPaneManager;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\views\Plugin\views\argument\NumericArgument;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the Shipping Information pane area handler.
 *
 * Allows displaying the Shipping Information checkout pane as part of the
 * Shopping Cart form.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("commerce_cp_shipping_information")
 */
class ShippingInformation extends AreaPluginBase {

  /**
   * The entity type manager.
   *
   * Required for getting entity storages.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_type_manager;

  /**
   * The checkout pane manager.
   *
   * Required for reconstructing the shipping information checkout pane.
   *
   * @var \Drupal\commerce_checkout\CheckoutPaneManager
   */
  protected $checkout_pane_manager;

  /**
   * Constructs a new ShippingInformation instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_checkout\CheckoutPaneManager $checkout_pane_manager
   *   The checkout pane manater.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    CheckoutPaneManager $checkout_pane_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entity_type_manager = $entity_type_manager;
    $this->checkout_pane_manager = $checkout_pane_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_checkout_pane')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    // Whether to show the shipping profile form field.
    $show_shipping_profile = TRUE;
    if (isset($this->options['show_shipping_profile']) && !$this->options['show_shipping_profile']) {
      $show_shipping_profile = FALSE;
    }
    $form['show_shipping_profile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display the Shipping Profile form field'),
      '#default_value' => $show_shipping_profile,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['show_shipping_profile'] = ['default' => TRUE];

    return $options;
  }

  /**
   * Form constructor for the views form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state) {
    // Make sure we do not accidentally cache this form.
    $form['#cache']['max-age'] = 0;

    $order = NULL;
    $order_storage = $this->entity_type_manager->getStorage('commerce_order');

    // The order is available as a view argument.
    foreach ($this->view->argument as $name => $argument) {
      $is_numeric = $argument instanceof NumericArgument;
      if (!$is_numeric || $argument->getField() !== 'commerce_order.order_id') {
        continue;
      }

      $order = $order_storage->load($argument->getValue());
      if ($order) {
        break;
      }
    }

    // We will be recreating the checkout shipping_information pane provided by
    // the commerce_shipping module.

    // First we need to get the checkout flow plugin.
    $order_bundle = $order->bundle();
    $checkout_flow_id = $this->entity_type_manager
      ->getStorage('commerce_order_type')
      ->load($order_bundle)
      ->getThirdPartySetting('commerce_checkout', 'checkout_flow');

    if (!$checkout_flow_id) {
      return;
    }

    $checkout_flow = $this->entity_type_manager
      ->getStorage('commerce_checkout_flow')
      ->load($checkout_flow_id);
    $plugin = $checkout_flow->getPluginCollections();

    // This is only one plugin wrapped in a plugin collection, so it should
    // always be one. If not, there's something we haven't taken into account
    // well so let's not do anything until we fix it.
    if ($plugin['configuration']->count() > 1) {
      return;
    }

    // The plugin should contain the shipping_information pane provided by
    // commerce_shipping that also contains the require_shipping_profile option.
    $plugin_configuration = $plugin['configuration']->getConfiguration();
    if (!isset($plugin_configuration['panes']['shipping_information']['require_shipping_profile'])) {
      return;
    }

    $checkout_flow_plugin = $plugin['configuration']->getIterator()->current();
    $checkout_flow_plugin->setOrder($order);

    // We've got the checkout flow plugin, construct the shipping_information
    // pane.
    $shipping_pane = $this->checkout_pane_manager->createInstance(
      'shipping_information',
      $plugin_configuration['panes']['shipping_information'],
      $checkout_flow_plugin
    );

    // We don't want to recreate the shipping_information pane or reload the
    // order in the validation/submission functions, so let's make them
    // available via the form state's storage.
    $form_state->set('shipping_information_pane', $shipping_pane);
    $form_state->set('order', $order);

    $form['shipping_information'] = [
      '#parents' => ['shipping_information'],
      '#type' => $shipping_pane->getWrapperElement(),
      '#title' => $shipping_pane->getDisplayLabel(),
    ];
    $form['shipping_information'] = $shipping_pane->buildPaneForm(
      $form['shipping_information'],
      $form_state,
      $form
    );

    if (!$this->options['show_shipping_profile']) {
      $form['shipping_information']['shipping_profile']['#access'] = FALSE;
    }

    // Re-calculating shipping costs is not supported yet.
    unset($form['shipping_information']['recalculate_shipping']);
  }

  /**
   * Form validation callback.
   *
   * @see \Drupal\views\Form\ViewsFormMainForm::validateForm
   */
  public function viewsFormValidate($form, FormStateInterface $form_state) {
    $shipping_pane = $form_state->get('shipping_information_pane');
    $shipping_pane->validatePaneForm(
      $form['shipping_information'],
      $form_state,
      $form
    );
  }

  /**
   * Form submission callback.
   *
   * @see \Drupal\views\Form\ViewsFormMainForm::submitForm
   */
  public function viewsFormSubmit($form, FormStateInterface $form_state) {
    $shipping_pane = $form_state->get('shipping_information_pane');
    $shipping_pane->submitPaneForm(
      $form['shipping_information'],
      $form_state,
      $form
    );

    // The shipping information pane form submission callback will potentially
    // alter the order's shipments the determine the order's shipping costs, so
    // we need to save the order.
    $order = $form_state->get('order');
    $order->save();
  }

  /**
   * Determines whether the form is empty.
   *
   * We don't render the form if there are no results i.e. no cart form.
   */
  public function viewsFormEmpty($empty = FALSE) {
    return $empty;
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    return [];
  }

}
