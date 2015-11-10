<?php

class PodcastEpisodeNotFoundException extends Exception {}

class DuplicatePodcastEpisodeException extends Exception {}

class Application_Service_PodcastEpisodeService extends Application_Service_ThirdPartyCeleryService implements Publish
{
    /**
     * Arbitrary constant identifiers for the internal tasks array
     */

    const DOWNLOAD = 'download';

    /**
     * @var string service name to store in ThirdPartyTrackReferences database
     */
    protected static $_SERVICE_NAME = PODCAST_SERVICE_NAME;  // Service name constant from constants.php

    /**
     * @var string exchange name for Podcast tasks
     */
    protected static $_CELERY_EXCHANGE_NAME = 'podcast';

    /**
     * @var array map of constant identifiers to Celery task names
     */
    protected static $_CELERY_TASKS = [
        self::DOWNLOAD => 'podcast-download'
    ];

    private static $privateFields = array(
        "id"
    );

    /**
     * Utility function to import and download a single episode
     *
     * @param int $podcastId ID of the podcast the episode should belong to
     * @param array $episode array of episode data to store
     *
     * @return PodcastEpisodes the stored PodcastEpisodes object
     */
    public function importEpisode($podcastId, $episode) {
        $e = $this->addPlaceholder($podcastId, $episode);
        $this->_download($e->getDbId(), $e->getDbDownloadUrl());
        return $e;
    }

    /**
     * Given an array of episodes, store them in the database as placeholder objects until
     * they can be processed by Celery
     *
     * @param int $podcastId    Podcast object identifier
     * @param array $episodes   array of podcast episodes
     *
     * @return array the stored PodcastEpisodes objects
     */
    public function addPodcastEpisodePlaceholders($podcastId, $episodes) {
        $storedEpisodes = array();
        foreach ($episodes as $episode) {
            try {
                $e = $this->addPlaceholder($podcastId, $episode);
            } catch(DuplicatePodcastEpisodeException $ex) {
                Logging::warn($ex->getMessage());
                continue;
            }
            array_push($storedEpisodes, $e);
        }
        return $storedEpisodes;
    }

    /**
     * Given an episode, store it in the database as a placeholder object until
     * it can be processed by Celery
     *
     * @param int $podcastId  Podcast object identifier
     * @param array $episode  array of podcast episode data
     *
     * @return PodcastEpisodes the stored PodcastEpisodes object
     *
     * @throws DuplicatePodcastEpisodeException
     */
    public function addPlaceholder($podcastId, $episode) {
        $existingEpisode = PodcastEpisodesQuery::create()->findOneByDbEpisodeGuid($episode["guid"]);
        if (!empty($existingEpisode)) {
            throw new DuplicatePodcastEpisodeException("Episode already exists: \n" . var_export($episode, true));
        }
        // We need to check whether the array is parsed directly from the SimplePie
        // feed object, or whether it's passed in as json
        $enclosure = $episode["enclosure"];
        $url = $enclosure instanceof SimplePie_Enclosure ? $enclosure->get_link() : $enclosure["link"];
        return $this->_buildEpisode($podcastId, $url, $episode["guid"], $episode["pub_date"]);
    }

    /**
     * Given episode parameters, construct and store a basic PodcastEpisodes object
     *
     * @param int $podcastId            the podcast the episode belongs to
     * @param string $url               the download URL for the episode
     * @param string $guid              the unique id for the episode. Often the same as the download URL
     * @param string $publicationDate   the publication date of the episode
     *
     * @return PodcastEpisodes the newly created PodcastEpisodes object
     *
     * @throws Exception
     * @throws PropelException
     */
    private function _buildEpisode($podcastId, $url, $guid, $publicationDate) {
        $e = new PodcastEpisodes();
        $e->setDbPodcastId($podcastId);
        $e->setDbDownloadUrl($url);
        $e->setDbEpisodeGuid($guid);
        $e->setDbPublicationDate($publicationDate);
        $e->save();
        return $e;
    }

    /**
     * Given an array of episodes, extract the IDs and download URLs and send them to Celery
     *
     * @param array $episodes array of podcast episodes
     */
    public function downloadEpisodes($episodes) {
        /** @var PodcastEpisodes $episode */
        foreach($episodes as $episode) {
            $this->_download($episode->getDbId(), $episode->getDbDownloadUrl());
        }
    }

    /**
     * Given an episode ID and a download URL, send a Celery task
     * to download an RSS feed track
     *
     * @param int $id       episode unique ID
     * @param string $url   download url for the episode
     */
    private function _download($id, $url) {
        $CC_CONFIG = Config::getConfig();
        $data = array(
            'id'            => $id,
            'url'           => $url,
            'callback_url'  => Application_Common_HTTPHelper::getStationUrl() . '/rest/media',
            'api_key'       => $apiKey = $CC_CONFIG["apiKey"][0],
        );
        $this->_executeTask(static::$_CELERY_TASKS[self::DOWNLOAD], $data);
    }

    /**
     * Update a ThirdPartyTrackReferences object for a completed upload
     *
     * @param $task         CeleryTasks the completed CeleryTasks object
     * @param $episodeId    int         PodcastEpisodes identifier
     * @param $episode      stdClass    simple object containing Podcast episode information
     * @param $status       string      Celery task status
     *
     * @return ThirdPartyTrackReferences the updated ThirdPartyTrackReferences object
     *
     * @throws Exception
     * @throws PropelException
     */
    public function updateTrackReference($task, $episodeId, $episode, $status) {
        $ref = parent::updateTrackReference($task, $episodeId, $episode, $status);

        $dbEpisode = PodcastEpisodesQuery::create()
            ->findOneByDbId($episode->episodeid);

        // If the placeholder for the episode is somehow removed, return with a warning
        if (!$dbEpisode) {
            Logging::warn("Celery task $task episode $episode->episodeid unsuccessful: episode placeholder removed");
            return $ref;
        }

        // Even if the task itself succeeds, the download could have failed, so check the status
        if ($status == CELERY_SUCCESS_STATUS && $episode->status == 1) {
            $dbEpisode->setDbFileId($episode->fileid)->save();
        } else {
            Logging::warn("Celery task $task episode $episode->episodeid unsuccessful with message $episode->error");
            $dbEpisode->delete();
        }

        return $ref;
    }

    /**
     * Publish the file with the given file ID to the station podcast
     *
     * @param int $fileId ID of the file to be published
     */
    public function publish($fileId) {
        $id = Application_Model_Preference::getStationPodcastId();
        $url = $guid = Application_Common_HTTPHelper::getStationUrl()."rest/media/$fileId/download";
        if (!PodcastEpisodesQuery::create()
            ->filterByDbPodcastId($id)
            ->findOneByDbFileId($fileId)) {  // Don't allow duplicate episodes
            $e = $this->_buildEpisode($id, $url, $guid, date('r'));
            $e->setDbFileId($fileId)->save();
        }
    }

    /**
     * Unpublish the file with the given file ID from the station podcast
     *
     * @param int $fileId ID of the file to be unpublished
     */
    public function unpublish($fileId) {
        $id = Application_Model_Preference::getStationPodcastId();
        PodcastEpisodesQuery::create()
            ->filterByDbPodcastId($id)
            ->findOneByDbFileId($fileId)
            ->delete();
    }

    /**
     * @param $episodeId
     * @return array
     * @throws PodcastEpisodeNotFoundException
     */
    public static function getPodcastEpisodeById($episodeId)
    {
        $episode = PodcastEpisodesQuery::create()->findPk($episodeId);
        if (!$episode) {
            throw new PodcastEpisodeNotFoundException();
        }

        return $episode->toArray(BasePeer::TYPE_FIELDNAME);
    }

    /**
     * Returns an array of Podcast episodes, with the option to paginate the results.
     *
     * @param $podcastId
     * @param int $offset
     * @param int $limit
     * @param string $sortColumn
     * @param string $sortDir "ASC" || "DESC"
     * @return array
     * @throws PodcastNotFoundException
     */
    public function getPodcastEpisodes($podcastId,
                                       $offset=0,
                                       $limit=10,
                                       $sortColumn=PodcastEpisodesPeer::PUBLICATION_DATE,
                                       $sortDir="ASC")
    {
        $podcast = PodcastQuery::create()->findPk($podcastId);
        if (!$podcast) {
            throw new PodcastNotFoundException();
        }

        $sortDir = ($sortDir === "DESC") ? $sortDir = Criteria::DESC : Criteria::ASC;
        $isStationPodcast = $podcastId == Application_Model_Preference::getStationPodcastId();

        $episodes = PodcastEpisodesQuery::create()
            ->filterByDbPodcastId($podcastId);
        if ($isStationPodcast && $limit != 0) {
            $episodes = $episodes->setLimit($limit);
        }
        // XXX: We should maybe try to alias this so we don't pass CcFiles as an array key to the frontend.
        //      It would require us to iterate over all the episodes and change the key for the response though...
        $episodes = $episodes->joinWith('PodcastEpisodes.CcFiles', Criteria::LEFT_JOIN)
            ->setOffset($offset)
            ->orderBy($sortColumn, $sortDir)
            ->find();

        return $isStationPodcast ? $this->_getStationPodcastEpisodeArray($episodes)
                                 : $this->_getImportedPodcastEpisodeArray($podcast, $episodes);
    }

    /**
     * Given an array of PodcastEpisodes objects from the Station Podcast,
     * convert the episode data into array form
     *
     * @param array $episodes array of PodcastEpisodes to convert
     * @return array
     */
    private function _getStationPodcastEpisodeArray($episodes) {
        $episodesArray = array();
        foreach ($episodes as $episode) {
            /** @var PodcastEpisodes $episode */
            $episodeArr = $episode->toArray(BasePeer::TYPE_FIELDNAME, true, [], true);
            array_push($episodesArray, $episodeArr);
        }
        return $episodesArray;
    }

    /**
     * Given an ImportedPodcast object and an array of stored PodcastEpisodes objects,
     * fetch all episodes from the podcast RSS feed, and serialize them in a readable form
     *
     * TODO: there's definitely a better approach than this... we should be trying to create
     *       PodcastEpisdoes objects instead of our own arrays
     *
     * @param ImportedPodcast   $podcast    Podcast object to fetch the episodes for
     * @param array             $episodes   array of PodcastEpisodes objects to
     *
     * @return array array of episode data
     *
     * @throws CcFiles/FileNotFoundException
     */
    public function _getImportedPodcastEpisodeArray($podcast, $episodes) {
        $rss = Application_Service_PodcastService::getPodcastFeed($podcast->getDbUrl());
        $episodeIds = array();
        $episodeFiles = array();
        foreach ($episodes as $e) {
            /** @var PodcastEpisodes $e */
            array_push($episodeIds, $e->getDbEpisodeGuid());
            $episodeFiles[$e->getDbEpisodeGuid()] = $e->getDbFileId();
        }

        $episodesArray = array();
        foreach ($rss->get_items() as $item) {
            /** @var SimplePie_Item $item */
            // If the enclosure is empty or has not URL, this isn't a podcast episode (there's no audio data)
            $enclosure = $item->get_enclosure();
            $url = $enclosure instanceof SimplePie_Enclosure ? $enclosure->get_link() : $enclosure["link"];
            if (empty($url)) { continue; }
            $itemId = $item->get_id();
            $ingested = in_array($itemId, $episodeIds) ? (empty($episodeFiles[$itemId]) ? -1 : 1) : 0;
            $file = $ingested > 0 && !empty($episodeFiles[$itemId]) ?
                CcFiles::getSanitizedFileById($episodeFiles[$itemId]) : array();

            array_push($episodesArray, array(
                "podcast_id" => $podcast->getDbId(),
                "guid" => $itemId,
                "ingested" => $ingested,
                "title" => $item->get_title(),
                // From the RSS spec best practices:
                // 'An item's author element provides the e-mail address of the person who wrote the item'
                "author" => $this->_buildAuthorString($item),
                "description" => htmlspecialchars($item->get_description()),
                "pub_date" => $item->get_gmdate(),
                "link" => $item->get_link(),
                "enclosure" => $item->get_enclosure(),
                "file" => $file
            ));
        }

        return $episodesArray;
    }

    /**
     * Construct a string representation of the author fields of a SimplePie_Item object
     *
     * @param SimplePie_Item $item the SimplePie_Item to extract the author data from
     *
     * @return string the string representation of the author data
     */
    private function _buildAuthorString(SimplePie_Item $item) {
        $authorString = $author = $item->get_author();
        if (!empty($author)) {
            $authorString = $author->get_email();
            $authorString = empty($authorString) ? $author->get_name() : $authorString;
        }

        return $authorString;
    }

    public function deletePodcastEpisodeById($episodeId)
    {
        $episode = PodcastEpisodesQuery::create()->findByDbId($episodeId);

        if ($episode) {
            $episode->delete();
        } else {
            throw new PodcastEpisodeNotFoundException();
        }
    }

    private function removePrivateFields(&$data)
    {
        foreach (self::$privateFields as $key) {
            unset($data[$key]);
        }
    }

}