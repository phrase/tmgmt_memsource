<?php

namespace Drupal\tmgmt_memsource;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;

/**
 * Memsource translator UI.
 */
class MemsourceTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['service_url'] = [
      '#type' => 'textfield',
      '#title' => t('Memsource API endpoint'),
      '#default_value' => $translator->getSetting('service_url') ?: 'https://cloud.memsource.com/web/api',
      '#description' => t('Please enter the Memsource API endpoint.'),
      '#required' => TRUE,
      '#placeholder' => 'https://cloud.memsource.com/web/api',
    ];
    $form['memsource_user_name'] = [
      '#type' => 'textfield',
      '#title' => t('User name'),
      '#default_value' => $translator->getSetting('memsource_user_name'),
      '#description' => t('Please enter your Memsource Cloud user name.'),
      '#required' => TRUE,
      '#placeholder' => 'user name',
    ];
    $form['memsource_password'] = [
      '#type' => 'password',
      '#title' => t('Password'),
      '#default_value' => $translator->getSetting('memsource_password'),
      '#description' => t('Please enter your Memsource Cloud password.'),
      '#required' => TRUE,
      '#placeholder' => 'password',
    ];
    $form += parent::addConnectButton();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $plugin */
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    $result = $plugin->loginToMemsource();
    if ($result) {
      // login OK
    }
    else {
      $form_state->setErrorByName('settings][service_url', t('Login incorrect. Please check the API endpoint, user name and password.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    $templates_json = $translator_plugin->sendApiRequest('v2/projectTemplate/list');
    $templates = [0 => '-'];
    foreach ($templates_json as $template) {
      $templates[$template['id']] = $template['name'];
    }
    $settings['project_name'] = [
      '#type' => 'textfield',
      '#title' => t('Project name'),
      '#default_value' => NULL,
      '#description' => t('Enter the name of your Memsource Cloud project.'),
    ];
    $settings['project_template'] = [
      '#type' => 'select',
      '#title' => t('Project template'),
      '#options' => $templates,
      '#description' => t('Select a  Memsource Cloud project template.'),
    ];
    $settings['due_date'] = [
      '#type' => 'date',
      '#title' => t('Due Date'),
      '#date_date_format' => 'Y-m-d',
      '#description' => t('Enter the due date of this translation.'),
      '#default_value' => NULL,
    ];
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    $form = [];

    if ($job->isActive()) {
      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => t('Pull translations'),
        '#submit' => [[$this, 'submitPullTranslations']],
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * Submit callback to pull translations form Memsource Cloud.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $result = $translator_plugin->fetchTranslatedFiles($job);
    $translated = $result['translated'];
    $untranslated = $result['untranslated'];
    $errors = $result['errors'];
    if (sizeof($errors) == 0) {
      if ($untranslated == 0 && $translated != 0) {
        $job->addMessage('Fetched translations for @translated job items.', ['@translated' => $translated]);
      }
      elseif ($translated == 0) {
        drupal_set_message('No job item has been translated yet.');
      }
      else {
        $job->addMessage('Fetched translations for @translated job items, @untranslated are not translated yet.', [
          '@translated' => $translated,
          '@untranslated' => $untranslated,
        ]);
      }
    }
    tmgmt_write_request_messages($job);
  }

}
