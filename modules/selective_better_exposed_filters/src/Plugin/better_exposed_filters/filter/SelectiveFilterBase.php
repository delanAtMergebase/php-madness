<?php

namespace Drupal\selective_better_exposed_filters\Plugin\better_exposed_filters\filter;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Plugin\views\filter\TaxonomyIndexTid;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Base class for Better exposed filters widget plugins.
 */
abstract class SelectiveFilterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultConfiguration() {
    return [
      'options_show_only_used' => FALSE,
      'options_show_only_used_filtered' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function buildConfigurationForm(FilterPluginBase $filter, array $settings) {
    $form = [];
    if ($filter->isExposed() && $filter instanceof TaxonomyIndexTid) {
      $form['options_show_only_used'] = [
        '#type' => 'checkbox',
        '#title' => t('Show only used terms'),
        '#default_value' => !empty($settings['options_show_only_used']),
        '#description' => t('Restrict exposed filter values to those presented in the result set.'),
      ];

      $form['options_show_only_used_filtered'] = [
        '#type' => 'checkbox',
        '#title' => t('Filter terms based on filtered result set'),
        '#default_value' => !empty($settings['options_show_only_used_filtered']),
        '#description' => t('Restrict exposed filter values to those presented in the already filtered result set.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function exposedFormAlter(ViewExecutable &$current_view, FilterPluginBase $filter, array $settings, array &$form, FormStateInterface &$form_state) {
    if ($filter->isExposed() && !empty($settings['options_show_only_used'])) {
      $identifier = $filter->options['is_grouped'] ? $filter->options['group_info']['identifier'] : $filter->options['expose']['identifier'];

      if (empty($current_view->selective_filter)) {
        $view = Views::getView($current_view->id());
        $view->selective_filter = TRUE;
        $view->setArguments($current_view->args);
        $view->setItemsPerPage(0);
        $view->setDisplay($current_view->current_display);
        $view->preExecute();
        $view->execute();

        if (!empty($view->result)) {
          $hierarchy = !empty($filter->options['hierarchy']);
          $field_id = $filter->definition['field_name'];
          $relationship = $filter->options['relationship'];
          $element = &$form[$identifier];

          $ids = [];
          foreach ($view->result as $row) {
            $entity = $row->_entity;
            if ($relationship != 'none') {
              $entity = $row->_relationship_entities[$relationship] ?? FALSE;
            }
            if ($entity instanceof FieldableEntityInterface && $entity->hasField($field_id)) {
              $term_values = $entity->get($field_id)->getValue();

              if (!empty($term_values)) {
                foreach ($term_values as $term_value) {
                  $tid = $term_value['target_id'];
                  $ids[$tid] = $tid;

                  if ($hierarchy) {
                    $parents = \Drupal::service('entity_type.manager')
                      ->getStorage("taxonomy_term")
                      ->loadAllParents($tid);

                    /** @var \Drupal\taxonomy\TermInterface $term */
                    foreach ($parents as $term) {
                      $ids[$term->id()] = $term->id();
                    }
                  }
                }
              }
            }
          }

          if (!empty($element['#options'])) {
            foreach ($element['#options'] as $key => $option) {
              if ($key == 'All') {
                continue;
              }

              $target_id = $key;
              if (is_object($option) && !empty($option->option)) {
                $target_id = array_keys($option->option);
                $target_id = reset($target_id);
              }
              if (!in_array($target_id, $ids)) {
                unset($element['#options'][$key]);
              }
            }
          }
        }
      }
      else {
        if (!empty($settings['options_show_only_used_filtered'])) {
          $user_input = $form_state->getUserInput();
          if (isset($user_input[$identifier])) {
            unset($user_input[$identifier]);
          }
        }
        else {
          $user_input = [];
        }
        $form_state->setUserInput($user_input);
      }
    }
  }

}
