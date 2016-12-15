<?php

namespace UEdit\Bundle\MultimediaManagerBundle\Manager;

use Psr\Log\LoggerInterface;
use UEdit\Bundle\MultimediaManagerBundle\Entity\Thumbnail;
use Kaltura\Client\Configuration as KalturaConfiguration;
use Kaltura\Client\Client as KalturaClient;
use Kaltura\Client\Type\CategoryFilter as KalturaCategoryFilter;
use Kaltura\Client\Type\AssetFilter as KalturaAssetFilter;
use Kaltura\Client\Type\MediaEntry as KalturaMediaEntry;
use Kaltura\Client\Plugin\Metadata\MetadataPlugin as KalturaMetadataPlugin;
use Kaltura\Client\Enum\SessionType;
use Kaltura\Client\ClientException;
use Kaltura\Client\Plugin\Metadata\Type\MetadataFilter as KalturaMetadataFilter;
use Kaltura\Client\Enum\AssetStatus;
use Kaltura\Client\ApiException;
use Symfony\Component\HttpFoundation\File\File;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaMediaUpdateException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaMediaRetrieveException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaMetadataAccessException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaSessionInitException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaSetPublishException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaUploadFileException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaRemoveFileException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\VideoStatusException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\ImageStorageException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaListCategoryException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaProviderListNotFoundException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\KalturaThumbnailException;
use UEdit\Bundle\MultimediaManagerBundle\Manager\Exception\VideoStorageConnectionException;

/**
 * Kaltura manager class.
 *
 * Manages operations over Kaltura multimedia repository.
 *
 * @author Jose Manuel García Maleno <josemanuel.garcia@unidadeditorial.es>
 */
class KalturaManager implements ImageStorageInterface, VideoStorageInterface
{
    const FIRST_CATEGORY_VALUE = 'Últimos';
    const DEFAULT_THUMB_TAG = 'default_thumb';

    private $adminsecret;
    private $userid;
    private $partnerId;
    private $serviceurl;
    private $client;
    private $videoChannel;
    private $publishedCatId;
    private $customMetadataId;
    private $defaultAccessControlId;
    private $categoryFilterId;
    private $logger;

    public function __construct(
        $adminsecret,
        $userid,
        $partnerId,
        $serviceurl,
        $videoChannel,
        $publishedCatId,
        $customMetadataId,
        $defaultAccessControlId,
        $categoryFilterId,
        $type,
        LoggerInterface $logger
    ) {
        $this->adminsecret = $adminsecret;
        $this->userid = $userid;
        $this->partnerId = $partnerId;
        $this->serviceurl = $serviceurl;
        $this->client = null;
        $this->videoChannel = $videoChannel;
        $this->publishedCatId = $publishedCatId;
        $this->customMetadataId = $customMetadataId;
        $this->defaultAccessControlId = $defaultAccessControlId;
        $this->categoryFilterId = $categoryFilterId;
        $this->type = $type;
        $this->logger = $logger;
    }

    /**
     * createSession
     *
     * Creates a Kaltura session.
     *
     * @throws KalturaSessionInitException When there is an error starting a session in Kaltura.
     *
     * @return Client
     *
     * {@inheritdoc}
     */
    public function createSession()
    {
        if ($this->hasSession()) {
            return $this->client;
        }

        $kalturaConfig = new KalturaConfiguration($this->partnerId);

        $kalturaConfig->setServiceUrl($this->serviceurl);

        $this->client = new KalturaClient($kalturaConfig);

        try {
            $ks = $this->client->session->start(
                $this->adminsecret,
                $this->userid,
                SessionType::ADMIN,
                $this->partnerId,
                null,
                null
            );
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSessionInitException();
        }

        $this->client->setKs($ks);

        $this->client->type = $this->type;

        return $this->client;
    }

    /**
     * hasSession
     *
     * Checks if a kaltura session already exists.
     *
     * @return boolean
     *
     * {@inheritdoc}
     */
    public function hasSession()
    {
        return ($this->client !== null);
    }

    /**
     * ceateEntry
     *
     * Creates and returns an instance of MediaEntry.
     *
     * @return mixed
     *
     * {@inheritdoc}
     */
    public function createEntry()
    {
        return new KalturaMediaEntry();
    }

    /**
     * updateMetadataEntry
     *
     * Updates the given fields in the given metadata entry using the given Kaltura client.
     *
     * @param mixed  $client  Kaltura client.
     * @param array  $fields  Fields to update.
     * @param string $entryId Identifier of the metadata entry.
     *
     * @throws KalturaMetadataAccessException When there is an error retrieving metadata from Kaltura.
     *
     * {@inheritdoc}
     */
    public function updateMetadataEntry($client, $fields, $entryId)
    {
        try {
            $metadataPlugin = KalturaMetadataPlugin::get($client);

            $profileId = $this->customMetadataId;

            $metadataProfileFields = $metadataPlugin->metadataProfile->listFields($profileId)->objects;
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaMetadataAccessException();
        }

        $xsd = $metadataPlugin->metadataProfile->get($profileId)->xsd;

        $filter_meta = new KalturaMetadataFilter();
        $filter_meta->objectIdEqual = $entryId;

        $metadataProfileFields = array();

        $XSDDOC = new \DOMDocument();
        $XSDDOC->preserveWhiteSpace = false;

        if ($XSDDOC->loadXML($xsd)) {
            $xsdpath = new \DOMXPath($XSDDOC);

            $attributeNodes = $xsdpath->query("//xsd:element[not(contains(@name, 'metadata'))]");

            foreach ($attributeNodes as $attr) {
                $metadataProfileFields[$attr->getAttribute('name')] = $attr->getAttribute('name');
            }

            unset($xsdpath);
        }

        $result = $metadataPlugin->metadata->listAction($filter_meta);

        if (isset($result->objects[0]->id)) {
            $metadataId = $result->objects[0]->id;
            $metadataPlugin->metadata->delete($metadataId);
        }

        $xmlData = '<metadata>';

        foreach ($metadataProfileFields as $metadataField) {
            if (isset($fields[$metadataField])) {
                $xmlData .= '<'.$metadataField.'>'.$fields[$metadataField].'</'.$metadataField.'>';
            }
        }

        $xmlData .= '</metadata>';

        try {
            $metadataPlugin->metadata->add($profileId, 1, $entryId, $xmlData);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaMetadataAccessException();
        }
    }

    /**
     * getMetadataEntry
     *
     * Obtains a metadata entry. If client is null, creates a new Kaltura session.
     *
     * @param string $entryId Identifier of the metadata entry.
     * @param mixed  $client  Kaltura client.
     *
     * @throws KalturaSessionInitException    When there is an error starting session in Kaltura.
     * @throws KalturaMetadataAccessException When there is an error retrieving metadata from Kaltura.
     *
     * @return mixed SimpleXMLElement object.
     *
     * {@inheritdoc}
     */
    public function getMetadataEntry($entryId, $client = null)
    {
        if (null === $client) {
            try {
                $client = $this->createSession();
            } catch (\Exception $e) {
                $this->logger->error(__METHOD__." - ".$e->getMessage());

                throw new KalturaSessionInitException();
            }
        }

        try {
            $metadataPlugin = KalturaMetadataPlugin::get($client);

            $filter_meta = new KalturaMetadataFilter();
            $filter_meta->objectIdEqual = $entryId;

            $result = $metadataPlugin->metadata->listAction($filter_meta);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaMetadataAccessException();
        }

        $xml = null;

        if (isset($result->objects[0]->xml)) {
            $xml = simplexml_load_string($result->objects[0]->xml);
        }

        return $xml;
    }

    /**
     * uploadFile
     *
     * Uploads a file to the Kaltura backend.
     *
     * @param mixed  $entry
     * @param string $path  Path of the file to be uploaded.
     *
     * @throws KalturaSessionInitException When there is an error starting session in Kaltura.
     * @throws KalturaUploadFileException  When there is an error uploading a file to Kaltura.
     *
     * @return mixed
     *
     * {@inheritdoc}
     */
    public function uploadFile($entry, $path)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSessionInitException();
        }

        try {
            $token = $client->uploadToken->add();

            $uploadToken = $client->uploadToken->upload($token->id, $path);

            $entry = $client->baseEntry->addFromUploadedFile($entry, $uploadToken->id);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaUploadFileException();
        }

        return $entry;
    }

    /**
     * remove
     *
     * Removes a file from the Kaltura backend.
     *
     * @param string $entryId Identifier of the content to be removed from Kaltura.
     *
     * @throws KalturaSessionInitException When there is an error starting session in Kaltura.
     * @throws KalturaRemoveFileException  When there is an error removing a file from Kaltura.
     *
     * @return boolean True if remove was succesfull.
     *
     * {@inheritdoc}
     */
    public function remove($entryId)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSessionInitException();
        }

        try {
            $client->baseEntry->delete($entryId);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaRemoveFileException();
        }

        return true;
    }

    /**
     * publishOnKaltura
     *
     * Adds the category 'published' to a video on Kaltura.
     *
     * @param string $entryId Identifier of the metadata entry.
     * @param string $tyhpe   Format of the content to publish.
     *
     * @throws KalturaSessionInitException When there is an error starting session in Kaltura.
     * @throws KalturaSetPublishException  When there is an error setting as published a content
     *                                     in Kaltura.
     *
     * {@inheritdoc}
     */
    public function publishOnKaltura($entryId)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSessionInitException();
        }

        try {
            $entry = $this->createEntry();

            $video = $client->media->get($entryId);

            $categories = array();

            if (null != $video->categoriesIds) {
                $categories = \explode(",", $video->categoriesIds);
            }

            $categories[] = $this->publishedCatId;

            $entry->categoriesIds = \implode(",", $categories);

            $client->media->update($entryId, $entry);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSetPublishException();
        }
    }

    /**
     * publish
     *
     * Publish a content in Kaltura.
     *
     * @param string $path Path of the file to publish.
     *
     * @return string Identifier of the entry published in Kaltura.
     *
     * {@inheritdoc}
     */
    public function publish($path)
    {
        $entry = $this->createEntry();

        $entry->name        = ' ';
        $entry->mediaType   = \Kaltura\Client\Enum\MediaType::VIDEO;

        $entry = $this->uploadFile($entry, $path);

        $this->publishOnKaltura($entry->id);

        $videoData = array(
            'id'          => $entry->id,
            'title'       => $entry->mediaType,
            'description' => $entry->description,
        );

        return $videoData;
    }

    /**
     * update
     *
     * Updates the metadata of a content in Kaltura.
     *
     * @param string $entryId Identifier of the entry in Kaltura.
     * @param array  $fields  Fields to be updated.
     *
     * @throws KalturaSessionInitException When there is an error starting session in Kaltura.
     * @throws KalturaMediaUpdateException When there is an error updating media entry data.
     *
     * @return bool True if everything went ok.
     *
     * {@inheritdoc}
     */
    public function update($entryId, $fields)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSessionInitException();
        }

        $entry = $this->createEntry();

        $metadataEntry = $this->getMetadataEntry($entryId);

        if (!empty($fields['title'])) {
            $entry->name = $fields['title'];
        }

        if (!empty($fields['description'])) {
            $entry->description = $fields['description'];
        }

        if (!empty($fields['startDate'])) {
            $entry->startDate = $fields['startDate'];
        }

        if (!empty($fields['endDate'])) {
            $entry->endDate= $fields['endDate'];
        }

        if (!empty($fields['accessControl'])) {
            $entry->accessControlId = $fields['accessControl'];
        } else {
            $entry->accessControlId = $this->defaultAccessControlId;
        }

        if (!empty($fields['Channel'])) {
            $categoriesIds[] = $this->publishedCatId;
            $categoriesIds[] = $fields['Channel'];

            $entry->categoriesIds = \implode(",", $categoriesIds);

            $fields['VideoChannel'] = $this->videoChannel;
        }

        try {
            $client->media->update($entryId, $entry);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaMediaUpdateException();
        }

        $this->updateMetadataEntry($client, $fields, $entryId);

        return true;
    }

    /**
     * retrieve
     *
     * Retrieve the video metadata.
     *
     * @param string $entryId Identifier of the entry in Kaltura.
     *
     * @throws KalturaMediaRetrieveException When there is an error retrieving media entry data.
     *
     * @return array Video metadata.
     *
     * {@inheritdoc}
     */
    public function retrieve($entryId)
    {
        try {
            $client = $this->createSession();
            $entry = $client->media->get($entryId);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaMediaRetrieveException();
        }

        if (!$entry) {
            return array();
        }

        $videoData = array(
            'id' => $entry->id,
            'title' => $entry->name,
            'description' => $entry->description,
            'startDate' => $entry->startDate,
            'endDate' => $entry->endDate,
            'accessControl' => $entry->accessControlId
        );

        //In this moment the channel is a special category
        $categories = explode(',', $entry->categories);

        foreach ($categories as $category) {
            if (strpos($category, 'Publicadas') === false) {
                $channel = $this->getCategoryByName($category);
                if (isset($channel[0])) {
                    $videoData['channel'] = $channel[0]->id;
                }
            }
        }

        $metadataEntry = $this->getMetadataEntry($entryId, $client);

        if (isset($metadataEntry->Provider)) {
            $videoData['provider'] = (string) $metadataEntry->Provider;
        }

        // Kaltura's field is an editable text field, only the strings 'true' and '1' should be true.
        if (isset($metadataEntry->Advertising)) {
            $adValue = (string) $metadataEntry->Advertising;
            if ($adValue === '1' || $adValue  === 'true') {
                $videoData['advertising'] = true;
            } else {
                $videoData['advertising'] = false;
            }
        }

        return $videoData;
    }

    /**
     * Return the download url to retrieve the source video uploaded.
     *
     * @param string $entryId The video id, eg: 0_xwxnyqpg
     *
     * @return string The url to download
     */
    public function getDownloadUrl($entryId)
    {
        $url = "";
        try {
            $videoData = $this->retrieve($entryId);

            if (!$videoData) {
                throw new KalturaMediaRetrieveException();
            }

            $url = "http://k.uecdn.es/p/109/raw/entry_id/".$entryId."/file_name/name";
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaMediaRetrieveException();
        }

        return $url;
    }

    /**
     * getCategoryByName
     *
     * Search the category by its name.
     *
     * @param string $name   Category name.
     * @param mixed  $client Kaltura client.
     *
     * @return array Category data.
     */
    private function getCategoryByName($name, $client = null)
    {
        if (null === $client) {
            $client = $this->createSession();
        }

        $filter = new KalturaCategoryFilter();
        $filter->fullNameEqual = $name;

        $result = $client->category->listAction($filter, null);

        return $result->objects;
    }

    /**
     * getChannels
     *
     * Returns a list of channels
     *
     * {@inheritdoc}
     */
    public function getChannels()
    {
        $firstElement = array();
        $channels = array();
        try {
            $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new KalturaSessionInitException();
        }

        $filter = new KalturaCategoryFilter();
        $filter->fullIdsStartsWith = $this->categoryFilterId;

        try {
            $categories = $this->client->category->listAction($filter, null);
        } catch (ClientException $e) {
            throw new KalturaListCategoryException("Error Processing Request");
        }

        if ($categories->objects) {
            foreach ($categories->objects as $category) {
                if ($category->parentId != 0) {
                    //If it has subcategories it shouldn't appear in the list
                    if ($this->getParentIdInCategoriesArray($category->parentId, $categories->objects)) {
                        unset($channels[$category->parentId]);
                    }
                    if ($category->name == self::FIRST_CATEGORY_VALUE) {
                        $firstElement[$category->id] = $category->name;
                    } else {
                        $channels[$category->id] = $category->name;
                    }
                }
            }
            //Order by name
            asort($channels);
            $channels = $firstElement + $channels;
        }

        return $channels;
    }

    /**
     * Checks if a category is a subcategory of a list of categories given
     * @param  int     $categoryId The category to check
     * @param  $categories   A list of categories (array or something that can be iterable)
     * @return boolean True if the category is a subcategory of the categories given
     */
    private function getParentIdInCategoriesArray($categoryId, $categories)
    {
        foreach ($categories as $category) {
            if ($category->id === $categoryId) {
                return true;
            }
        }

        return false;
    }

    public function getProviderList()
    {
        try {
            $client = $this->createSession();
            $metadataPlugin = KalturaMetadataPlugin::get($client);

            $metadataProfile = $metadataPlugin->metadataProfile
                ->listAction()
                ->objects;

            $profileId = $metadataProfile[0]->id;

            $xsd = $metadataPlugin->metadataProfile
                ->get($profileId)
                ->xsd;

            $XSDDOC = new \DOMDocument();
            $XSDDOC->preserveWhiteSpace = false;

            if ($XSDDOC->loadXML($xsd)) {
                $xsdpath = new \DOMXPath($XSDDOC);
                $attributeNodes =
                    $xsdpath->query(
                        "//xsd:element[contains(@name, 'Provider')]/xsd:simpleType/xsd:restriction"
                    )->item(0);

                foreach ($attributeNodes->childNodes as $attr) {
                    $outputProviders[$attr->getAttribute('value')] = $attr->getAttribute('value');
                }

                unset($xsdpath);
            }
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__.' - '.$e->getMessage());

            throw new KalturaProviderListNotFoundException(
                'Error retrieving the provider list from Kaltura.'
            );
        }

        if (empty($outputProviders)) {
            return array();
        }

        $providerList = \array_values($outputProviders);

        return $providerList;
    }

    /**
     * {@inheritdoc}
     *
     * @author José Serrano <jose.serrano@unidadeditorial.es>
     */
    public function getDefaultThumbnail($id)
    {
        $thumbnails = $this->getVideoThumbnails($id);

        $thumbnails = $thumbnails->objects;

        if (empty($thumbnails)) {
            return null;
        }

        $defaultThumbnail = null;
        foreach ($thumbnails as $thumbnail) {
            $tags = $thumbnail->tags;
            //default thumbnails have a tag: 'default_thumb'
            if (!empty($tags) && $this->hasDefaultTag($tags)) {
                $defaultThumbnail = $thumbnail;
            }
        }

        if (\is_null($defaultThumbnail)) {
            return null;
        }

        $thumbnailId = $defaultThumbnail->id;

        $thumbnail = new Thumbnail();
        $thumbnail->setId($thumbnailId);
        $thumbnail->setDescription($defaultThumbnail->description);
        $thumbnail->setWidth($defaultThumbnail->width);
        $thumbnail->setHeight($defaultThumbnail->height);
        $thumbnail->setSize($defaultThumbnail->size);
        $thumbnail->setTags(array($defaultThumbnail->tags));
        $thumbnail->setCreatedAt($defaultThumbnail->createdAt);
        $thumbnail->setUpdatedAt($defaultThumbnail->updatedAt);
        $thumbnail->setUrl($this->getThumbnailUrl($thumbnailId));
        $thumbnail->setDefault(true);

        return $thumbnail;
    }

    /**
     * setDefaultThumbnail.
     *
     * Sets the given thumbnail as default in its Kaltura resource.
     *
     * @param string $thumbnailId Identifier of the thumbnail.
     *
     * @throws ImageStorageException     When there is an error creating session in Kaltura.
     * @throws KalturaThumbnailException When there is an error setting the thumbnail as default.
     * @throws ImageStorageException     When there is an error connecting to the videostorage system.
     *
     * @author Jose Manuel García Maleno <josemanuel.garcia@unidadeditorial.es>
     */
    public function setDefaultThumbnail($thumbnailId)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());

            throw new ImageStorageException();
        }

        try {
            $client->thumbAsset->setAsDefault($thumbnailId);
        } catch (ClientException $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new ImageStorageException();
        } catch (ApiException $e) {
            $this->logger->error(__METHOD__.' - '.$e->getMessage());

            throw new KalturaThumbnailException(
                "Error setting the thumbnail '$thumbnailId' as default."
            );
        }
    }

    /**
     * getVideoThumbnailList.
     *
     * Retrieves the list of thumbnails of a given resource.
     *
     * @param string $entryId the multimedia's id.
     *
     * @return array $thumbs array of Thumbnail objects.
     */
    public function getVideoThumbnailList($entryId)
    {
        $thumbList = $this->getVideoThumbnails($entryId);

        if ($thumbList->totalCount == 0) {
            $this->logger->error(__METHOD__ . ' - There are no thumbnails in the video '.$entryId);

            throw new KalturaThumbnailException(
                'Error retrieving thumbnail list.'
            );
        }

        $thumbs = array();

        foreach ($thumbList->objects as $thumbItem) {
            $default = false;
            $tags = $thumbItem->tags;
            //default thumbnails have a tag: 'default_thumb'
            if (!empty($tags) && $this->hasDefaultTag($tags)) {
                $default = true;
            }

            $thumb = new Thumbnail();
            $thumb->setId($thumbItem->id);
            $thumb->setDescription($thumbItem->description);
            $thumb->setWidth($thumbItem->width);
            $thumb->setHeight($thumbItem->height);
            $thumb->setSize($thumbItem->size);
            $thumb->setTags(array($thumbItem->tags));
            $thumb->setCreatedAt($thumbItem->createdAt);
            $thumb->setUpdatedAt($thumbItem->updatedAt);
            $thumb->setUrl($this->getThumbnailUrl($thumbItem->id));
            $thumb->setDefault($default);

            $thumbs[] = $thumb;
        }

        return $thumbs;
    }

    /**
     * getThumbnailUrl.
     *
     * Retrieves the parameterized thumbnail's public url.
     *
     * @param  string                    $id the thumbnail's id.
     * @return string                    $url the thumbnail's public url.
     * @throws KalturaThumbnailException when there was a problem
     *                                      retrieving the thumbnail's public url.
     *
     * @author José Serrano <jose.serrano@unidadeditorial.es>
     */
    public function getThumbnailUrl($id)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());
            throw new ImageStorageException();
        }

        try {
            $thumbService = $client->getThumbAssetService();
            $url = $thumbService->getUrl($id);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__.' - '.$e->getMessage());
            throw new KalturaThumbnailException(
                'Error retrieving the kaltura thumbnail URL.'
            );
        }

        return $url;
    }

    /**
     * isVideoReady
     *
     * Check if the video is encoded or not.
     *
     * @throws KalturaMediaRetrieveException   If there was any error retrieving the video.
     * @throws VideoStorageConnectionException When there are any errors connecting to videostorage system.
     * @throws VideoStatusException            If the video has an error.
     *
     * {@inheritdoc}
     */
    public function isVideoReady($entryId)
    {
        $client = $this->createSession();
        try {
            $entry = $client->media->get($entryId);
        } catch (ApiException $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());
            throw new KalturaMediaRetrieveException();
        } catch (ClientException $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());
            throw new VideoStorageConnectionException();
        }

        if ($entry->status == AssetStatus::ERROR) {
            throw new VideoStatusException("The entryId $entryId has errors");
        }

        if ($entry->status != AssetStatus::READY) {
            return false;
        }

        return true;
    }

    /**
     * getVideoThumbnails.
     *
     * Retrieves the parameterized video's thumbnail list
     * from Kaltura.
     *
     * @param  string                    $id the video's id.
     * @return array                     $thumblist array of ThumbAsset objects.
     * @throws ImageStorageException     when there was a problem
     *                                      creating the Kaltura session.
     * @throws KalturaThumbnailException when there was a problem
     *                                      retrieving the list of thumbnails.
     *
     * @author José Serrano <jose.serrano@unidadeditorial.es>
     */
    private function getVideoThumbnails($id)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());
            throw new ImageStorageException();
        }

        try {
            $thumbService = $client->getThumbAssetService();
            $filter = new KalturaAssetFilter();
            $filter->entryIdEqual = $id;
            $thumbList = $thumbService->listAction($filter);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());
            throw new KalturaThumbnailException(
                'Error retrieving thumbnail list.'
            );
        }

        return $thumbList;
    }

    /**
     * addThumbnailToResource
     *
     * Adds a thumbnail to the given resource.
     *
     * @throws KalturaThumbnailException When there is an error adding the thumbnail to the resource.
     * @throws ImageStorageException     When there is an error connecting to videostorage system.
     *
     * {@inheritdoc}
     */
    public function addThumbnailToResource(File $thumbnail, $id)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());
            throw new ImageStorageException();
        }

        try {
            $result = $client->thumbAsset->addfromimage($id, $thumbnail);
        } catch (ApiException $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());
            throw new KalturaThumbnailException(
                'Error uploading a thumbnail list.'
            );
        } catch (ClientException $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());
            throw new ImageStorageException();
        }

        $thumbnail = new Thumbnail();
        $thumbnail->setId($result->id);
        $thumbnail->setDescription($result->description);
        $thumbnail->setWidth($result->width);
        $thumbnail->setHeight($result->height);
        $thumbnail->setSize($result->size);
        $thumbnail->setTags(array($result->tags));
        $thumbnail->setCreatedAt($result->createdAt);
        $thumbnail->setUpdatedAt($result->updatedAt);
        $thumbnail->setUrl($this->getThumbnailUrl($result->id));
        $thumbnail->setDefault(false);

        return $thumbnail;
    }

    /**
     * removeThumbnail
     *
     * Removes a thumbnail resource by its id.
     *
     * {@inheritdoc}
     */
    public function removeThumbnail($thumbnailId)
    {
        try {
            $client = $this->createSession();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' - '.$e->getMessage());

            throw new ImageStorageException();
        }

        try {
            $client->thumbAsset->delete($thumbnailId);
        } catch (ClientException $e) {
            $this->logger->error(__METHOD__." - ".$e->getMessage());

            throw new ImageStorageException();
        } catch (ApiException $e) {
            $this->logger->error(__METHOD__.' - '.$e->getMessage());

            throw new KalturaThumbnailException(
                "Error removing the thumbnail with id '$thumbnailId'."
            );
        }
    }

    /**
     * hasDefaultTag.
     *
     * Checks the input string for the default tag.
     *
     * @param string $tags tags string.
     * @return bool if the default tag is present
     * in the tag string.
     *
     * @author José Serrano <jose.serrano@unidadeditorial.es>
     */
    private function hasDefaultTag($tags)
    {
        if (strpos($tags, self::DEFAULT_THUMB_TAG) !== false) {
            return true;
        }

        return false;
    }
}
