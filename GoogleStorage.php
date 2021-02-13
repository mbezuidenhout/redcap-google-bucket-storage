<?php

namespace Stanford\GoogleStorage;

require_once "emLoggerTrait.php";
require __DIR__ . '/vendor/autoload.php';

# Imports the Google Cloud client library
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\Bucket;

/**
 * Class GoogleStorage
 * @package Stanford\GoogleStorage
 * @property \Google\Cloud\Storage\StorageClient $client
 * @property \Google\Cloud\Storage\Bucket[] $buckets
 * @property array $instances
 * @property array $fields
 * @property array $record
 * @property array $downloadLinks
 * @property \Project $project
 * @property string $recordId
 * @property int $eventId
 * @property int $instanceId
 */
class GoogleStorage extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;


    /**
     * @var \Google\Cloud\Storage\StorageClient
     */
    private $client;

    /**
     * @var \Google\Cloud\Storage\Bucket[]
     */
    private $buckets;

    /**
     * @var array
     */
    private $instances;

    private $project;

    private $fields;

    private $recordId;

    private $eventId;

    private $instanceId;

    private $record;

    private $downloadLinks;

    public function __construct()
    {
        try {
            parent::__construct();

            if (isset($_GET['pid']) && $this->getProjectSetting('google-api-token') != '' && $this->getProjectSetting('google-project-id') != '') {
                $this->setInstances();

                global $Proj;

                $this->setProject($Proj);

                $this->prepareGoogleStorageFields();
                //configure google storage object
                $this->setClient(new StorageClient(['keyFile' => json_decode($this->getProjectSetting('google-api-token'), true), 'projectId' => $this->getProjectSetting('google-project-id')]));

                if (!empty($this->getInstances())) {
                    $buckets = array();
                    foreach ($this->getInstances() as $instance) {
                        $buckets[$instance['google-storage-bucket']] = $this->getClient()->bucket($instance['google-storage-bucket']);
                    }
                    $this->setBuckets($buckets);
                }
            }
        } catch (\Exception $e) {
            #echo $e->getMessage();
        }
    }

    /**
     * @param string $path
     */
    public function includeFile($path)
    {
        include_once $path;
    }

    private function prepareGoogleStorageFields()
    {
        $fields = array();
        $re = '/^@GOOGLE-STORAGE=/m';
        foreach ($this->getProject()->metadata as $name => $field) {
            preg_match_all($re, $field['misc'], $matches, PREG_SET_ORDER, 0);
            if (!empty($matches)) {
                $fields[$name] = str_replace('@GOOGLE-STORAGE=', '', $field['misc']);
            }
            unset($matches);
        }
        $this->setFields($fields);
    }

    public function getFieldInstrument($field)
    {
        foreach ($this->getProject()->forms as $name => $form) {
            if (array_key_exists($field, $form['fields'])) {
                return $name;
            }
        }
    }


    public function saveRecord()
    {
        $data[\REDCap::getRecordIdField()] = filter_var($_POST['record_id'], FILTER_SANITIZE_STRING);
        $filesPath = json_decode($_POST['files_path'], true);
        foreach ($filesPath as $field => $item) {
            $data[$field] = $item;
            $form = $this->getFieldInstrument($field);
        }
        $eventId = filter_var($_POST['event_id'], FILTER_SANITIZE_NUMBER_INT);
        $data['redcap_event_name'] = $this->getProject()->getUniqueEventNames($eventId);
        if ($this->getProject()->isRepeatingForm($eventId, $form)) {
            $data['redcap_repeat_instance'] = filter_var($_POST['instance_id'], FILTER_SANITIZE_NUMBER_INT);
            $data['redcap_repeat_instrument'] = $form;
        }

        $response = \REDCap::saveData($this->getProjectId(), 'json', json_encode(array($data)));
        if (empty($response['errors'])) {
            return array('status' => 'success');
        } else {
            if (is_array($response['errors'])) {
                throw new \Exception(implode(",", $response['errors']));
            } else {
                throw new \Exception($response['errors']);
            }
        }
    }

    public function redcap_every_page_top()
    {
        // in case we are loading record homepage load its the record children if existed
        if (strpos($_SERVER['SCRIPT_NAME'], 'DataEntry/index.php') !== false && $this->getFields()) {


            if (isset($_GET['id'])) {
                $this->setRecordId(filter_var($_GET['id'], FILTER_SANITIZE_STRING));
            }

            if (isset($_GET['event_id'])) {
                $this->setEventId(filter_var($_GET['event_id'], FILTER_SANITIZE_NUMBER_INT));
            } else {
                $this->setEventId($this->getFirstEventId());
            }

            if (isset($_GET['instance'])) {
                $this->setInstanceId(filter_var($_GET['instance'], FILTER_SANITIZE_NUMBER_INT));
            }
            $this->setRecord();
            $this->prepareDownloadLinks();
            $this->includeFile("src/client.php");
        }

    }

    public function prepareDownloadLinks()
    {
        $record = $this->getRecord();
        $links = array();
        foreach ($this->getFields() as $field => $bucket) {
            if ($record[$this->getRecordId()][$this->getEventId()][$field] != '') {
                $files = explode(",", $record[$this->getRecordId()][$this->getEventId()][$field]);
                $bucket = $this->getBucket($field);
                foreach ($files as $file) {
                    $links[$field][$file] = $this->getGoogleStorageSignedUrl($bucket, trim($file));
                }
            }
        }
        $this->setDownloadLinks($links);
    }

    public function buildUploadPath($fileName, $recordId, $eventId, $instanceId)
    {
        return $recordId . '_' . $eventId . '_' . $instanceId . '/' . $fileName;
    }

    /**
     * @param \Google\Cloud\Storage\Bucket $bucket
     * @param string $objectName
     * @param int $duration
     * @return string
     * @throws \Exception
     */
    public function getGoogleStorageSignedUrl($bucket, $objectName, $duration = 50)
    {
        $url = $bucket->object($objectName)->signedUrl(new \DateTime('+ ' . $duration . ' seconds'),
            [
                'version' => 'v4',
            ]);
        return $url;
    }

    /**
     * @param \Google\Cloud\Storage\Bucket $bucket
     * @param string $objectName
     * @param int $duration
     * @return string
     * @throws \Exception
     */
    public function getGoogleStorageSignedUploadUrl($bucket, $objectName, $contentType = 'text/plain', $duration = 3600)
    {
        $url = $bucket->object($objectName)->signedUrl(new \DateTime('+ ' . $duration . ' seconds'),
            [
                'method' => 'PUT',
                'contentType' => $contentType,
                'version' => 'v4',
            ]);
        return $url;
    }

    /**
     * @return \Google\Cloud\Storage\StorageClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param \Google\Cloud\Storage\StorageClient $client
     */
    public function setClient(StorageClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $fieldName
     * @return \Google\Cloud\Storage\Bucket
     */
    public function getBucket($fieldName)
    {
        $bucketName = $this->getFields()[$fieldName];
        return $this->getBuckets()[$bucketName];
    }

    /**
     * @return Bucket[]
     */
    public function getBuckets()
    {
        return $this->buckets;
    }

    /**
     * @param Bucket[] $buckets
     */
    public function setBuckets(array $buckets)
    {
        $this->buckets = $buckets;
    }


    /**
     * @return array
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     */
    public function setInstances()
    {
        $this->instances = $this->getSubSettings('instance', $this->getProjectId());
    }

    /**
     * @return \Project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * @param \Project $project
     */
    public function setProject(\Project $project)
    {
        $this->project = $project;
    }

    /**
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * @param array $fields
     */
    public function setFields(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * @return string
     */
    public function getRecordId()
    {
        return $this->recordId;
    }

    /**
     * @param string $recordId
     */
    public function setRecordId($recordId)
    {
        $this->recordId = $recordId;
    }

    /**
     * @return int
     */
    public function getEventId(): int
    {
        return $this->eventId;
    }

    /**
     * @param int $eventId
     */
    public function setEventId(int $eventId): void
    {
        $this->eventId = $eventId;
    }

    /**
     * @return int
     */
    public function getInstanceId()
    {
        return $this->instanceId;
    }

    /**
     * @param int $instanceId
     */
    public function setInstanceId($instanceId)
    {
        $this->instanceId = $instanceId;
    }

    /**
     * @return array
     */
    public function getRecord()
    {
        return $this->record;
    }

    /**
     * @param array $record
     */
    public function setRecord()
    {
        $param = array(
            'project_id' => $this->getProjectId(),
            'return_format' => 'array',
            'events' => $this->getEventId(),
            'records' => [$this->getRecordId()]
        );
        $data = array();
        $record = \REDCap::getData($param);
        $this->record = $record;
    }

    /**
     * @return array
     */
    public function getDownloadLinks()
    {
        return $this->downloadLinks;
    }

    /**
     * @param array $downloadLinks
     */
    public function setDownloadLinks(array $downloadLinks)
    {
        $this->downloadLinks = $downloadLinks;
    }
}