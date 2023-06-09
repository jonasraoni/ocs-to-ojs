<?php

/**
 * @file import.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Importer
 *
 * @brief Imports OCS data into using as template data generated by the export script
 */

class Importer
{
    /** Count of processed papers */
    private $processedPapers = 0;
    /** Count of imported papers */
    private $importedPapers = 0;
    /** Count of skipped papers */
    private $skippedPapers = 0;
    /** Count of failed papers */
    private $failedPapers = 0;
    /** Path for the OJS installation */
    private $ojsPath;
    /** Parsed contents of the metadata.json file */
    private $metadata;
    /** Path where the data from the export script was generated */
    private $inputPath;
    /** Force level, see README.md */
    private $forceLevel = 0;
    /** Path for the PHP executable */
    private $phpPath;
    /** User which will be assigned to each paper */
    private $username;
    /** Admin user which will be used while creating the journals */
    private $adminUsername;
    /** If specified, the shell commands to import the papers will not be executed, but stored in this file */
    private $shellCommandFile;

    /**
     * Feeds the script with command line arguments
     */
    public static function run()
    {
        $options = getopt('i:o:u:a:p:f:e:');
        if (empty($options['i']) || empty($options['o']) || empty($options['u']) || empty($options['a'])) {
            exit("Usage:\nimport.php -i INPUT_PATH -o PATH_TO_OJS_INSTALLATION -u IMPORT_USERNAME -a ADMIN_USERNAME [-p PHP_EXECUTABLE_PATH] [-f LEVEL] [-e]\n\a");
        }

        new static($options['o'], $options['i'], $options['u'], $options['a'], $options['p'] ?? 'php', $options['f'] ?? 0, $options['e'] ?? null);
    }

    /**
     * Initializes the process
     */
    private function __construct($ojsPath, $inputPath, $username, $adminUsername, $phpPath, $forceLevel, $shellCommandFile)
    {
        $exception = $defaultException = new Exception('An unexpected error has happened');
        try {
            ini_set('memory_limit', -1);
            set_time_limit(0);
            $this->ojsPath = realpath($ojsPath);
            $this->inputPath = realpath($inputPath);
            $this->username = $username;
            $this->adminUsername = $adminUsername;
            $this->phpPath = preg_replace('`(?<!^) `', '^ ', escapeshellcmd($phpPath));
            $this->forceLevel = $forceLevel;
            $this->shellCommandFile = $shellCommandFile ? realpath(dirname($shellCommandFile)) . '/' . basename($shellCommandFile) : null;
            $this->bootOjs();
            $this->loadMetadata();
            $this->checkOjsVersion();
            if (count($polyfilled = $this->createPolyfills()) && !$this->shellCommandFile) {
                throw new DomainException(
                    "The following functions were not found in you PHP installation (they were probably disabled due to security reasons):\n"
                    . implode(', ', $polyfilled) . "\n"
                    . "You have two options:\n"
                    . "- Enable them, by updating the php.ini manually or asking your host provider/system administrator\n"
                    . "- Re-run the tool with the flag \"-e import-commands.sh\", which will just create the metadata. The ready to import papers will be placed in the folder \"processing-papers\" and the tool will just write out to the specified file shell commands to import them"
                );
            }
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
                if (!$exception) {
                    $this->log(
                        "Do not forget to:\n"
                        . "- Disable the [debug].display_errors setting on OJS\n"
                        . "- Review the current issue for each imported journal\n"
                        . "- In case you're importing into an existing journal, it's important to review the issues ordering\n"
                    );
                }
            }
        }
        echo chr(7);
    }

    /**
     * Initializes the OJS installation (we're going to use its internals to retrieve data)
     */
    private function bootOjs()
    {
        if (!is_file($this->ojsPath . "/config.inc.php")) {
            throw new DomainException("The path \"{$this->ojsPath}\" doesn't seem to be a valid OJS installation, the config.inc.php file wasn't found.");
        }

        foreach (['.inc', ''] as $prefix) {
            if (file_exists($path = "{$this->ojsPath}/tools/bootstrap{$prefix}.php")) {
                require_once $path;
            }
        }
        new CommandLineTool();
        $this->log('Booting complete');
    }

    /**
     * Parses the metadata.json and keeps it on memory
     */
    private function loadMetadata()
    {
        $this->metadata = json_decode(file_get_contents("{$this->inputPath}/metadata.json"), null, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Logs messages
     */
    private static function log($message)
    {
        echo "{$message}\n";
    }

    /**
     * Checks if the OCS version is supported by the script by comparing it to the reference value at the metadata.json file
     */
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

    /**
     * Checks whether OJS has:
     * - The required locales installed
     * - The required journals
     * - The required issues (per journal)
     * - The required sections (per journal)
     * 
     * The function might create the missing data using information from the metadata.json depending on the "force level" setting
     */
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
            throw new DomainException(
                'The conferences which are going to be imported require the following locales to be installed: ' . implode(', ', $missingLocales)
                . "\nYou might ignore this warning by re-running with the argument \"-f 2\""
            );
        }
        $this->log("Required locales are installed");

        $hasSettingType = ['section_settings' => true, 'issue_settings' => true];
        foreach ($hasSettingType as $table => &$hasField) {
            try {
                $this->readAll("SELECT setting_type FROM {$table} WHERE 1 = 0");
            } catch (Exception $e) {
                $hasField = false;
            }
        }

        $this->log("Matching journals");
        $missingJournals = [];
        foreach ($this->metadata->journals as $journal) {
            if ($row = $this->readAll('SELECT j.journal_id FROM journals j WHERE j.path = ?', [$journal->urlPath])[0] ?? null) {
                $journal->localId = $row->journal_id;
            } elseif ($this->forceLevel < 3) {
                $missingJournals[] = $journal->urlPath;
            } else {
                $this->log("Creating journal {$journal->urlPath}");

                $application = Application::get();
                $request = $application->getRequest();

                import('classes.core.PageRouter');
                $router = new PageRouter();
                $router->setApplication($application);
                $request->setRouter($router);

                $request->setDispatcher($application->getDispatcher());

                // Initialize the locale
                import('classes.i18n.AppLocale');
                if (method_exists('AppLocale', 'initialize')) {
                    AppLocale::initialize($request);
                }
                // Load generic plugins
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
                    'supportedSubmissionLocales'
                ] as $name) {
                    if (is_object($value = $journal->{$name} ?? null) || is_array($value) || strlen((string) $value)) {
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
                foreach ($installedLocales as $locale) {
                    AppLocale::requireComponents(LOCALE_COMPONENT_APP_DEFAULT, $locale);
                }
                $journal->localId = $contextService->add($context, $request)->getId();
                $this->log("Journal ID {$journal->localId} created");
            }
        }

        if (count($missingJournals)) {
            throw new DomainException(
                "The following journals paths were not found:\n" . implode("\n", $missingJournals) . "\n\nYou have these options:"
                . "\n- Map the non-existent values to existing journal paths at the file \"{$this->inputPath}/metadata.json\", by updating the conference.path to an existing journal path."
                . "\n- Let this tool create the missing journals by re-running with the argument \"-f 3\", you might review/modify the data which will be used to create the journal at the metadata.json file."
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
                    foreach (['title' => $issue->title ?? [], 'description' => $issue->description ?? []] as $name => $values) {
                        foreach ($values as $locale => $value) {
                            $data = [
                                'issue_id' => $issueId,
                                'locale' => $locale,
                                'setting_name' => $name,
                                'setting_value' => $value
                            ];
                            if ($hasSettingType['issue_settings']) {
                                $data += ['setting_type' => 'string'];
                            }
                            $this->execute(
                                'INSERT INTO issue_settings (' . implode(', ', array_keys($data)) . ')
                                VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
                                array_values($data)
                            );
                        }
                    }
                    $this->execute('UPDATE custom_issue_orders SET seq = seq + 1 WHERE journal_id = ?', [$journal->localId]);
                    $this->execute(
                        'INSERT INTO custom_issue_orders (issue_id, journal_id, seq) VALUES (?, ?, 1)',
                        [$issueId, $journal->localId]
                    );
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
                . "\n- Let this tool create the missing issues by re-running with the argument \"-f 4\", you might review/modify the data which will be used to create the issues at the metadata.json file."
                . "\n- Remove the issue from the metadata.json, this will cause its related papers to be skipped."
                . "\n\nIf you opt to create the issues, please review the issue ordering after the import. We'll insert the new issues on the top";
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
                // The matching is done by the section title
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
                    foreach (['title' => $section->title, 'abbrev' => $section->abbrev, 'policy' => $section->policy ?? []] as $name => $values) {
                        foreach ($values as $locale => $value) {
                            $data = [
                                'section_id' => $sectionId,
                                'locale' => $locale,
                                'setting_name' => $name,
                                'setting_value' => $value
                            ];
                            if ($hasSettingType['section_settings']) {
                                $data += ['setting_type' => 'string'];
                            }
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
                . "\n- Let this tool create the missing sections by re-running with the argument \"-f 5\", you might review/modify the data which will be used to create the sections at the metadata.json file."
                . "\n- Remove the section from the metadata.json, this will cause its related papers to be skipped.";
            throw new DomainException($message);
        } else {
            $this->log("Sections matched successfully");
        }

        $this->log('Metadata Imported');
    }

    /**
     * Retrieves a map consisting of the role identifier as key (in the form "ROLE_NAME_" + identifier) and its ID as value (per journal)
     * Only the AUTHOR is needed, it's ROLE_ID (65536) is hardcoded
     */
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

    /**
     * Retrieves a map consisting of the genre identifier as key (in the form "GENRE_NAME_" + identifier) and its ID as value (per journal)
     * In case a perfect match cannot be found, the first enabled genre will be used
     */
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

    /**
     * Import the papers into OJS
     */
    private function importPapers()
    {
        $this->log('Checking OJS configuration');
        if (!Config::getVar('debug', 'display_errors') && $this->forceLevel < 6) {
            throw new DomainException('In order to get better error messages, please setup the setting [debug].display_errors at the config.inc.php file of OJS with "On"');
        }
        $this->log('OJS configuration checked');


        $this->log('Importing papers into OJS');

        // Successfully processed papers will be placed here
        $processedFolder = "{$this->inputPath}/processed-papers";
        $this->log("Creating/checking the processed folder at {$processedFolder}");
        if (!is_dir($processedFolder)) {
            mkdir($processedFolder);
        }

        // Papers which are currently being processed (and the ones that failed) will be placed here
        // Note: at this point, the variable replacement for the template is done
        $processingFolder = "{$this->inputPath}/processing-papers";
        $this->log("Creating/checking the processing folder at {$processingFolder}");
        if (!is_dir($processingFolder)) {
            mkdir($processingFolder);
        }

        // Skipped papers will be placed here
        $skippedFolder = "{$this->inputPath}/skipped-papers";
        $this->log("Creating/checking the skipped folder at {$skippedFolder}");
        if (!is_dir($skippedFolder)) {
            mkdir($skippedFolder);
        }

        // Builds an "ID => object" map for the conferences, scheduled conferences and tracks
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

        // Check whether the OJS has a defective filter (the update is safe, but will be reverted later)
        $hasBadFilter = $this->execute("UPDATE filter_groups SET output_type = 'class::classes.publication.Publication[]' WHERE output_type = 'class::classes.publication.Publication'");
        try {
            foreach (new FilesystemIterator("{$this->inputPath}/papers") as $paper) {
                // The paper filename is supposed to have these components. The section ID is optional
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

                // The paper XML is a kind of template, that still needs some updates, here we builds an array with the required variable replacements
                $replaces = $this->getGenreMap($journal->localId) + $this->getRoleMap($journal->localId) + [
                    'SECTION_ABBREVIATION' => $section ? $section->localAbbrev : null,
                    'ISSUE_VOLUME' => $issue->volume,
                    'ISSUE_NUMBER' => $issue->number,
                    'ISSUE_YEAR' => $issue->year
                ];

                $path = "{$processingFolder}/{$paper->getFilename()}";
                $command = null;
                try {
                    // Replaces the variables, and saves the file
                    if (!file_put_contents(
                        $path,
                        preg_replace_callback('/\\{\\[#(\w+)#\\]\\}/', function ($match) use ($replaces) {
                            return htmlspecialchars($replaces[$match[1]], ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
                        }, file_get_contents($paper))
                    )) {
                        throw new DomainException('Failed to regenerate XML for import');
                    }
                    // Builds the command to import the paper through the NativeImportExportPlugin
                    $command = "{$this->phpPath} -d memory_limit=-1 " . escapeshellarg("{$this->ojsPath}/tools/importExport.php") . ' NativeImportExportPlugin import ' . escapeshellarg(preg_replace(['/[a-z]:/i', '/\\\\/'], ['', '/'], $path)) . " {$journal->urlPath} {$this->username}";

                    // Writes the command and skips to the next paper
                    if ($this->shellCommandFile) {
                        if (!file_put_contents($this->shellCommandFile, "{$command}\n", FILE_APPEND)) {
                            throw new DomainException("Failed to write the shell command to \"{$this->shellCommandFile}\"");
                        }
                        $this->log("The command to generate the paper was generated successfully. The ready to process paper was left at \"{$path}\". " . (rename($paper, "{$processedFolder}/{$paper->getFilename()}") ? "Moved" : "Failed to move") . ' source paper XML to the processed folder.');
                        ++$this->importedPapers;
                        continue;
                    }

                    // Retrieve the last created publication ID, will be used to check if a new one was created (which indicates that the paper was at least partially imported)
                    $lastCount = $this->readAll('SELECT MAX(publication_id) AS count FROM publications')[0]->count;
                    if ($output = shell_exec($command)) {
                        $this->log("Output from OJS:\n{$output}");
                    }

                    import('classes.i18n.AppLocale');
                    AppLocale::requireComponents(LOCALE_COMPONENT_PKP_MANAGER);
                    $errorMessages = ['Fatal error', __('plugins.importexport.common.validationErrors'), __('plugins.importexport.common.errorsOccured') ?: __('plugins.importexport.common.errorsOccurred')];
                    $hasErrorMessage = false;
                    foreach ($errorMessages as $errorMessage) {
                        if ($hasErrorMessage = strpos((string) $output, $errorMessage) !== false) {
                            break;
                        }
                    }

                    if ($hasErrorMessage || $lastCount === (int) $this->readAll('SELECT MAX(publication_id) AS count FROM publications')[0]->count) {
                        throw new DomainException('Failure while running the import command from the Native Import Export Plugin');
                    }
                    $this->log("Paper imported successfully. " . (rename($paper, "{$processedFolder}/{$paper->getFilename()}") ? "Moved" : "Failed to move") . ' paper XML to the processed folder');
                    ++$this->importedPapers;
                    unlink($path);
                } catch (DomainException $e) {
                    $this->log("Failed to process paper {$paper->getFilename()}: " . $e->getMessage());
                    // If the "command" part was reached, then store a copy of the XML to debug what happened
                    if ($command) {
                        file_put_contents("{$processingFolder}/{$paper->getBasename('.xml')}.txt", $command);
                        $this->log("A copy of the XML file, together with its related command (.txt extension), was left at {$path} for debugging purposes");
                    }
                    ++$this->failedPapers;
                }
            }
        } finally {
            // Revert the filter fix
            if ($hasBadFilter) {
                $this->execute("UPDATE filter_groups SET output_type = 'class::classes.publication.Publication' WHERE output_type = 'class::classes.publication.Publication[]'");
            }
        }

        $this->log('Papers imported');
    }

    /**
     * Executes a query
     */
    private function execute($query, $params = [])
    {
        $dao = new DAO();
        return $dao->update($query, $params);
    }

    /**
     * Retrieves the last inserted ID
     */
    private function getInsertId($table, $field)
    {
        $dao = new class() extends DAO {
            public function getLastInsertId($table, $field)
            {
                // OJS +3.3
                return method_exists('DAO', 'getInsertId')
                    ? $this->getInsertId()
                    : $this->_getInsertId($table, $field);
            }
        };
        return $dao->getLastInsertId($table, $field);
    }

    /**
     * Runs the query and retrieves a clean array with each row while ensuring data is composed of valid UTF-8 characters
     */
    private function readAll($query, $params = [])
    {
        $updateRow = function ($row) {
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
        };

        $dao = new DAO();
        $rs = $dao->retrieve($query, $params);
        $data = [];

        // OJS +3.3
        if ($rs instanceof Generator) {
            foreach ($rs as $row) {
                $updateRow($row);
                $data[] = $row;
            }
        } else {
            while (!$rs->EOF) {
                $row = (object) $rs->GetRowAssoc(0);
                $updateRow($row);
                $data[] = $row;
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $data;
    }

    /**
     * Polyfills possibly disabled functions
     * @return [] The polyfilled functions
     */
    private function createPolyfills()
    {
        $polyfilled = [];
        if (!function_exists('escapeshellcmd')) {
            function escapeshellcmd($command) {
                return $command;
            }
            $polyfilled[] = 'escapeshellcmd';
        }

        if (!function_exists('escapeshellarg')) {
            function escapeshellarg($command) {
                return $command;
            }
            $polyfilled[] = 'escapeshellarg';
        }

        if (!function_exists('shell_exec')) {
            function shell_exec($command) {
                return null;
            }
            $polyfilled[] = 'shell_exec';
        }
        return $polyfilled;
    }
}

Importer::run();
