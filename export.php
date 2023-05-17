<?php

/**
 * @file export.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Exporter
 *
 * @brief Exports OCS data to be used by the import script
 */

class Exporter
{
    /** Count of exported papers */
    private $exportedPapers = 0;
    /** Count of failed papers */
    private $failedPapers = 0;
    /** Path where the script is running */
    private $runningPath;
    /** Path for the OCS installation */
    private $ocsPath;
    /** Path where the deliverables are going to be placed */
    private $outputPath;
    /** Force flag */
    private $force = false;
    /** List of conferences to export (empty = all) */
    private $conferences = [];
    /** Target OJS installation */
    private $target;
    /** Merged list of tracks by conference */
    private $uniqueTrackIds = [];
    /** Whether supplementary files must be exported as public galleys */
    private $supplementaryFileAsGalley = false;

    /**
     * Feeds the script with command line arguments
     */
    public static function run($argv)
    {
        $options = getopt('i:o:t:fs');
        if (empty($options['i']) || empty($options['o']) || empty($options['t'])) {
            exit(
                "Usage:\n"
                . "export.php -i PATH_TO_OCS_INSTALLATION -o OUTPUT_PATH -t TARGET_OJS_VERSION [-f] [-s] [conference_path1 [conferenceN...]]\n"
                . "-t\tPossible values are: stable-3_2_1, stable-3_3_3 and stable-3_4_0\n"
                . "-s\tWhen specified will turn supplementary files into public galleys\n"
                . "-f\tWhen specified will ignore the warnings\n\a"
            );
        }

        $conferences = array_filter(array_slice($argv, count($options) + count(array_filter($options, 'is_string')) + 1), 'strlen');
        new static($options['i'], $options['o'], strtolower($options['t']), $conferences, isset($options['s']), isset($options['f']));
    }

    /**
     * Initializes the process
     */
    private function __construct($ocsPath, $outputPath, $target, $conferences, $supplementaryFileAsGalley, $force)
    {
        $exception = $defaultException = new Exception('An unexpected error has happened');
        try {
            ini_set('memory_limit', -1);
            set_time_limit(0);
            $writers = [];
            foreach (new FilesystemIterator('writers') as $writer) {
                if (strpos($writer->getFilename(), 'stable') === 0) {
                    $writers[] = $writer->getBasename('.php');
                }
            }
            if (!in_array($target, $writers)) {
                throw new DomainException('Invalid target argument, available OJS versions: ' . implode(', ', $writers));
            }
            $this->target = $target;
            $this->runningPath = realpath(getcwd());
            $this->ocsPath = realpath($ocsPath);
            $this->outputPath = $outputPath;
            $this->force = $force;
            $this->conferences = $conferences;
            $this->supplementaryFileAsGalley = $supplementaryFileAsGalley;
            $this->bootOcs();
            $this->checkOcsVersion();
            $this->createOutputPath();
            $this->exportMetadata();
            $this->exportPapers();
            $exception = null;
        } catch (Exception $exception) {
        } finally {
            if ($exception === $defaultException && error_get_last()) {
                $exception = new Exception(print_r(error_get_last(), true), 0, $exception);
            }
            if ($exception instanceof DomainException) {
                $this->log($exception->getMessage());
            } else {
                $this->log($exception ? "Export failed with {$exception}" : 'Export finished with success');
                $this->log("Exported papers: {$this->exportedPapers}");
                $this->log("Failed papers: {$this->failedPapers}");
            }
        }
        echo chr(7);
    }

    /**
     * Creates the output path
     */
    private function createOutputPath()
    {
        $currentDirectory = getcwd();
        chdir($this->runningPath);
        if (is_dir($this->outputPath)) {
            if (!$this->force) {
                $this->outputPath = realpath($this->outputPath);
                throw new DomainException("Output path \"{$this->outputPath}\" already exists, quitting for security. Ensure the output path doesn't exist or use the -f option");
            }
        } else {
            static::log("Creating output path {$this->outputPath}");
            if (!mkdir($this->outputPath, 0777, true)) {
                throw new DomainException('Failed to create output directory');
            }
        }
        $this->outputPath = realpath($this->outputPath);
        static::log("Output path created");
        chdir($currentDirectory);
    }

    /**
     * Initializes the OCS installation (we're going to use its internals to retrieve data)
     */
    private function bootOcs()
    {
        if (!is_file($this->ocsPath . "/config.inc.php")) {
            throw new DomainException("The path \"{$this->ocsPath}\" doesn't seem to be a valid OCS installation, the config.inc.php file wasn't found.");
        }

        require_once "{$this->ocsPath}/tools/bootstrap.inc.php";
        new CommandLineTool();
        // Attempt to use UTF-8
        $dao = new DAO();
        $dao->update("SET NAMES 'utf8'", false, true, false);
        $dao->update("SET client_encoding = 'UTF8'", false, true, false);
        $this->log('Booting complete');
    }

    /**
     * Logs messages
     */
    private static function log($message)
    {
        echo "{$message}\n";
    }

    /**
     * Retrieve the OCS version at the database
     */
    private function getOcsVersion() {
        $version = $this->readAll(
            "SELECT v.major, v.minor, v.revision, v.build
            FROM versions v
            WHERE
                v.current = 1
                AND v.product_type = 'core'
                AND v.product = 'ocs2'"
        );
        return implode('.', (array) reset($version));
    }

    /**
     * Checks if the OCS version is supported by the script
     */
    private function checkOcsVersion() {
        $this->log('Checking OCS version');

        $requiredVersion = '2.3.6.0';
        $version = $this->getOcsVersion();
        if ($version !== $requiredVersion) {
            if ($this->force) {
                $this->log('OCS version check failed, but the problem was ignored');
                return;
            }
            throw new DomainException("This script is compatible only with OCS {$requiredVersion}, your OCS version {$version} must be downgraded/upgraded");
        }
        $this->log('OCS version checked');
    }

    /**
     * Exports a JSON file with the OCS metadata, which is needed at the import script
     */
    private function exportMetadata()
    {
        $path = $this->outputPath . "/metadata.json";
        $this->log("Exporting OCS metadata to {$path}");
        if (!file_put_contents($path, json_encode([
                'journals' => $this->getConferences(),
                'ocs' => $this->getOcsVersion(),
                'ojs' => str_replace('_', '.', explode('-', $this->target)[1])
            ], JSON_PRETTY_PRINT)
        )) {
                throw new DomainException('Failed to generate the OCS metadata');
        }
        $this->log('Metadata exported');
    }

    /**
     * Exports all papers by generating custom XML import files
     */
    private function exportPapers()
    {
        $this->log('Exporting OCS papers');

        /** @var ConferenceDAO */
        $conferenceDao = DAORegistry::getDAO('ConferenceDAO');
        /** @var SchedConfDAO */
        $schedConfDao = DAORegistry::getDAO('SchedConfDAO');
        /** @var TrackDAO */
        $trackDao = DAORegistry::getDAO('TrackDAO');
        /** @var PublishedPaperDAO */
        $publishedPaperDao = DAORegistry::getDAO('PublishedPaperDAO');

        mkdir("{$this->outputPath}/papers", 0777, true);

        $conferences = count($this->conferences)
            ? array_map(
                function ($conference) use ($conferenceDao) {
                    return $conferenceDao->getConferenceByPath($conference);
                }, $this->conferences
            )
            : $conferenceDao->getConferences()->toArray();
        foreach ($conferences as $conference) {
            $locale = $conference->getPrimaryLocale();
            $this->log("Processing conference \"{$conference->getTitle($locale)}\"");
            $tracks = $trackDao->getConferenceTracks($conference->getId())->toAssociativeArray('id');
            foreach ($schedConfDao->getSchedConfsByConferenceId($conference->getId())->toArray() as $schedConf) {
                $this->log("Processing scheduled conference \"{$schedConf->getTitle($locale)}\"");
                foreach ($publishedPaperDao->getPublishedPapersBySchedConfId($schedConf->getId())->toArray() as $paper) {
                    $this->log('Processing paper ID ' . $paper->getPaperId());
                    $trackId = $this->uniqueTrackIds[$paper->getTrackId()];
                    $track = isset($tracks[$trackId]) ? $tracks[$trackId] : $trackId = null;
                    $filename = "{$conference->getId()}-{$schedConf->getId()}-{$trackId}-{$paper->getId()}.xml";
                    $path = "{$this->outputPath}/papers/{$filename}";
                    try {
                        $className = $this->getWriterClass();
                        /** @var BaseXmlWriter */
                        $writer = new $className($conference, $schedConf, $track, $paper, $this->supplementaryFileAsGalley);
                        if (!file_put_contents($path, $writer->process())) {
                            throw new Exception("Failed to write paper to {$path}");
                        }
                        $this->log("Paper XML generated as {$filename}");
                        ++$this->exportedPapers;
                    } catch(Exception $e) {
                        $this->log("Failed to generate paper with {$e}");
                        ++$this->failedPapers;
                        continue;
                    }
                }
            }
        }

        $this->log('Papers exported');
    }

    /**
     * Retrieves a list of conferences
     */
    private function getConferences()
    {
        $conferenceParams = substr(str_repeat('?,', count($this->conferences)), 0, -1);
        list($filter, $params) = count($this->conferences) ? [sprintf('WHERE c.path IN (%s)', $conferenceParams), $this->conferences] : ['', []];

        $conferences = $this->readAll(
            "SELECT c.conference_id, c.path, c.primary_locale, c.enabled, cs.setting_name, cs.setting_value, cs.locale
            FROM conferences c
            LEFT JOIN conference_settings cs
                ON cs.conference_id = c.conference_id
                AND cs.setting_name IN (
                    'authorInformation',
                    'contactEmail',
                    'contactName',
                    'description',
                    'itemsPerPage',
                    'lockssLicense',
                    'numPageLinks',
                    'privacyStatement',
                    'readerInformation',
                    'supportedFormLocales',
                    'supportedLocales',
                    'title'
                )
            $filter
            ORDER BY c.seq DESC",
            $params
        );

        // Merges the settings we can reuse with the entity data
        $conferences = array_values(
            array_reduce($conferences, function($conferences, $conference) {
                $conferences[$conference->conference_id] = isset($conferences[$conference->conference_id])
                    ? $conferences[$conference->conference_id]
                    : [
                        'id' => $conference->conference_id,
                        'urlPath' => $conference->path,
                        'primary_locale' => $this->getTargetLocale($conference->primary_locale),
                        'enabled' => $conference->enabled
                    ];
                if ($conference->setting_name === 'title') {
                    $conference->setting_value = strip_tags($conference->setting_value);
                }
                if ($conference->locale) {
                    $conference->locale = $this->getTargetLocale($conference->locale);
                    $conferences[$conference->conference_id][$conference->setting_name][$conference->locale] = $conference->setting_value;
                } else {
                    $conferences[$conference->conference_id][$conference->setting_name] = $conference->setting_value;
                }
                return $conferences;
            }, [])
        );

        if (count($this->conferences) && count($conferences) !== count($this->conferences)) {
            $notFound = array_diff(
                $this->conferences,
                array_map(
                    function ($conference) {
                        return $conference->urlPath;
                    },
                    $conferences
                )
            );
            throw new DomainException('The following conferences were not found:' . implode(', ', $notFound));
        }

        // Adjust/feed/rename some data to fit better into the import script
        foreach ($conferences as &$conference) {
            $conference['primaryLocale'] = $conference['primary_locale'];
            if (isset($conference['title'])) {
                $conference['name'] = $conference['title'];
            }
            $nonEmptyLocaleList = null;
            foreach (['supportedLocales', 'supportedFormLocales'] as $field) {
                if (isset($conference[$field])) {
                    $locales = unserialize($conference[$field]);
                    $conference[$field] = is_array($locales) ? $locales : [];
                    foreach ($conference[$field] as &$locale) {
                        $locale = $this->getTargetLocale($locale);
                    }
                    $nonEmptyLocaleList || $nonEmptyLocaleList = $conference[$field];
                }
            }
            if ($nonEmptyLocaleList) {
                foreach (['supportedFormLocales', 'supportedLocales', 'supportedSubmissionLocales'] as $field) {
                    if (!isset($conference[$field])) {
                        $conference[$field] = $nonEmptyLocaleList;
                    }
                }
            }
            unset($conference['primary_locale'], $conference['title']);
            $conference['issues'] = $this->getScheduledConferences($conference['urlPath']);
            $conference['sections'] = $this->getTracks($conference['urlPath']);
        }

        return $conferences;
    }

    /**
     * Retrieves a list of scheduled conferences for a given conference path
     */
    private function getScheduledConferences($conference)
    {
        $scheduledConferences = $this->readAll(
            "SELECT sc.sched_conf_id, sc.path, sc.seq, sc.start_date, sc.end_date, scs.setting_name, scs.setting_value, scs.locale
            FROM sched_confs sc
            INNER JOIN conferences c ON c.conference_id = sc.conference_id AND c.path = ?
            LEFT JOIN sched_conf_settings scs
                ON scs.sched_conf_id = sc.sched_conf_id
                AND scs.setting_name IN ('title', 'overview', 'introduction')
            ORDER BY sc.seq DESC",
            [$conference]
        );

        $currentVolume = 0;
        // Merges the settings we can reuse with the entity data
        $scheduledConferences = array_values(
            array_reduce($scheduledConferences, function($scheduledConferences, $scheduledConference) use (&$currentVolume) {
                $scheduledConferences[$scheduledConference->sched_conf_id] = isset($scheduledConferences[$scheduledConference->sched_conf_id])
                    ? $scheduledConferences[$scheduledConference->sched_conf_id]
                    : [
                        'id' => $scheduledConference->sched_conf_id,
                        'path' => $scheduledConference->path,
                        'volume' => ++$currentVolume,
                        'number' => 1,
                        'startDate' => $scheduledConference->start_date,
                        'endDate' =>  $scheduledConference->end_date
                    ];
                if ($scheduledConference->setting_name === 'title') {
                    $scheduledConference->setting_value = strip_tags($scheduledConference->setting_value);
                }
                if ($scheduledConference->locale) {
                    $scheduledConference->locale = $this->getTargetLocale($scheduledConference->locale);
                    $scheduledConferences[$scheduledConference->sched_conf_id][$scheduledConference->setting_name][$scheduledConference->locale] = $scheduledConference->setting_value;
                } else {
                    $scheduledConferences[$scheduledConference->sched_conf_id][$scheduledConference->setting_name] = $scheduledConference->setting_value;
                }
                return $scheduledConferences;
            }, [])
        );

        // Adjust
        return array_map(function ($schedConf) {
            foreach (['overview', 'introduction'] as $field) {
                if (!empty($schedConf[$field])) {
                    $schedConf['description'] = $schedConf[$field];
                    break;
                }
            }
            $schedConf['year'] = date('Y', strtotime(empty($schedConf['endDate']) ? $schedConf['startDate'] : $schedConf['endDate']));
            unset($schedConf['overview'], $schedConf['introduction']);
            return $schedConf;
        }, $scheduledConferences);
    }

    /**
     * Retrieves a list of tracks for a given conference path
     */
    private function getTracks($conference)
    {
        $locale = $this->readAll(
            "SELECT c.primary_locale AS locale
            FROM conferences c
            WHERE c.path = ?",
            [$conference]
        );
        $locale = $this->getTargetLocale(array_pop($locale)->locale);

        $tracks = $this->readAll(
            "SELECT t.track_id, t.seq, ts.setting_name, ts.setting_value, ts.locale
            FROM tracks t
            INNER JOIN sched_confs sc ON sc.sched_conf_id = t.sched_conf_id
            INNER JOIN conferences c ON c.conference_id = sc.conference_id AND c.path = ?
            LEFT JOIN track_settings ts
                ON ts.track_id = t.track_id
                AND ts.setting_name IN ('title', 'abbrev', 'policy')
            ORDER BY sc.seq DESC, t.seq DESC",
            [$conference]
        );

        // Merges the settings we can reuse with the entity data
        $tracks = array_reduce($tracks, function($tracks, $track) {
            $tracks[$track->track_id] = isset($tracks[$track->track_id]) ? $tracks[$track->track_id] : ['id' => $track->track_id];
            if ($track->setting_name !== 'policy') {
                $track->setting_value = strip_tags($track->setting_value);
            }
            if ($track->locale) {
                $track->locale = $this->getTargetLocale($track->locale);
                $tracks[$track->track_id][$track->setting_name][$track->locale] = $track->setting_value;
            } else {
                $tracks[$track->track_id][$track->setting_name] = $track->setting_value;
            }
            return $tracks;
        }, []);

        // Attempts to merge similar tracks by conference
        return array_values(
            array_reduce($tracks, function($tracks, $track) use ($locale) {
                isset($track['title']) || $track['title'] = [$locale => 'General'];
                isset($track['abbrev']) || $track['abbrev'] = [$locale => 'GEN'];
                $title = isset($track['title'][$locale]) ? $track['title'][$locale] : reset($track['title']);
                $tracks[$title] = isset($tracks[$title]) ? $tracks[$title] : $track;
                $this->uniqueTrackIds[$track['id']] = $tracks[$title]['id'];
                return $tracks;
            }, [])
        );
    }

    /**
     * Runs the query and retrieves a clean array with each row while ensuring data is composed of valid UTF-8 characters
     */
    private function readAll($query, $params = [])
    {
        $dao = new DAO();
        $rs = $dao->retrieve($query, $params);
        $data = [];
        while (!$rs->EOF) {
            $row = (object) $rs->GetRowAssoc(0);
            foreach ($row as &$value) {
                if ($value !== null) {
                    // Attempt to convert to UTF-8 (just to detect bad encoded characters) and convert the problematic characters
                    if (($newValue = iconv('UTF-8', 'UTF-8//TRANSLIT', $value)) === false) {
                        // Fallback to removing them
                        $newValue = iconv('UTF-8', 'UTF-8//IGNORE', $value);
                    }
                    $value = trim($newValue);
                }
            }
            $data[] = $row;
            $rs->MoveNext();
        }
        $rs->Close();
        return $data;
    }

    /**
     * Retrieves the class name of the specialized XML writer and loads the file
     * @return class-string<BaseXmlWriter>
     */
    private function getWriterClass()
    {
        require_once "writers/{$this->target}.php";
        return ucwords(preg_replace('/[^a-z0-9]/', '', $this->target)) . 'Writer';
    }

    /**
     * Shortcut for the specialized getTargetLocale()
     */
    private function getTargetLocale($locale)
    {
        return call_user_func([$this->getWriterClass(), 'getTargetLocale'], $locale);
    }
}

Exporter::run($argv);
