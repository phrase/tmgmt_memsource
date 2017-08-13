<?php

namespace Drupal\tmgmt_memsource\Plugin\tmgmt\Translator;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Translator\AvailableResult;

/**
 * Memsource translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "memsource",
 *   label = @Translation("Memsource"),
 *   description = @Translation("Memsource translator service."),
 *   ui = "Drupal\tmgmt_memsource\MemsourceTranslatorUi",
 * )
 */
class MemsourceTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {

  /**
   * The translator.
   *
   * @var TranslatorInterface
   */
  private $translator;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Constructs a MemsourceTranslator object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \GuzzleHttp\ClientInterface $client */
    $client = $container->get('http_client');
    return new static(
      $client,
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    $this->translator = $translator;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $supported_remote_languages = [];
    $this->setTranslator($translator);
    try {
      $supported_languages = $this->sendApiRequest('v2/language/listSupportedLangs');
      foreach ($supported_languages as $language) {
        $supported_remote_languages[$language['code']] = $language['name'];
      }
    }
    catch (\Exception $e) {
      // Ignore exception, nothing we can do.
    }
    asort($supported_remote_languages);
    return $supported_remote_languages;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    if ($this->getToken()) {
      return AvailableResult::yes();
    }
    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $job = $this->requestJobItemsTranslation($job->getItems());
    if (!$job->isRejected()) {
      $job->submitted();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    $this->setTranslator($job->getTranslator());
    $project_id = 0;
    $due_date = $job->getSetting('due_date');
    try {
      $project_id = $this->newTranslationProject($job, $due_date);
      $job->addMessage('Created a new project in Memsource with the id: @id', ['@id' => $project_id], 'debug');

      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job_items as $job_item) {
        $job_part_id = $this->sendFiles($job_item, $project_id, $due_date);

        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_mapping */
        $remote_mapping = RemoteMapping::create([
          'tjid' => $job->id(),
          'tjiid' => $job_item->id(),
          'remote_identifier_1' => 'tmgmt_memsource',
          'remote_identifier_2' => $project_id,
          'remote_identifier_3' => $job_part_id,
          'remote_data' => [
            'FileStateVersion' => 1,
            'TmsState' => 'TranslatableSource',
            'RequiredBy' => $due_date,
          ],
        ]);
        $remote_mapping->save();

        if ($job_item->getJob()->isContinuous()) {
          $job_item->active();
        }
      }
    }
    catch (TMGMTException $e) {
      try {
        $this->sendFileError('RestartPoint03', $project_id, '', $job, $due_date, $e->getMessage(), TRUE);
      }
      catch (TMGMTException $e) {
        \Drupal::logger('tmgmt_memsource')->error('Error sending the error file: @error', ['@error' => $e->getMessage()]);
      }
      $job->rejected('Job has been rejected with following error: @error',
        ['@error' => $e->getMessage()], 'error');
      if (isset($remote_mapping)) {
        $remote_mapping->delete();
      }
    }
    return $job;
  }

  public function loginToMemsource() {
    $params = [
      'username' => $this->translator->getSetting('memsource_user_name'),
      'password' => $this->translator->getSetting('memsource_password'),
    ];
    $result = $this->request('v2/auth/login', 'GET', $params);
    if ($result['token']) {
      // store the token
      $this->storeToken($result['token']);
      return TRUE;
    }
    return FALSE;
  }

  public function storeToken($token) {
    $config = \Drupal::configFactory()->getEditable('tmgmt_memsource.settings');
    $config->set('memsource_token', $token)->save();
  }

  public function getToken() {
    return \Drupal::configFactory()->get('tmgmt_memsource.settings')->get('memsource_token');
  }

  public function verifyToken() {
    if (!$this->translator) {
      throw new TMGMTException('There is no Translator entity. Access to the Memsource API is not possible.');
    }
    $code = $this->request('v3/auth/whoAmI', 'GET', ['token' => $this->getToken()], FALSE, TRUE);
    if ($code != 200) {
      // token is invalid, try to re-login
      $this->loginToMemsource();
    }
  }

  public function sendApiRequest($path, $method = 'GET', $params = [], $download = FALSE, $code = FALSE, $body = NULL) {
    $result = NULL;
    $params['token'] = $this->getToken();
    try {
      $result = $this->request($path, $method, $params, $download, $code, $body);
    } catch (TMGMTException $ex) {
      if ($ex->getCode() == 401) {
        // token is invalid, try to re-login
        $this->loginToMemsource();
        $params['token'] = $this->getToken();
        $result = $this->request($path, $method, $params, $download, $code, $body);
      } else {
        throw $ex;
      }
    }
    return $result;
  }

  /**
   * Does a request to Memsource API.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   (Optional) HTTP method (GET, POST...). By default uses GET method.
   * @param array $params
   *   (Optional) Form parameters to send to Memsource API.
   * @param bool $download
   *   (Optional) If we expect resource to be downloaded. FALSE by default.
   * @param bool $code
   *   (Optional) If we want to return the status code of the call. FALSE by
   *   default.
   * @param string $body
   *   (Optional) Body of the POST request. NULL by
   *   default.
   *
   * @return array|int
   *   Response array or status code.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function request($path, $method = 'GET', $params = [], $download = FALSE, $code = FALSE, $body = NULL) {
    $options = [];
    if (!$this->translator) {
      throw new TMGMTException('There is no Translator entity. Access to the Memsource API is not possible.');
    }
    $config = \Drupal::configFactory()->get('tmgmt_memsource.settings');
    $service_url = $this->translator->getSetting('service_url');
    if (!$service_url) {
      if ($config->get('debug')) {
        \Drupal::logger('tmgmt_memsource')
          ->warning('Attempt to call Memsource API when service_url is not set: ' . $path);
      }
      return array();
    }
    $url = $service_url . '/' . $path;

    try {
      if ($body) {
        $options['body'] = $body;
      } else {
        if ($method == 'GET') {
          $options['query'] = $params;
        }
        else {
          $options['json'] = $params;
        }
      }
      $response = $this->client->request($method, $url, $options);
    }
    catch (RequestException $e) {
      if (!$e->hasResponse()) {
        if ($code) {
          return $e->getCode();
        }
        throw new TMGMTException('Unable to connect to Memsource API due to following error: @error', ['@error' => $e->getMessage()], $e->getCode());
      }
      $response = $e->getResponse();
      if ($config->get('debug')) {
        \Drupal::logger('tmgmt_memsource')->error('%method Request to %url:<br>
            <ul>
                <li>Request: %request</li>
                <li>Response: %response</li>
            </ul>
            ', [
            '%method' => $method,
            '%url' => $url,
            '%request' => $e->getRequest()->getBody()->getContents(),
            '%response' => $response->getBody()->getContents(),
          ]
        );
      }
      if ($code) {
        return $response->getStatusCode();
      }
      throw new TMGMTException('Unable to connect to Memsource API due to following error: @error', ['@error' => $response->getReasonPhrase()], $response->getStatusCode());
    }
    $received_data = $response->getBody()->getContents();
    if ($config->get('debug')) {
      \Drupal::logger('tmgmt_memsource')->debug('%method Request to %url:<br>
            <ul>
                <li>Request: %request</li>
                <li>Response: %response</li>
            </ul>
            ', [
          '%method' => $method,
          '%url' => $url,
          '%request' => json_encode($options),
          '%response' => $received_data,
        ]
      );
    }
    if ($code) {
      return $response->getStatusCode();
    }

    if ($response->getStatusCode() != 200) {
      throw new TMGMTException('Unable to connect to the Memsource API due to following error: @error at @url',
        ['@error' => $response->getStatusCode(), '@url' => $url]);
    }

    // If we are expecting a download, just return received data.
    if ($download) {
      return $received_data;
    }
    $received_data = json_decode($received_data, TRUE);

    return $received_data;
  }

  /**
   * Creates new translation project at Memsource Cloud.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   * @param string $required_by
   *   The date by when the translation is required.
   *
   * @return int
   *   Memsource project id.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function newTranslationProject(JobInterface $job, $due_date) {
    // prepare parameters for Project API
    $url = Url::fromRoute('tmgmt_memsource.callback');
    $mail = empty($job->getOwner()->getEmail()) ? \Drupal::config('system.site')->get('mail') : $job->getOwner()->getEmail();
    $name = $job->getSetting('project_name') ?: 'Drupal TMGMT project ' . $job->id();
    $params = [
      'name' => $name,
      'sourceLang' => $job->getRemoteSourceLanguage(),
      'targetLang' => $job->getRemoteTargetLanguage(),
      'dateDue' => $due_date,
    ];
    $template_id = $job->getSetting('project_template');
    if ($template_id == '0') {
      $result = $this->sendApiRequest('v4/project/create', 'GET', $params);
    } else {
      $params['template'] = $template_id;
      $result = $this->sendApiRequest('v4/project/createFromTemplate', 'GET', $params);
    }
    return $result['id'];
  }

  /**
   * Send the files to Memsource Cloud.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   * @param int $project_id
   *   Memsource project id.
   * @param string $due_date
   *   The date by when the translation is required.
   *
   * @return string
   *   Memsource jobPartId.
   */
  private function sendFiles(JobItemInterface $job_item, $project_id, $due_date) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    // prepare parameters for Job API
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');

    $job_item_id = $job_item->id();
    $target_language = $job_item->getJob()->getRemoteTargetLanguage();
    $conditions = ['tjiid' => ['value' => $job_item_id]];
    $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
    $name = "JobID_{$job_item->getJob()->id()}_JobItemID_{$job_item_id}_{$job_item->getJob()->getSourceLangcode()}_{$target_language}";

    $file_id = $this->uploadFileResource($xliff, $name);
    $job_part_id = $this->createJob($project_id, $file_id, $target_language, $due_date);

//    $this->sendUrl($job_item, $project_id, $file_id, FALSE, $required_by);

    return $job_part_id;
  }

  /**
   * Creates a file resource at Memsource Cloud.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param string $name
   *   File name of the .XLIFF file.
   *
   * @return string
   *   Memsource uuid of the resource.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function uploadFileResource($xliff, $name) {
    $file_name = $name . '.xliff';
    $form_params = [
      'token' => $this->getToken(),
      'name' => $file_name,
    ];
//    file_put_contents('/Users/filiprachunek/workspace/' . $file_name, $xliff);
    $result = $this->sendApiRequest('v2/file/uploadRequestBody?' . http_build_query($form_params), 'POST', [], false, false, $xliff);
    return $result['uid'];
  }

  public function createJob($project_id, $file_id, $target_language, $due_date) {
    $form_params = [
      'project' => $project_id,
      'file' => $file_id,
      'targetLang' => $target_language,
      'due' => $due_date,
      'fileFormat' => 'wpxliff',
    ];
    $result = $this->sendApiRequest('v8/job/create', 'GET', $form_params);
    return $result['jobParts'][0]['id'];
  }

  /**
   * Parses received translation from Memsource Cloud and returns unflatted data.
   *
   * @param string $data
   *   Xliff data, received from Memsource Cloud.
   *
   * @return array
   *   Unflatted data.
   */
  protected function parseTranslationData($data) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');
    // Import given data using XLIFF converter. Specify that passed content is
    // not a file.
    return $xliff_converter->import($data, FALSE);
  }

  /**
   * Fetches translations for job items of a given job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   A job containing job items that translations will be fetched for.
   *
   * @return array
   *   Array containing a containing the number of items translated and the
   *   number that has not been translated yet.
   */
  public function fetchTranslatedFiles(JobInterface $job) {
    $this->setTranslator($job->getTranslator());
    $translated = 0;
    $errors = [];

    try {
      /** @var JobItemInterface $job_item */
      foreach ($job->getItems() as $job_item) {
        $mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
        /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
        foreach ($mappings as $mapping) {
          // prepare parameters for Job API (to get the job status)
          $job_part_id = $mapping->getRemoteIdentifier3();
          $project_id = $mapping->getRemoteIdentifier2();
          $old_state = $mapping->getRemoteData('TmsState');
          $params = [
            'jobPart' => $job_part_id,
          ];
          $info = [];
          try {
            $info = $this->sendApiRequest('v8/job/get', 'GET', $params);
          } catch (TMGMTException $e) {
            $job->addMessage('Error fetching the job item: @job_item. Memsource job @job_part_id not found.',
              ['@job_item' => $job_item->label(), '@job_part_id' => $job_part_id], 'error');
            $errors[] = 'Memsource job ' . $job_part_id . ' not found, it was probably deleted.';
          }

          if (array_key_exists('status', $info)) {
            if ($this->remoteTranslationCompleted($info['status'])) {
              try {
                $this->addFileDataToJob($job, $info['status'], $project_id, $job_part_id);
                $translated++;
              } catch (TMGMTException $e) {
                $restart_point = $old_state == 'TranslatableSource' ? 'RestartPoint01' : 'RestartPoint02';
                $this->sendFileError($restart_point, $project_id, $job_part_id, $job_item->getJob(), $mapping->getRemoteData('RequiredBy'), $e->getMessage());
                $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $job_item->label()], 'error');
                continue;
              }
            } else {
//              $errors[] = 'Memsource job ' . $job_part_id . ' not completed.';
            }
          }
        }
      }
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_memsource')->error('Could not pull translation resources: @error', ['@error' => $e->getMessage()]);
    }
    return [
      'translated' => $translated,
      'untranslated' => count($job->getItems()) - $translated,
      'errors' => $errors,
    ];
  }

  /**
   * Retrieve all the updates for all the job items in a translator.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item to get the translation.
   *
   * @return int
   *   The number of updated job items.
   */
  public function pullRemoteTranslation(JobItemInterface $job_item) {
    $job = $job_item->getJob();
    $this->setTranslator($job->getTranslator());
    $remotes = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
    $remote = reset($remotes);
    $params = [
      'jobPart' => $remote->getRemoteIdentifier3(),
    ];
    $info = $this->sendApiRequest('v8/job/get', 'GET', $params);
    $old_state = $remote->getRemoteData('TmsState');
    if ($this->remoteTranslationCompleted($info['status'])) {
      try {
        $this->addFileDataToJob($job, $info['status'], $remote->getRemoteIdentifier2(), $remote->getRemoteIdentifier3());
        return 1;
      }
      catch (TMGMTException $e) {
        $restart_point = $old_state == 'TranslatableSource' ? 'RestartPoint01' : 'RestartPoint02';
        $this->sendFileError($restart_point, $remote->getRemoteIdentifier2(), $remote->getRemoteIdentifier3(), $job, $remote->getRemoteData('RequiredBy'), $e->getMessage());
        $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $remote->getJobItem()->label()], 'error');
//        $this->confirmUpload($remote->getRemoteIdentifier2(), $restart_point);
      }
    }
    return 0;
  }

  public function remoteTranslationCompleted($status) {
    return $status == 'COMPLETED_BY_LINGUIST' || $status == 'COMPLETED';
  }

  /**
   * Sends an error file to Memsource API.
   *
   * @param string $state
   *   The state.
   * @param int $project_id
   *   The project id.
   * @param string $file_id
   *   The file id to update.
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job.
   * @param string $required_by
   *   The date by when the translation is required.
   * @param string $message
   *   (Optional) The error message.
   * @param bool $confirm
   *   (Optional) Set to TRUE if also want to send the confirmation message
   *   of this error. Otherwise will not send it.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   If there is a problem with the request.
   */
  public function sendFileError($state, $project_id, $file_id, JobInterface $job, $required_by, $message = '', $confirm = FALSE) {
    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $required_by,
      'SourceLanguage' => $job->getRemoteSourceLanguage(),
      'TargetLanguage' => $job->getRemoteTargetLanguage(),
      'FilePathAndName' => 'error-' . (new DrupalDateTime())->format('Y-m-d\TH:i:s') . '.txt',
      'FileIdToUpdate' => $file_id,
      'FileState' => $state,
      'FileData' => base64_encode($message),
    ];
    // TODO: handle the error somehow (or maybe not)
//    $this->request('file', 'PUT', $form_params);
//    if ($confirm) {
//      $this->confirmUpload($project_id, $state);
//    }
  }

  /**
   * Retrieve the data of a file in a state.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job to which will be added the data.
   * @param string $state
   *   The state of the file.
   * @param int $project_id
   *   The project ID.
   * @param string $file_id
   *   The file ID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function addFileDataToJob(JobInterface $job, $state, $project_id, $file_id) {
    $mail = empty($job->getOwner()->getEmail()) ? \Drupal::config('system.site')->get('mail') : $job->getOwner()->getEmail();
    $params = [
      'jobPart' => $file_id,
    ];
    $data = $this->sendApiRequest('v8/job/getCompletedFile', 'GET', $params, TRUE);
    $decoded_data = $data;
//    \Drupal::logger('tmgmt_memsource')->debug('Completed file: ' . print_r($decoded_data, true));
    $file_data = $this->parseTranslationData($decoded_data);
    if ($this->remoteTranslationCompleted($state)) {
      $status = TMGMT_DATA_ITEM_STATE_TRANSLATED;
    }
    else {
      $status = TMGMT_DATA_ITEM_STATE_PRELIMINARY;
    }
    $job->addTranslatedData($file_data, [], $status);
    $mappings = RemoteMapping::loadByRemoteIdentifier('tmgmt_memsource', $project_id, $file_id);
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
    $mapping = reset($mappings);
    $mapping->removeRemoteData('TmsState');
    $mapping->addRemoteData('TmsState', $state);
    $mapping->save();

    // If this is a preliminary translation we must send the preview url.
    if ($status == TMGMT_DATA_ITEM_STATE_PRELIMINARY && $job->getSetting('review')) {
      foreach (array_keys($file_data) as $job_item_id) {
        /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
        $job_item = JobItem::load($job_item_id);
//        $this->sendUrl($job_item, $project_id, $file_id, TRUE, $mapping->getRemoteData('RequiredBy'));
      }
    }
  }

  public function containsText($string) {
    return $string != NULL && $string != "" &&  !ctype_space(preg_replace("/(&nbsp;)/", "", $string));
  }

  public function logDebug($message) {
    \Drupal::logger('tmgmt_memsource')->debug($message);
  }

}
