<?php

class Importer {
    private $processedPapers = 0;
    private $importedPapers = 0;
    private $skippedPapers = 0;
    private $failedPapers = 0;
    private $ojsPath;
    private $metadata;
    private $inputPath;
    private $forceLevel = 0;
    private $phpPath;
    private $username;
    private $adminUsername;

    public static function run()
    {
        $options = getopt('i:o:u:a:p:f:');
        if (empty($options['i']) || empty($options['o']) || empty($options['u']) || empty($options['a'])) {
            exit("Usage:\nimport.php -i INPUT_PATH -o PATH_TO_OJS_INSTALLATION -u IMPORT_USERNAME -a ADMIN_USERNAME [-p PHP_EXECUTABLE_PATH] [-f LEVEL]");
        }

        new static($options['i'], $options['o'], $options['u'], $options['a'], $options['p'] ?? 'php', $options['f'] ?? 0);
    }

    private function __construct($ojsPath, $inputPath, $username, $adminUsername, $phpPath, $forceLevel)
    {
        $exception = $defaultException = new Exception('An unexpected error has happened');
        try {
            session_start();
            session_write_close();
            ini_set('memory_limit', -1);
            set_time_limit(0);
            $this->ojsPath = realpath($ojsPath);
            $this->inputPath = $inputPath;
            $this->username = $username;
            $this->adminUsername = $adminUsername;
            $this->phpPath = preg_replace('`(?<!^) `', '^ ', escapeshellcmd($phpPath));
            $this->forceLevel = $forceLevel;
            $this->bootOjs();
            $this->loadMetadata();
            $this->checkOjsVersion();
            $this->importMetadata();
            $this->importPapers();
            $exception = null;
        } catch (Exception $exception) {
        } finally {
            if ($exception === $defaultException && error_get_last()) {
                $exception = new Exception(print_r(error_get_last(), true), 0, $exception);
            }
            if ($exception instanceof DomainException) {
                $this->log($exception->getMessage());
            } else {
                $this->log($exception ? "Import failed with {$exception}" : 'Import finished with success');
                $this->log("Processed papers: {$this->processedPapers}");
                $this->log("Imported papers: {$this->importedPapers}");
                $this->log("Skipped papers: {$this->skippedPapers}");
                $this->log("Failed papers: {$this->failedPapers}");
            }
        }
        echo chr(7);
    }

    private function bootOjs()
    {
        if (!is_file($this->ojsPath . "/config.inc.php")) {
            throw new DomainException("The path \"{$this->ojsPath}\" doesn't seem to be a valid OJS installation, the config.inc.php file wasn't found.");
        }

        $this->log('Booting OJS');
        require_once "{$this->ojsPath}/tools/bootstrap.inc.php";
        new CommandLineTool();
        $this->log('Booting complete');
    }

    private function loadMetadata()
    {
        $this->metadata = json_decode(file_get_contents("{$this->inputPath}/metadata.json"), null, 512, JSON_THROW_ON_ERROR);
    }

    private static function log($message)
    {
        echo "{$message}\n";
    }

    private function checkOjsVersion() {
        $this->log('Checking OJS version');
        $version = $this->readAll(
            "SELECT v.major, v.minor, v.revision
            FROM versions v
            WHERE
                v.current = 1
                AND v.product_type = 'core'
                AND v.product = 'ojs2'"
        );
        $requiredVersion = $this->metadata->ojs;
        $version = implode('.', (array) reset($version));
        if ($version !== $requiredVersion) {
            if ($this->forceLevel > 0) {
                $this->log('OJS version check failed, but the issue was ignored');
                return;
            }
            throw new DomainException("This script is compatible only with OJS {$requiredVersion}, your OJS version {$version} must be downgraded/upgraded. You can re-run with the argument \"-f 1\" to ignore");
        }
        $this->log('OJS version checked');
    }

    private function importMetadata()
    {
        $this->log("Importing metadata into OJS");

        $this->log("Validating installed locales");
        $uniqueLocales = [];
        foreach ($this->metadata->journals as $journal) {
            foreach (['supportedLocales', 'supportedFormLocales'] as $locales) {
                if (isset($journal->locales)) {
                    $uniqueLocales += array_flip($journal->locales);
                }
            }
        }
        $uniqueLocales = array_keys($uniqueLocales);

        $rawLocales = $this->readAll('SELECT installed_locales AS locales FROM site')[0]->locales;
        try {
            $installedLocales = json_decode($rawLocales, null, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            if (!($installedLocales = @unserialize($rawLocales))) {
                $installedLocales = explode(':', $rawLocales);
            }
        }
        $installedLocales = (array) $installedLocales;

        if (count($missingLocales = array_diff($uniqueLocales, $installedLocales)) && $this->forceLevel < 2) {
            throw new DomainException('The conferences which are going to be imported require the following locales to be installed: ' . implode(', ', $missingLocales));
        }
        $this->log("Required locales are installed");

        $this->log("Matching journals");
        $missingJournals = [];
        foreach ($this->metadata->journals as $journal) {
            if ($row = $this->readAll('SELECT j.journal_id FROM journals j WHERE j.path = ?', [$journal->urlPath])[0] ?? null) {
                $journal->localId = $row->journal_id;
            } elseif ($this->forceLevel < 3) {
                $missingJournals[] = $journal->urlPath;
            } else {
                $this->log("Creating journal {$journal->urlPath}");
                //OJS 3.2
                AppLocale::requireComponents(LOCALE_COMPONENT_APP_DEFAULT);
                $application = Application::get();
                $request = $application->getRequest();

                import('classes.core.PageRouter');
                $router = new PageRouter();
                $router->setApplication($application);
                $request->setRouter($router);

                $request->setDispatcher($application->getDispatcher());

                // Initialize the locale and load generic plugins.
                AppLocale::initialize($request);
                PluginRegistry::loadCategory('generic');

                $user =& Registry::get('user', true);
                if (!$user) {
                    $userDao = DAORegistry::getDAO('UserDAO');
                    $user = $userDao->getByUsername($this->adminUsername);
                    if (!$user) {
                        throw new DomainException("Admin user with the username \"{$this->adminUsername}\" not found");
                    }
                }

                import('classes.core.Services');
                $contextService = Services::get('context');
                $context = Application::getContextDAO()->newDataObject();
                foreach ([
                    'urlPath',
                    'name',
                    'primaryLocale',
                    'enabled',
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
                ] as $name) {
                    if (is_object($value = $journal->{$name} ?? null) || strlen((string) $value)) {
                        if (is_object($value)) {
                            foreach ($value as $locale => $value) {
                                $context->setData($name, $value, $locale);
                                if ($name === 'name') {
                                    preg_match_all('/\p{L}(?=\p{L})/u', $value, $initials);
                                    $context->setData('acronym', mb_strtoupper(implode('', $initials[0]) ?: preg_replace('/\P{L}/u', '', $value)), $locale);
                                }
                            }
                        } else {
                            $context->setData($name, $value);
                        }
                    }
                }
                $journal->localId = $contextService->add($context, $request)->getId();
                $this->log("Journal ID {$journal->localId} created");
            }
        }

        if (count($missingJournals)) {
            throw new DomainException(
                "The following journals paths were not found:\n" . implode("\n", $missingJournals) . "\n\nYou have these options:"
                . "\n- Map the non-existent values to existing journal paths at the file \"{$this->inputPath}/metadata.json\", by updating the conference.path to an existing journal path."
                . "\n- Let this tool to create the missing journals by re-running with the argument \"-f 2\", you might review/modify the data which will be used to create the journal at the metadata.json file."
                . "\n- Remove the journal and its subdata from the metadata.json, this will cause its related papers to be skipped."
            );
        } else {
            $this->log("Journals matched successfully");
        }

        $this->log("Matching issues");
        $missingIssues = [];
        foreach ($this->metadata->journals as $journal) {
            foreach ($journal->issues as $issue) {
                if ($row = $this->readAll(
                    'SELECT i.issue_id
                    FROM issues i
                    WHERE i.journal_id = ?
                    AND i.volume = ?
                    AND i.number = ?
                    AND i.year = ?',
                    [$journal->localId, $issue->volume, $issue->number, $issue->year]
                )[0] ?? null) {
                    $issue->localId = $row->issue_id;
                } elseif ($this->forceLevel < 4) {
                    $missingIssues[$journal->urlPath][] = "Issue volume {$issue->volume}, number {$issue->number}, year {$issue->year}";
                } else {
                    $this->log("Creating issue volume {$issue->volume} number {$issue->number} year {$issue->year} on journal {$journal->urlPath}");
                    $data = [
                        'journal_id' => $journal->localId,
                        'volume' => $issue->volume,
                        'number' => $issue->number,
                        'year' => $issue->year,
                        'published' => 1,
                        'current' => 0,
                        'date_published' => $issue->startDate ?? $issue->endDate ?? date('Y-m-d'),
                        'last_modified' => $issue->startDate ?? $issue->endDate ?? date('Y-m-d'),
                        'access_status' => 1,
                        'show_volume' => (bool) strlen($issue->volume),
                        'show_number' => (bool) strlen($issue->number),
                        'show_year' => (bool) strlen($issue->year),
                        'show_title' => 1
                    ];
                    $this->execute(
                        'INSERT INTO issues (' . implode(', ', array_keys($data)) . ')
                        VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                        array_values($data)
                    );
                    $issueId = $this->getInsertId('issues', 'issue_id');
                    $issue->localId = $issueId;
                    foreach (['title' => $issue->title, 'description' => $issue->description] as $name => $values) {
                        foreach ($values as $locale => $value) {
                            $data = [
                                'issue_id' => $issueId,
                                'locale' => $locale,
                                'setting_name' => $name,
                                'setting_value' => $value,
                                'setting_type' => 'string'
                            ];
                            $this->execute(
                                'INSERT INTO issue_settings (' . implode(', ', array_keys($data)) . ')
                                VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                                array_values($data)
                            );
                        }
                    }
                    $this->log("Issue ID {$issueId} created");
                }
            }
        }
        if (count($missingIssues)) {
            $message = "The following issues were not found:\n";
            foreach ($missingIssues as $journal => $issues) {
                $message .= "\nJournal \"{$journal}\"\n" . implode("\n", $issues);
            }
            $message .= "\n\nYou have these options:"
                . "\n- Map the non-existent values to existing issues at the file \"{$this->inputPath}/metadata.json\", by updating the issue.volume, issue.number and issue.year to the values of an existing issue of the given journal."
                . "\n- Let this tool create the missing issues by re-running with the argument \"-f 3\", you might review/modify the data which will be used to create the issues at the metadata.json file."
                . "\n- Remove the issue from the metadata.json, this will cause its related papers to be skipped.";
            throw new DomainException($message);
        } else {
            $this->log("Issues matched successfully");
        }

        $this->log("Matching sections");
        $missingSections = [];
        foreach ($this->metadata->journals as $journal) {
            foreach ($journal->sections as $section) {
                $params = [$journal->localId];
                $mainLocale = null;
                foreach ($section->title as $locale => $value) {
                    $mainLocale = $mainLocale ?: $locale;
                    $params[] = $locale;
                    $params[] = $value;
                }
                if ($row = $this->readAll(
                    "SELECT
                        s.section_id, (
                            SELECT ss.setting_value
                            FROM section_settings ss
                            WHERE
                                ss.section_id = s.section_id
                                AND ss.setting_name = 'abbrev'
                            ORDER BY
                                ss.locale <> 'en_US', setting_value DESC
                            LIMIT 1
                        ) AS abbrev
                    FROM sections s
                    INNER JOIN section_settings ss
                        ON ss.section_id = s.section_id
                    WHERE
                        s.journal_id = ?
                        AND ss.setting_name = 'title'
                        AND (" . implode(' OR ', array_fill(0, count(get_object_vars($section->title)), '(ss.locale = ? AND ss.setting_value = ?)')) . ")",
                    $params
                )[0] ?? null) {
                    $section->localId = $row->section_id;
                    $section->localAbbrev = $row->abbrev;
                } elseif ($this->forceLevel < 5) {
                    $missingSections[$journal->urlPath][] = 'Section with title "' . $section->title->{$mainLocale} . '", abbrev "' . $section->abbrev->{$mainLocale} . '"';
                } else {
                    $this->log("Creating section " . $section->abbrev->{$mainLocale} . " on journal {$journal->urlPath}");
                    $this->execute(
                        'INSERT INTO sections (journal_id, seq)
                        SELECT ?, (SELECT COALESCE(MAX(seq), 0) + 1 FROM sections WHERE journal_id = ?)',
                        [$journal->localId, $journal->localId]
                    );
                    $sectionId = $this->getInsertId('sections', 'section_id');
                    $section->localId = $sectionId;
                    $section->localAbbrev = $section->abbrev->{$mainLocale};
                    foreach (['title' => $section->title, 'abbrev' => $section->abbrev, 'policy' => $section->policy] as $name => $values) {
                        foreach ($values as $locale => $value) {
                            $data = [
                                'section_id' => $sectionId,
                                'locale' => $locale,
                                'setting_name' => $name,
                                'setting_value' => $value,
                                'setting_type' => 'string'
                            ];
                            $this->execute(
                                'INSERT INTO section_settings (' . implode(', ', array_keys($data)) . ')
                                VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                                array_values($data)
                            );
                        }
                    }
                    $this->log("Section ID {$sectionId} created");
                }
            }
        }
        if (count($missingSections)) {
            $message = "The following sections were not found:\n";
            foreach ($missingSections as $journal => $sections) {
                $message .= "\nJournal {$journal}\n" . implode("\n", $sections);
            }
            $message .= "\n\nYou have these options:"
                . "\n- Map the non-existent values to existing sections at the file \"{$this->inputPath}/metadata.json\", by updating the section.title to an existing section of the given journal."
                . "\n- Let this tool create the missing sections by re-running with the argument \"-f 4\", you might review/modify the data which will be used to create the sections at the metadata.json file."
                . "\n- Remove the section from the metadata.json, this will cause its related papers to be skipped.";
            throw new DomainException($message);
        } else {
            $this->log("Sections matched successfully");
        }

        $this->log('Metadata Imported');
    }

    private function getRoleMap($journalId)
    {
        $cache = [];

        if (isset($cache[$journalId])) {
            return $cache[$journalId];
        }

        $map = [
            'AUTHOR' => null
        ];

        $roles = $this->readAll(
            "SELECT 'AUTHOR' AS identifier, ugs.setting_value AS name
            FROM user_groups ug
            INNER JOIN user_group_settings ugs
                ON ug.user_group_id = ugs.user_group_id
                AND ugs.setting_name = 'name'
            WHERE
                ug.context_id = ?
                AND ug.role_id = 65536
            ORDER BY
                ugs.locale <> 'en_US', locale DESC
            LIMIT 1",
            [$journalId]
        );
        $firstRole = null;
        foreach ($roles as $role) {
            $firstRole = $role->name ?? $firstRole;
            if (!isset($map[$role->identifier])) {
                $map[$role->identifier] = $role->name;
            }
        }
        $finalMap = [];
        foreach ($map as $identifier => $name) {
            $finalMap["ROLE_NAME_{$identifier}"] = $name ?? $firstRole;
        }
        return $cache[$journalId] = $finalMap;
    }

    private function getGenreMap($journalId)
    {
        $cache = [];

        if (isset($cache[$journalId])) {
            return $cache[$journalId];
        }

        $map = [
            'RESEARCHINSTRUMENT' => null,
            'RESEARCHMATERIALS' => null,
            'RESEARCHRESULTS' => null,
            'TRANSCRIPTS' => null,
            'DATAANALYSIS' => null,
            'DATASET' => null,
            'SOURCETEXTS' => null,
            'OTHER' => null,
            'SUBMISSION' => null,
            'STYLE' => null,
            'IMAGE' => null
        ];

        $genres = $this->readAll(
            "SELECT g.entry_key AS identifier, gs.setting_value AS name
            FROM genres g
            INNER JOIN genre_settings gs
                ON gs.genre_id = g.genre_id
                AND gs.setting_name = 'name'
            WHERE
                g.context_id = ?
                AND g.enabled = 1
            ORDER BY
                g.seq, gs.locale <> 'en_US'",
            [$journalId]
        );
        $firstGenre = null;
        foreach ($genres as $genre) {
            $firstGenre = $genre->name ?? $firstGenre;
            if (!isset($map[$genre->identifier])) {
                $map[$genre->identifier] = $genre->name;
            }
        }
        $finalMap = [];
        foreach ($map as $identifier => $name) {
            $finalMap["GENRE_NAME_{$identifier}"] = $name ?? $firstGenre;
        }
        return $cache[$journalId] = $finalMap;
    }

    private function importPapers()
    {
        $this->log('Importing papers into OJS');

        $processedFolder = "{$this->inputPath}/processed-papers";
        $this->log("Creating/checking the processed folder at {$processedFolder}");
        if (!is_dir($processedFolder)) {
            mkdir($processedFolder);
        }

        $processingFolder = "{$this->inputPath}/processing-papers";
        $this->log("Creating/checking the processing folder at {$processingFolder}");
        if (!is_dir($processingFolder)) {
            mkdir($processingFolder);
        }

        $skippedFolder = "{$this->inputPath}/skipped-papers";
        $this->log("Creating/checking the skipped folder at {$skippedFolder}");
        if (!is_dir($skippedFolder)) {
            mkdir($skippedFolder);
        }

        $conferenceMap = $schedConfMap = $trackMap = [];
        foreach ($this->metadata->journals as $journal) {
            $conferenceMap[$journal->id] = $journal;
            foreach ($journal->issues as $issue) {
                $schedConfMap[$issue->id] = $issue;
            }
            foreach ($journal->sections as $section) {
                $trackMap[$section->id] = $section;
            }
        }

        $hasBadFilter = $this->execute("UPDATE filter_groups SET output_type = 'class::classes.publication.Publication[]' WHERE output_type = 'class::classes.publication.Publication'");
        try {
            foreach (new FilesystemIterator("{$this->inputPath}/papers") as $paper) {
                [$conferenceId, $schedConfId, $trackId, $paperId] = explode('-', $paper->getBasename('.xml'));
                $journal = $conferenceMap[$conferenceId] ?? null;
                $issue = $schedConfMap[$schedConfId] ?? null;
                $section = $trackMap[$trackId] ?? null;

                foreach (['journal' => $journal, 'issue' => $issue, 'section' => $trackId ? $section : true] as $name => $value) {
                    if (!$value || !isset($value->localId)) {
                        $this->log(
                            "Paper {$paper->getFilename()} skipped due to missing {$name} mapping. "
                            . (rename($paper, "{$processedFolder}/{$paper->getFilename()}") ? "Moved" : "Failed to move") . ' paper XML to the skipped folder'
                        );
                        ++$this->skippedPapers;
                        continue 2;
                    }
                }

                $this->log("Processing paper {$paper->getFilename()}");
                ++$this->processedPapers;

                $replaces = $this->getGenreMap($journal->localId) + $this->getRoleMap($journal->localId) + [
                    'SECTION_ABBREVIATION' => $section ? $section->localAbbrev : null,
                    'ISSUE_VOLUME' => $issue->volume,
                    'ISSUE_NUMBER' => $issue->number,
                    'ISSUE_YEAR' => $issue->year
                ];

                $path = "{$processingFolder}/{$paper->getFilename()}";
                $command = null;
                try {
                    if (!file_put_contents(
                        $path,
                        preg_replace_callback('/\\{\\[#(\w+)#\\]\\}/', function ($match) use ($replaces) {
                            return $replaces[$match[1]];
                        }, file_get_contents($paper))
                    )) {
                        throw new DomainException('Failed to regenerate XML for import');
                    }
                    $command = "{$this->phpPath} -d memory_limit=-1 " . escapeshellarg("{$this->ojsPath}/tools/importExport.php") . ' NativeImportExportPlugin import ' . escapeshellarg($path) . " {$journal->urlPath} {$this->username}";
                    $lastCount = $this->readAll('SELECT MAX(publication_id) AS count FROM publications')[0]->count;
                    $output = shell_exec($command);
                    $this->log("Output from OJS:\n{$output}");
                    if ($output === null || strpos($output, 'Fatal error') !== false || $lastCount + 1 !== (int) $this->readAll('SELECT MAX(publication_id) AS count FROM publications')[0]->count) {
                        throw new DomainException('Failure while running the import command from the Native Import Export Plugin, you may retry with the setting [debug].display_errors defined as "On" on the config.inc.php');
                    }
                    $this->log("Paper imported successfully. " (rename($paper, "{$processedFolder}/{$paper->getFilename()}") ? "Moved" : "Failed to move") . ' paper XML to the processed folder');
                    ++$this->importedPapers;
                    unlink($path);
                    $this->log('');
                } catch (DomainException $e) {
                    $this->log("Failed to process paper {$paper->getFilename()}: " . $e->getMessage());
                    if ($command) {
                        file_put_contents("{$processingFolder}/{$paper->getBasename('.xml')}.txt", $command);
                        $this->log("A copy of the XML file, together with its related command (.txt extension), was left at {$path} for debugging purposes");
                    }
                    ++$this->failedPapers;
                }
            }
        } finally {
            if ($hasBadFilter) {
                $this->execute("UPDATE filter_groups SET output_type = 'class::classes.publication.Publication' WHERE output_type = 'class::classes.publication.Publication[]'");
            }
        }

        $this->log('Papers imported');
    }

    private function execute($query, $params = [])
    {
        $dao = new DAO();
        return $dao->update($query, $params);
    }

    private function getInsertId($table, $field)
    {
        $dao = new DAO();
        return $dao->getDataSource()->po_insert_id($table, $field);
    }

    private function readAll($query, $params = [])
    {
        $dao = new DAO();
        $rs = $dao->retrieve($query, $params);
        $data = [];
        while (!$rs->EOF) {
            $row = (object) $rs->GetRowAssoc(0);
            foreach ($row as &$value) {
                $value = iconv('UTF-8', 'UTF-8//TRANSLIT', $value);
            }
            $data[] = $row;
            $rs->MoveNext();
        }
        $rs->Close();
        return $data;
    }
}

Importer::run();
