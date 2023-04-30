<?php

class Exporter {
    private $runningPath;
    private $ocsPath;
    private $outputPath;
    private $force = false;
    private $conferences = [];
    private $target;
    private $uniqueTrackIds = [];

    public static function run($argv)
    {
        $options = getopt('i:o:t:f');
        if (empty($options['i']) || empty($options['o']) || empty($options['t'])) {
            exit("Usage:\nexport.php -i PATH_TO_OCS_INSTALLATION -o OUTPUT_PATH -t TARGET_OJS_VERSION [-f] [conference_path1 [conferenceN...]]");
        }

        $conferences = array_filter(array_slice($argv, count($options) + count(array_filter($options, 'is_string')) + 1), 'strlen');
        new static($options['i'], $options['o'], strtolower($options['t']), $conferences, isset($options['f']));
    }

    private function __construct($ocsPath, $outputPath, $target, $conferences, $force)
    {
        $exception = null;
        try {
            ini_set('memory_limit', -1);
            set_time_limit(0);
            $generators = [];
            foreach (new FilesystemIterator('generators') as $generator) {
                $generators[] = $generator->getBasename('.php');
            }
            if (!in_array($target, $generators)) {
                throw new Exception('Invalid target argument, available OJS versions: ' . implode(', ', $generators));
            }
            $this->target = $target;
            $this->runningPath = realpath(getcwd());
            $this->ocsPath = realpath($ocsPath);
            $this->outputPath = $outputPath;
            $this->force = $force;
            $this->conferences = $conferences;
            $this->bootOcs();
            $this->checkOcsVersion();
            $this->createOutputPath();
            $this->exportMetadata();
            $this->exportPapers();
        } catch (Exception $exception) {
        } finally {
            $this->log($exception ? "Export failed with {$exception}" : 'Export finished with success');
            echo chr(7);
        }
    }

    private function createOutputPath()
    {
        $currentDirectory = getcwd();
        chdir($this->runningPath);
        if (is_dir($this->outputPath)) {
            if (!$this->force) {
                $this->outputPath = realpath($this->outputPath);
                throw new Exception("Output path \"{$this->outputPath}\" already exists, quitting for security. Ensure the output path doesn't exist or use the -f option");
            }
        } else {
            static::log("Creating output path {$this->outputPath}");
            if (!mkdir($this->outputPath, 0777, true)) {
                throw new Exception('Failed to create output directory');
            }
        }
        $this->outputPath = realpath($this->outputPath);
        static::log("Output path created");
        chdir($currentDirectory);
    }

    private function bootOcs()
    {
        if (!is_file($this->ocsPath . "/config.inc.php")) {
            throw new Exception("The path \"{$this->ocsPath}\" doesn't seem to be a valid OCS installation, the config.inc.php file wasn't found.");
        }

        $this->log('Booting OCS');
        define('INDEX_FILE_LOCATION', "{$this->ocsPath}/index.php");
        chdir(dirname(INDEX_FILE_LOCATION));
        require_once 'lib/pkp/includes/bootstrap.inc.php';
        Application::getRequest();
        // Attempt to use UTF-8
        (new DAO())->update("SET NAMES 'utf8'", false, true, false);
        (new DAO())->update("SET client_encoding = 'UTF8'", false, true, false);
        $this->log('Booting complete');
    }

    private static function log($message)
    {
        echo "{$message}\n";
    }

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

    private function checkOcsVersion() {
        $this->log('Checking OCS version');

        $requiredVersion = '2.3.6.0';
        $version = $this->getOcsVersion();
        if ($version !== $requiredVersion) {
            if ($this->force) {
                $this->log('OCS version check failed, but the problem was ignored');
                return;
            }
            throw new Exception("This script is compatible only with OCS {$requiredVersion}, your OCS version {$version} must be downgraded/upgraded");
        }
        $this->log('OCS version checked');
    }

    private function exportMetadata()
    {
        $path = $this->outputPath . "/metadata.json";
        $this->log("Exporting OCS metadata to {$path}");
        if (!file_put_contents($path, json_encode([
                'journals' => $this->getConferences(),
                'ocs' => $this->getOcsVersion(),
                'ojs' => str_replace('_', '.', explode('-', $this->target)[1])
            ], JSON_PRETTY_PRINT))) {
            throw new Exception('Failed to generate the OCS metadata');
        }
        $this->log('Metadata exported');
    }

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
        $locale = AppLocale::getPrimaryLocale();
        $conferences = count($this->conferences)
            ? array_map(
                function ($conference) use ($conferenceDao) {
                    return $conferenceDao->getConferenceByPath($conference);
                }, $this->conferences
            )
            : $conferenceDao->getConferences()->toArray();
        foreach ($conferences as $conference) {
            $this->log('Processing conference ' . $conference->getTitle($locale));
            foreach ($schedConfDao->getSchedConfsByConferenceId($conference->getId())->toArray() as $schedConf) {
                $this->log('Processing scheduled conference ' . $schedConf->getTitle($locale));
                $tracks = $trackDao->getSchedConfTracks($schedConf->getId())->toAssociativeArray('id');
                foreach ($publishedPaperDao->getPublishedPapersBySchedConfId($schedConf->getId())->toArray() as $paper) {
                    $this->log('Processing paper ID ' . $paper->getPaperId());
                    $trackId = $this->uniqueTrackIds[$paper->getTrackId()];
                    $track = isset($tracks[$trackId]) ? $tracks[$trackId] : $trackId = null;
                    require_once "generators/{$this->target}.php";
                    $filename = "{$this->outputPath}/papers/{$conference->getId()}-{$schedConf->getId()}-{$trackId}-{$paper->getId()}.xml";
                    try {
                        NativeXmlGenerator::renderPaper($filename, $conference, $schedConf, $track, $paper);
                    } catch(Exception $e) {
                        $this->log("Failed to generate paper with {$e}");
                        continue;
                    }
                }
            }
        }

        $this->log('Papers exported');
    }

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
            ORDER BY c.seq",
            $params
        );

        $conferences = array_values(
            array_reduce($conferences, function($conferences, $conference) {
                $conferences[$conference->conference_id] = isset($conferences[$conference->conference_id])
                    ? $conferences[$conference->conference_id]
                    : [
                        'id' => $conference->conference_id,
                        'urlPath' => $conference->path,
                        'primary_locale' => $conference->primary_locale,
                        'enabled' => $conference->enabled
                    ];
                if ($conference->setting_name === 'title') {
                    $conference->setting_value = strip_tags($conference->setting_value);
                }
                if ($conference->locale) {
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
            throw new Exception('The following conferences were not found:' . implode(', ', $notFound));
        }

        foreach ($conferences as &$conference) {
            $conference['primaryLocale'] = $conference['primary_locale'];
            if (isset($conference['title'])) {
                $conference['name'] = $conference['title'];
            }
            foreach (['supportedFormLocales', 'supportedLocales'] as $field) {
                if (isset($conference[$field])) {
                    $conference[$field] = serialize($conference[$field]);
                }
            }
            unset($conference['primary_locale'], $conference['title']);
            $conference['issues'] = $this->getScheduledConferences($conference['urlPath']);
            $conference['sections'] = $this->getTracks($conference['urlPath']);
        }

        return $conferences;
    }

    private function getScheduledConferences($conference)
    {
        $scheduledConferences = $this->readAll(
            "SELECT sc.sched_conf_id, sc.path, sc.seq, sc.start_date, sc.end_date, scs.setting_name, scs.setting_value, scs.locale
            FROM sched_confs sc
            INNER JOIN conferences c ON c.conference_id = sc.conference_id AND c.path = ?
            LEFT JOIN sched_conf_settings scs
                ON scs.sched_conf_id = sc.sched_conf_id
                AND scs.setting_name IN ('title', 'overview', 'introduction')
            ORDER BY sc.seq",
            [$conference]
        );

        $scheduledConferences = array_values(
            array_reduce($scheduledConferences, function($scheduledConferences, $scheduledConference) {
                $scheduledConferences[$scheduledConference->sched_conf_id] = isset($scheduledConferences[$scheduledConference->sched_conf_id])
                    ? $scheduledConferences[$scheduledConference->sched_conf_id]
                    : [
                        'id' => $scheduledConference->sched_conf_id,
                        'path' => $scheduledConference->path,
                        'volume' => $scheduledConference->seq,
                        'number' => 1,
                        'startDate' => $scheduledConference->start_date,
                        'endDate' =>  $scheduledConference->end_date
                    ];
                if ($scheduledConference->setting_name === 'title') {
                    $scheduledConference->setting_value = strip_tags($scheduledConference->setting_value);
                }
                if ($scheduledConference->locale) {
                    $scheduledConferences[$scheduledConference->sched_conf_id][$scheduledConference->setting_name][$scheduledConference->locale] = $scheduledConference->setting_value;
                } else {
                    $scheduledConferences[$scheduledConference->sched_conf_id][$scheduledConference->setting_name] = $scheduledConference->setting_value;
                }
                return $scheduledConferences;
            }, [])
        );

        return array_map(function ($schedConf) {
            $schedConf['description'] = $schedConf['overview'] ?: $schedConf['introduction'];
            $schedConf['year'] = date('Y', strtotime($schedConf['endDate'] ? $schedConf['endDate'] : $schedConf['startDate']));
            unset($schedConf['overview'], $schedConf['introduction']);
            return $schedConf;
        }, $scheduledConferences);
    }

    private function getTracks($conference)
    {
        $locale = $this->readAll(
            "SELECT c.primary_locale AS locale
            FROM conferences c
            WHERE c.path = ?",
            [$conference]
        );
        $locale = array_pop($locale)->locale;

        $tracks = $this->readAll(
            "SELECT t.track_id, t.seq, ts.setting_name, ts.setting_value, ts.locale
            FROM tracks t
            INNER JOIN sched_confs sc ON sc.sched_conf_id = t.sched_conf_id
            INNER JOIN conferences c ON c.conference_id = sc.conference_id AND c.path = ?
            LEFT JOIN track_settings ts
                ON ts.track_id = t.track_id
                AND ts.setting_name IN ('title', 'abbrev', 'policy')
            ORDER BY sc.seq, t.seq",
            [$conference]
        );

        $tracks = array_reduce($tracks, function($tracks, $track) {
            $tracks[$track->track_id] = isset($tracks[$track->track_id]) ? $tracks[$track->track_id] : ['id' => $track->track_id];
            if ($track->setting_name !== 'policy') {
                $track->setting_value = strip_tags($track->setting_value);
            }
            if ($track->locale) {
                $tracks[$track->track_id][$track->setting_name][$track->locale] = $track->setting_value;
            } else {
                $tracks[$track->track_id][$track->setting_name] = $track->setting_value;
            }
            return $tracks;
        }, []);

        return array_values(
            array_reduce($tracks, function($tracks, $track) use ($locale) {
                $title = isset($track['title'][$locale]) ? $track['title'][$locale] : reset($track['title']);
                $tracks[$title] = isset($tracks[$title]) ? $tracks[$title] : $track;
                $this->uniqueTrackIds[$track['id']] = $tracks[$title]['id'];
                return $tracks;
            }, [])
        );
    }

    private function readAll($query, $params = [])
    {
        $dao = new DAO();
        $rs = $dao->retrieve($query, $params);
        $data = [];
        while (!$rs->EOF) {
            $row = (object) $rs->GetRowAssoc(0);
            foreach ($row as &$value) {
                if ($value !== null) {
                    if (($newValue = iconv('UTF-8', 'UTF-8//TRANSLIT', $value)) === false) {
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
}

Exporter::run($argv);
