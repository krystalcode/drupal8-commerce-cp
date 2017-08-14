<?php

/**
 * @file
 * Provide views data for the Commerce Cart Pane module.
 */

/**
 * Implements hook_views_data().
 */
function commerce_cp_views_data() {
  $data = [];

  // The Shipping Information pane area plugin.
  $data['views']['commerce_cp_shipping_information'] = [
    'title' => t('Shipping Information pane'),
    'help' => t('Adds the checkout Shipping Information pane to the shopping cart page.'),
    'area' => [
      'id' => 'commerce_cp_shipping_information',
    ],
  ];

  return $data;
}
