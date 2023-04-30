<?php

import('xml.XMLCustomWriter');

class NativeXmlGenerator {
    /**
     * @param Conference $conference
     * @param SchedConf $schedConf
     * @param Track $track
     * @param PublishedPaper $paper
     */
    static function renderPaper($filename, $conference, $schedConf, $track, $paper) {
        $document = new DOMDocument('1.0', 'utf-8');
        
        $language = $paper->getLanguage();
        $locale = strlen($language) === 5
            ? $language
            : self::getLocaleFromIso3(strlen($language) === 2 ? self::getIso3FromIso1($language) : $language);
        if (!$locale) {
            $locale = AppLocale::getPrimaryLocale();
        }
        // Article
        $articleNode = $document->appendChild($document->createElementNS('http://pkp.sfu.ca', 'article'));
        self::setAttributes($articleNode, [
            'xsi:schemaLocation' => 'http://pkp.sfu.ca native.xsd',
            'date_submitted' => self::formatDate($paper->getDateSubmitted()),
            'status' => 3,
            'submission_progress' => 0,
            'current_publication_id' => $paper->getId(),
            'stage' => 'production'
        ]);
        $articleNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        /** @var PaperFileDAO */
        $paperFileDao = DAORegistry::getDAO('PaperFileDAO');

        // Submission Files
        /** @var PaperFile */
        foreach ($paperFileDao->getPaperFilesByPaper($paper->getId()) as $paperFile) {
            if ($submissionFileNode = self::generateSubmissionFile($document, $paper, $paperFile, $locale)) {
                $articleNode->appendChild($submissionFileNode);
            }
        }

        // Publication
        /** @var DOMElement */
        $publicationNode = $articleNode->appendChild($document->createElement('publication'));
        self::setAttributes($publicationNode, [
            'locale' => $locale,
            'version' => '1',
            'status' => '3',
            'seq' => '1',
            'date_published' => self::formatDate($paper->getDatePublished()),
            'access_status' => '0'
        ]);
        /** @var Author */
        foreach ($paper->getAuthors() as $author) {
            if ($author->getPrimaryContact()) {
                $publicationNode->setAttribute('primary_contact_id', $author->getId());
            }
        }

        if ($track) {
            $publicationNode->setAttribute('section_ref', "{[#SECTION_ABBREVIATION#]}");
        }

        // ID
        $id = XMLCustomWriter::createChildWithText($document, $publicationNode, 'id', $paper->getId());
        self::setAttributes($id, [
            'type' => 'internal',
            'advice' => 'ignore'
        ]);

        $mergedCoverage = [];
        foreach ([$paper->getCoverageGeo(null), $paper->getCoverageChron(null), $paper->getCoverageSample(null)] as $coverage) {
            foreach (is_array($coverage) ? $coverage : [] as $locale => $value) {
                $mergedCoverage[$locale][] = $value;
            }
        }
        foreach ($mergedCoverage as $locale => $values) {
            $mergedCoverage[$locale] = implode(',', array_filter(array_map('trim', $values), 'strlen'));
        }

        self::createLocalizedNodes($document, $publicationNode, 'title', $paper->getTitle(null));
        self::createLocalizedNodes($document, $publicationNode, 'abstract', $paper->getAbstract(null));
        self::createLocalizedNodes($document, $publicationNode, 'coverage', $mergedCoverage);
        self::createLocalizedNodes($document, $publicationNode, 'type', $paper->getType(null));

        // Disciplines
        $disciplines = $paper->getDiscipline(null);
        foreach (is_array($disciplines) ? $disciplines : [] as $locale => $discipline) {
            $disciplines = array_filter(array_map('trim', preg_split('/[,;]/', $discipline)), 'strlen');
            if (count($disciplines)) {
                $disciplinesNode = $publicationNode->appendChild($document->createElement('disciplines'));
                $disciplinesNode->setAttribute('locale', $locale);
                foreach ($disciplines as $discipline) {
                    XMLCustomWriter::createChildWithText($document, $disciplinesNode, 'discipline', $discipline, false);
                }
            }
        }

        // Subjects
        $subjects = $paper->getSubject(null);
        foreach (is_array($subjects) ? $subjects : [] as $locale => $subject) {
            $subjects = array_filter(array_map('trim', preg_split('/[,;]/', $subject)), 'strlen');
            if (count($subjects)) {
                $subjectsNode = $publicationNode->appendChild($document->createElement('subjects'));
                $subjectsNode->setAttribute('locale', $locale);
                foreach ($subjects as $subject) {
                    XMLCustomWriter::createChildWithText($document, $subjectsNode, 'subject', $subject, false);
                }
            }
        }

        // Authors
        if (count($paper->getAuthors())) {
            $authorsNode = $publicationNode->appendChild($document->createElement('authors'));
            foreach ($paper->getAuthors() as $i => $author) {
                $authorNode = self::generateAuthor($document, $author, $i + 1, $locale);
                $authorsNode->appendChild($authorNode);
            }
        }

        // Representation - Galleys
        /** @var PaperGalley */
        foreach ($paper->getGalleys() as $paperGalley) {
            $paperFile = $paperFileDao->getPaperFile($paperGalley->getFileId());
            if (!$paperFile) {
                continue;
            }
            $articleGalleyNode = $publicationNode->appendChild($document->createElement('article_galley'));
            self::setAttributes($articleGalleyNode, ['locale' => $paperGalley->getLocale(), 'approved' => 'true']);
            $idNode = XMLCustomWriter::createChildWithText($document, $articleGalleyNode, 'id', $paperGalley->getId(), false);
            self::setAttributes($idNode, ['type' => 'internal', 'advice' => 'ignore']);
            self::createLocalizedNodes($document, $articleGalleyNode, 'name', [$paperGalley->getLocale() => $paperGalley->getLabel()]);
            XMLCustomWriter::createChildWithText($document, $articleGalleyNode, 'seq', $paperGalley->getSequence());
            
            $fileRefNode = $articleGalleyNode->appendChild($document->createElement('submission_file_ref'));
            self::setAttributes($fileRefNode, ['id' => $paperFile->getFileId(), 'revision' => $paperFile->getRevision()]);
        }

        // Representation - Supplementary Files
        /** @var SuppFile */
        foreach ($paper->getSuppFiles() as $suppFile) {
            $paperFile = $paperFileDao->getPaperFile($suppFile->getFileId());
            if (!$paperFile) {
                continue;
            }
            $articleGalleyNode = $publicationNode->appendChild($document->createElement('supplementary_file'));
            self::setAttributes($articleGalleyNode, ['locale' => $locale, 'approved' => 'true']);
            $idNode = XMLCustomWriter::createChildWithText($document, $articleGalleyNode, 'id', $suppFile->getId(), false);
            self::setAttributes($idNode, ['type' => 'internal', 'advice' => 'ignore']);
            self::createLocalizedNodes($document, $articleGalleyNode, 'name', $suppFile->getTitle());
            XMLCustomWriter::createChildWithText($document, $articleGalleyNode, 'seq', $suppFile->getSequence());
            
            $fileRefNode = $articleGalleyNode->appendChild($document->createElement('submission_file_ref'));
            self::setAttributes($fileRefNode, ['id' => $suppFile->getFileId(), 'revision' => $paperFile->getRevision()]);
        }

        // Issue
        $issueNode = $publicationNode->appendChild($document->createElement('issue_identification'));
        XMLCustomWriter::createChildWithText($document, $issueNode, 'volume', '{[#ISSUE_VOLUME#]}', false);
        XMLCustomWriter::createChildWithText($document, $issueNode, 'number', '{[#ISSUE_NUMBER#]}', false);
        XMLCustomWriter::createChildWithText($document, $issueNode, 'year', '{[#ISSUE_YEAR#]}', false);

        XMLCustomWriter::createChildWithText($document, $articleNode, 'pages', $paper->getPages(), false);

        $document->save($filename);
    }

    /**
     * @param DOMDocument $document
     * @param Author $author
     */
    static function generateAuthor($document, $author, $seq, $locale) {
        $authorNode = $document->createElement('author');
        
        if ($author->getPrimaryContact()) {
            $authorNode->setAttribute('primary_contact', 'true');
        }
        self::setAttributes($authorNode, [
            'user_group_ref' => '{[#ROLE_NAME_AUTHOR#]}',
            'seq' => $seq,
            'id' => $author->getId()
        ]);

        self::createLocalizedNodes($document, $authorNode, 'givenname', [$locale => $author->getFirstName() . ($author->getMiddleName() ? ' ' . $author->getMiddleName() : '')]);
        self::createLocalizedNodes($document, $authorNode, 'familyname', [$locale => $author->getLastName()]);
        self::createLocalizedNodes($document, $authorNode, 'affiliation', [$locale => $author->getAffiliation()]);

        XMLCustomWriter::createChildWithText($document, $authorNode, 'country', $author->getCountry(), false);
        XMLCustomWriter::createChildWithText($document, $authorNode, 'email', $author->getEmail(), false);
        XMLCustomWriter::createChildWithText($document, $authorNode, 'url', $author->getUrl(), false);

        self::createLocalizedNodes($document, $authorNode, 'biography', $author->getBiography(null));

        return $authorNode;
    }

    /**
     * @param DOMDocument $document
     * @param PublishedPaper $paper
     * @param PaperFile $paperFile
     */
    static function generateSubmissionFile($document, $paper, $paperFile, $locale) {
        static $genreMap;

        if (!$genreMap) {
            AppLocale::requireComponents([LOCALE_COMPONENT_OCS_AUTHOR]);
            foreach ([
                'author.submit.suppFile.researchInstrument' => '{[#GENRE_NAME_RESEARCHINSTRUMENT#]}',
                'author.submit.suppFile.researchMaterials' => '{[#GENRE_NAME_RESEARCHMATERIALS#]}',
                'author.submit.suppFile.researchResults' => '{[#GENRE_NAME_RESEARCHRESULTS#]}',
                'author.submit.suppFile.transcripts' => '{[#GENRE_NAME_TRANSCRIPTS#]}',
                'author.submit.suppFile.dataAnalysis' => '{[#GENRE_NAME_DATAANALYSIS#]}',
                'author.submit.suppFile.dataSet' => '{[#GENRE_NAME_DATASET#]}',
                'author.submit.suppFile.sourceText' => '{[#GENRE_NAME_SOURCETEXTS#]}',
            ] as $sourceGenre => $targetGenre) {
                $genreMap[__($sourceGenre)] = $targetGenre;
            }
            $genreMap += [
                'OTHER' => '{[#GENRE_NAME_OTHER#]}',
                'SUBMISSION' => '{[#GENRE_NAME_SUBMISSION#]}',
                'STYLE' => '{[#GENRE_NAME_STYLE#]}',
                'IMAGE' => '{[#GENRE_NAME_IMAGE#]}'
            ];
        }

        /** @var PaperGalley|PaperHTMLGalley|SuppFile */
        $ownerGalley = null;
        foreach (array_merge($paper->getGalleys(), $paper->getSuppFiles()) as $object) {
            if ($object->getPaperId() === $paperFile->getPaperId()) {
                $ownerGalley = $object;
                break;
            }
        }

        if ($ownerGalley instanceof PaperHTMLGalley) {
            foreach (array_merge([$ownerGalley->getStyleFile()], $ownerGalley->getImageFiles()) as $dependentPaperFile) {
                // Dependent files will be handled later
                if ($dependentPaperFile->getFileId() === $paperFile->getFileId()) {
                    return null;
                }
            }
        }

        $submissionFileNode = $document->createElement('submission_file');
        self::setAttributes($submissionFileNode, ['stage' => 'submission', 'id' => $paperFile->getFileId()]);

        import('file.PaperFileManager');
        $paperFileManager = new PaperFileManager($paper->getPaperId());
        $paperFileManager->readFile($paperFile->getFileId());
        $revisionNode = $submissionFileNode->appendChild($document->createElement('revision'));
        $paperContent = $paperFileManager->readFile($paperFile->getFileId());
        $contentSize = strlen($paperContent);

        if ((int) $paperFile->getFileSize() !== $contentSize) {
            echo "File ID {$paperFile->getFileId()} has a size mismatch, expected {$paperFile->getFileSize()}, but read {$contentSize}\n";
        }

        self::setAttributes($revisionNode, [
            'number' => $paperFile->getRevision(),
            'genre' => $genreMap['SUBMISSION'],
            'filename' => $paperFile->getOriginalFileName(),
            'viewable' => $paperFile->getViewable() ? 'true' : 'false',
            'date_uploaded' => self::formatDate($paperFile->getDateUploaded()),
            'date_modified' => self::formatDate($paperFile->getDateModified()),
            'filesize' => $contentSize,
            'filetype' => $paperFile->getFileType()
        ]);

        self::createLocalizedNodes($document, $revisionNode, 'name', [$locale => $paperFile->getOriginalFileName()]);

        $embedNode = $revisionNode->appendChild($document->createElement('embed', base64_encode($paperContent)));
        $embedNode->setAttribute('encoding', 'base64');

        if ($ownerGalley instanceof SuppFile) {
            $genre = isset($genreMap[$ownerGalley->getType()]) ? $genreMap[$ownerGalley->getType()] : $genreMap['OTHER'];
            $revisionNode->setAttribute('genre', $genre);
            self::createLocalizedNodes($document, $submissionFileNode, 'creator', $ownerGalley->getCreator(null));
            self::createLocalizedNodes($document, $submissionFileNode, 'subject', $ownerGalley->getSubject(null));
            self::createLocalizedNodes($document, $submissionFileNode, 'description', $ownerGalley->getDescription(null));
            self::createLocalizedNodes($document, $submissionFileNode, 'publisher', $ownerGalley->getPublisher(null));
            self::createLocalizedNodes($document, $submissionFileNode, 'sponsor', $ownerGalley->getSponsor(null));
            if ($dateCreated = $ownerGalley->getDateCreated()) {
                $submissionFileNode->appendChild($document->createElement('date_created', $dateCreated));
            }
            self::createLocalizedNodes($document, $submissionFileNode, 'source', $ownerGalley->getSource(null));
            if ($language = $ownerGalley->getLanguage()) {
                $locale = strlen($language) === 5
                    ? $language
                    : self::getLocaleFromIso3(strlen($language) === 2 ? self::getIso3FromIso1($language) : $language);
                $submissionFileNode->appendChild($document->createElement('language', $locale));
            }
        } elseif ($ownerGalley instanceof PaperHTMLGalley) {
            $submissionFileNodes = $document->createDocumentFragment();
            $submissionFileNodes->appendChild($submissionFileNode);

            foreach (array_merge([$ownerGalley->getStyleFile()], $ownerGalley->getImageFiles()) as $dependentPaperFile) {
                $submissionFileNode = $submissionFileNodes->appendChild($document->createElement('submission_file'));
                self::setAttributes($submissionFileNode, ['stage' => 'dependent', 'id' => $dependentPaperFile->getFileId()]);
                
                $paperFileManager->readFile($dependentPaperFile->getFileId());
                $revisionNode = $submissionFileNode->appendChild($document->createElement('revision'));
                self::setAttributes($revisionNode, [
                    'number' => $dependentPaperFile->getRevision(),
                    'genre' => $genreMap[$ownerGalley->getStyleFile() === $dependentPaperFile ? 'STYLE' : 'IMAGE'],
                    'filename' => $dependentPaperFile->getOriginalFileName(),
                    'viewable' => $dependentPaperFile->getViewable() ? 'true' : 'false',
                    'date_uploaded' => self::formatDate($dependentPaperFile->getDateUploaded()),
                    'date_modified' => self::formatDate($dependentPaperFile->getDateModified()),
                    'filesize' => $dependentPaperFile->getFileSize(),
                    'filetype' => $dependentPaperFile->getFileType()
                ]);
        
                self::createLocalizedNodes($document, $revisionNode, 'name', [$locale => $dependentPaperFile->getOriginalFileName()]);

                $fileRefNode = $revisionNode->appendChild($document->createElement('submission_file_ref'));
                $fileRefNode->setAttribute('id', $paperFile->getFileId());
                $fileRefNode->setAttribute('revision', $paperFile->getRevision());
        
                $embedNode = $revisionNode->appendChild($document->createElement('embed', base64_encode($paperFileManager->readFile($dependentPaperFile->getFileId()))));
                $embedNode->setAttribute('encoding', 'base64');
            }
            return $submissionFileNodes;
        }
        return $submissionFileNode;
    }

    static function formatDate($date) {
        if ($date == '') return null;
        return date('Y-m-d', strtotime($date));
    }

    static function setAttributes($element, $attributes) {
        foreach($attributes as $attr => $value) {
            $element->setAttribute($attr, $value);
        }
    }

    static function createLocalizedNodes($document, $parentNode, $name, $values) {
        if (is_array($values)) {
            foreach ($values as $locale => $value) {
                if ($value === '') {
                    continue;
                }
                $parentNode
                    ->appendChild($document->createElement($name, htmlspecialchars($value, ENT_COMPAT, 'UTF-8')))
                    ->setAttribute('locale', $locale);
            }
        }
    }

    /**
     * Translate an ISO639-3 compatible 3-letter string
     * into the PKP locale identifier.
     *
     * This can be ambiguous if several locales are defined
     * for the same language. In this case we'll use the
     * primary locale to disambiguate.
     *
     * If that still doesn't determine a unique locale then
     * we'll choose the first locale found.
     *
     * @param $iso3 string
     * @return string
     */
    static function getLocaleFromIso3($iso3) {
        assert(strlen($iso3) == 3);
        $primaryLocale = AppLocale::getPrimaryLocale();

        $localeCandidates = array();
        $locales = self::_getAllLocalesCacheContent();
        foreach($locales as $locale => $localeData) {
            assert(isset($localeData['iso639-3']));
            if ($localeData['iso639-3'] == $iso3) {
                if ($locale == $primaryLocale) {
                    // In case of ambiguity the primary locale
                    // overrides all other options so we're done.
                    return $primaryLocale;
                }
                $localeCandidates[] = $locale;
            }
        }

        // Return null if we found no candidate locale.
        if (empty($localeCandidates)) return null;

        if (count($localeCandidates) > 1) {
            // Check whether one of the candidate locales
            // is a supported locale. If so choose the first
            // supported locale.
            $supportedLocales = AppLocale::getSupportedLocales();
            foreach($supportedLocales as $supportedLocale => $localeName) {
                if (in_array($supportedLocale, $localeCandidates)) return $supportedLocale;
            }
        }

        // If there is only one candidate (or if we were
        // unable to disambiguate) then return the unique
        // (first) candidate found.
        return array_shift($localeCandidates);
    }

    /**
     * Translate the ISO 2-letter language string (ISO639-1) into ISO639-3.
     * @param $iso1 string
     * @return string the translated string or null if we
     * don't know about the given language.
     */
    static function getIso3FromIso1($iso1) {
        assert(strlen($iso1) == 2);
        $locales = self::_getAllLocalesCacheContent();
        foreach($locales as $locale => $localeData) {
            if (substr($locale, 0, 2) == $iso1) {
                assert(isset($localeData['iso639-3']));
                return $localeData['iso639-3'];
            }
        }
        return null;
    }

    static function _getAllLocalesCacheContent() {
        return [
            'bs_BA' => 
            [
                'key' => 'bs_BA',
                'complete' => 'false',
                'name' => 'Bosanski',
                'iso639-2b' => 'bos',
                'iso639-3' => 'bos',
            ],
            'ca_ES' => 
            [
                'key' => 'ca_ES',
                'complete' => 'true',
                'name' => 'Català',
                'iso639-2b' => 'cat',
                'iso639-3' => 'cat',
            ],
            'cs_CZ' => 
            [
                'key' => 'cs_CZ',
                'complete' => 'true',
                'name' => 'Čeština',
                'iso639-2b' => 'cze',
                'iso639-3' => 'ces',
            ],
            'da_DK' => 
            [
                'key' => 'da_DK',
                'complete' => 'true',
                'name' => 'Dansk',
                'iso639-2b' => 'dan',
                'iso639-3' => 'dan',
            ],
            'de_DE' => 
            [
                'key' => 'de_DE',
                'complete' => 'true',
                'name' => 'Deutsch',
                'iso639-2b' => 'ger',
                'iso639-3' => 'deu',
            ],
            'el_GR' => 
            [
                'key' => 'el_GR',
                'complete' => 'false',
                'name' => 'ελληνικά',
                'iso639-2b' => 'gre',
                'iso639-3' => 'ell',
            ],
            'en_US' => 
            [
                'key' => 'en_US',
                'complete' => 'true',
                'name' => 'English',
                'iso639-2b' => 'eng',
                'iso639-3' => 'eng',
            ],
            'es_ES' => 
            [
                'key' => 'es_ES',
                'complete' => 'true',
                'name' => 'Español (España)',
                'iso639-2b' => 'spa',
                'iso639-3' => 'spa',
            ],
            'eu_ES' => 
            [
                'key' => 'eu_ES',
                'complete' => 'false',
                'name' => 'Euskara',
                'iso639-2b' => 'eus',
                'iso639-3' => 'eus',
            ],
            'fi_FI' => 
            [
                'key' => 'fi_FI',
                'complete' => 'true',
                'name' => 'Suomi',
                'iso639-2b' => 'fin',
                'iso639-3' => 'fin',
            ],
            'fr_CA' => 
            [
                'key' => 'fr_CA',
                'complete' => 'true',
                'name' => 'Français (Canada)',
                'iso639-2b' => 'fre',
                'iso639-3' => 'fra',
            ],
            'fr_FR' => 
            [
                'key' => 'fr_FR',
                'complete' => 'true',
                'name' => 'Français (France)',
                'iso639-2b' => 'fre',
                'iso639-3' => 'fra',
            ],
            'gd_GB' => 
            [
                'key' => 'gd_GB',
                'complete' => 'false',
                'name' => 'Scottish Gaelic',
                'iso639-2b' => 'gla',
                'iso639-3' => 'gla',
            ],
            'he_IL' => 
            [
                'key' => 'he_IL',
                'complete' => 'false',
                'name' => 'עברית',
                'iso639-2b' => 'heb',
                'iso639-3' => 'heb',
            ],
            'hi_IN' => 
            [
                'key' => 'hi_IN',
                'complete' => 'false',
                'name' => 'Hindi',
                'iso639-2b' => 'hin',
                'iso639-3' => 'hin',
            ],
            'hr_HR' => 
            [
                'key' => 'hr_HR',
                'complete' => 'false',
                'name' => 'Hrvatski',
                'iso639-2b' => 'hrv',
                'iso639-3' => 'hrv',
            ],
            'hu_HU' => 
            [
                'key' => 'hu_HU',
                'complete' => 'true',
                'name' => 'Magyar',
                'iso639-2b' => 'hun',
                'iso639-3' => 'hun',
            ],
            'hy_AM' => 
            [
                'key' => 'hy_AM',
                'complete' => 'false',
                'name' => 'Armenian',
                'iso639-2b' => 'hye',
                'iso639-3' => 'hye',
            ],
            'id_ID' => 
            [
                'key' => 'id_ID',
                'complete' => 'true',
                'name' => 'Bahasa Indonesia',
                'iso639-2b' => 'ind',
                'iso639-3' => 'ind',
            ],
            'is_IS' => 
            [
                'key' => 'is_IS',
                'complete' => 'false',
                'name' => 'Íslenska',
                'iso639-2b' => 'ice',
                'iso639-3' => 'isl',
            ],
            'it_IT' => 
            [
                'key' => 'it_IT',
                'complete' => 'true',
                'name' => 'Italiano',
                'iso639-2b' => 'ita',
                'iso639-3' => 'ita',
            ],
            'ja_JP' => 
            [
                'key' => 'ja_JP',
                'complete' => 'false',
                'name' => '日本語',
                'iso639-2b' => 'jpn',
                'iso639-3' => 'jpn',
            ],
            'ko_KR' => 
            [
                'key' => 'ko_KR',
                'complete' => 'false',
                'name' => '한국어',
                'iso639-2b' => 'kor',
                'iso639-3' => 'kor',
            ],
            'mk_MK' => 
            [
                'key' => 'mk_MK',
                'complete' => 'true',
                'name' => 'македонски јазик',
                'iso639-2b' => 'mkd',
                'iso639-3' => 'mkd',
            ],
            'nb_NO' => 
            [
                'key' => 'nb_NO',
                'complete' => 'true',
                'name' => 'Norsk Bokmål',
                'iso639-2b' => 'nor',
                'iso639-3' => 'nor',
            ],
            'nl_NL' => 
            [
                'key' => 'nl_NL',
                'complete' => 'true',
                'name' => 'Nederlands',
                'iso639-2b' => 'dut',
                'iso639-3' => 'nld',
            ],
            'pl_PL' => 
            [
                'key' => 'pl_PL',
                'complete' => 'true',
                'name' => 'Język Polski',
                'iso639-2b' => 'pol',
                'iso639-3' => 'pol',
            ],
            'pt_BR' => 
            [
                'key' => 'pt_BR',
                'complete' => 'true',
                'name' => 'Português (Brasil)',
                'iso639-2b' => 'por',
                'iso639-3' => 'por',
            ],
            'pt_PT' => 
            [
                'key' => 'pt_PT',
                'complete' => 'true',
                'name' => 'Português (Portugal)',
                'iso639-2b' => 'por',
                'iso639-3' => 'por',
            ],
            'ro_RO' => 
            [
                'key' => 'ro_RO',
                'complete' => 'true',
                'name' => 'Limba Română',
                'iso639-2b' => 'rum',
                'iso639-3' => 'ron',
            ],
            'ru_RU' => 
            [
                'key' => 'ru_RU',
                'complete' => 'true',
                'name' => 'Русский',
                'iso639-2b' => 'rus',
                'iso639-3' => 'rus',
            ],
            'sk_SK' => 
            [
                'key' => 'sk_SK',
                'complete' => 'true',
                'name' => 'Slovenčina',
                'iso639-2b' => 'slk',
                'iso639-3' => 'slk',
            ],
            'sl_SI' => 
            [
                'key' => 'sl_SI',
                'complete' => 'true',
                'name' => 'Slovenščina',
                'iso639-2b' => 'slv',
                'iso639-3' => 'slv',
            ],
            'sr_RS@cyrillic' => 
            [
                'key' => 'sr_RS@cyrillic',
                'complete' => 'false',
                'name' => 'Cрпски',
                'iso639-2b' => 'srp',
                'iso639-3' => 'srp',
            ],
            'sr_RS@latin' => 
            [
                'key' => 'sr_RS@latin',
                'complete' => 'false',
                'name' => 'Srpski',
                'iso639-2b' => 'srp',
                'iso639-3' => 'srp',
            ],
            'sv_SE' => 
            [
                'key' => 'sv_SE',
                'complete' => 'true',
                'name' => 'Svenska',
                'iso639-2b' => 'swe',
                'iso639-3' => 'swe',
            ],
            'tr_TR' => 
            [
                'key' => 'tr_TR',
                'complete' => 'true',
                'name' => 'Türkçe',
                'iso639-2b' => 'tur',
                'iso639-3' => 'tur',
            ],
            'uk_UA' => 
            [
                'key' => 'uk_UA',
                'complete' => 'true',
                'name' => 'Українська',
                'iso639-2b' => 'ukr',
                'iso639-3' => 'ukr',
            ],
            'vi_VN' => 
            [
                'key' => 'vi_VN',
                'complete' => 'true',
                'name' => 'Tiếng Việt',
                'iso639-2b' => 'vie',
                'iso639-3' => 'vie',
            ],
            'zh_CN' => 
            [
                'key' => 'zh_CN',
                'complete' => 'false',
                'name' => '简体中文',
                'iso639-2b' => 'chi',
                'iso639-3' => 'zho',
            ],
            'ar_IQ' => 
            [
                'key' => 'ar_IQ',
                'complete' => 'true',
                'name' => 'العربية',
                'iso639-2b' => 'ara',
                'iso639-3' => 'ara',
                'direction' => 'rtl',
            ],
            'fa_IR' => 
            [
                'key' => 'fa_IR',
                'complete' => 'true',
                'name' => 'فارسی',
                'iso639-2b' => 'per',
                'iso639-3' => 'per',
                'direction' => 'rtl',
            ],
            'ku_IQ' => 
            [
                'key' => 'ku_IQ',
                'complete' => 'true',
                'name' => 'کوردی',
                'iso639-2b' => 'ckb',
                'iso639-3' => 'ckb',
                'direction' => 'rtl',
            ],
        ];
    }
}
