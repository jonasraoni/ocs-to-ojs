<?php

/**
 * @file writers/stable-3_2_1.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Stable321Writer
 *
 * @brief Generates a XML file compatible with OJS 3.2.1 from an OCS paper
 */

require_once __DIR__ . '/BaseXmlWriter.php';

class Stable321Writer extends BaseXmlWriter
{
    /**
     * Generates the paper XML
     * @return string
     */
    public function process()
    {
        $this->processArticle();

        return $this->document->saveXML();
    }

    /**
     * Processes the author
     * @param Author $author
     * @return DOMElement
     */
    protected function processAuthor($author, $seq)
    {
        $authorNode = $this->document->createElement('author');
        
        if ($author->getPrimaryContact()) {
            $authorNode->setAttribute('primary_contact', 'true');
        }
        $this->setAttributes($authorNode, [
            'user_group_ref' => '{[#ROLE_NAME_AUTHOR#]}',
            'seq' => $seq,
            'id' => $author->getId()
        ]);

        $this->createLocalizedNodes($authorNode, 'givenname', [$this->locale => $author->getFirstName() . ($author->getMiddleName() ? ' ' . $author->getMiddleName() : '')]);
        $this->createLocalizedNodes($authorNode, 'familyname', [$this->locale => $author->getLastName()]);
        $this->createLocalizedNodes($authorNode, 'affiliation', [$this->locale => $author->getAffiliation()]);

        $this->createChildWithText($authorNode, 'country', $author->getCountry(), false);
        $this->createChildWithText($authorNode, 'email', $author->getEmail(), false);
        $this->createChildWithText($authorNode, 'url', $author->getUrl(), false);

        $this->createLocalizedNodes($authorNode, 'biography', $author->getBiography(null));

        return $authorNode;
    }

    /**
     * Process submission_file node
     * @param PaperFile $paperFile
     * @return DOMDocumentFragment
     */
    protected function processSubmissionFile($paperFile)
    {
        $documentFragment = $this->document->createDocumentFragment();
        $genreMap = $this->getGenreMap();

        /** @var PaperGalley|PaperHTMLGalley|SuppFile */
        $ownerGalley = null;
        foreach (array_merge($this->paper->getGalleys(), $this->paper->getSuppFiles()) as $object) {
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

        $submissionFileNode = $documentFragment->appendChild($this->document->createElement('submission_file'));
        $this->setAttributes($submissionFileNode, ['stage' => 'submission', 'id' => $paperFile->getFileId()]);

        import('file.PaperFileManager');
        $paperFileManager = new PaperFileManager($this->paper->getPaperId());
        $paperFileManager->readFile($paperFile->getFileId());
        $revisionNode = $submissionFileNode->appendChild($this->document->createElement('revision'));
        $paperContent = $paperFileManager->readFile($paperFile->getFileId());
        $contentSize = strlen($paperContent);

        if ((int) $paperFile->getFileSize() !== $contentSize) {
            echo "File ID {$paperFile->getFileId()} has a size mismatch, expected {$paperFile->getFileSize()}, but read {$contentSize}\n";
        }
        if (!$contentSize) {
            $contentSize = strlen($paperContent = 'Empty');
            echo "File ID {$paperFile->getFileId()} is empty, it will be imported with the content \"{$paperContent}\" for the sake of data completeness\n";
        }

        $this->setAttributes($revisionNode, [
            'number' => $paperFile->getRevision(),
            'genre' => $genreMap['SUBMISSION'],
            'filename' => $paperFile->getOriginalFileName(),
            'viewable' => $paperFile->getViewable() ? 'true' : 'false',
            'date_uploaded' => $this->formatDate($paperFile->getDateUploaded()),
            'date_modified' => $this->formatDate($paperFile->getDateModified()),
            'filesize' => $contentSize,
            'filetype' => $paperFile->getFileType()
        ]);

        $this->createLocalizedNodes($revisionNode, 'name', [$this->locale => $paperFile->getOriginalFileName()]);

        $embedNode = $revisionNode->appendChild($this->document->createElement('embed', base64_encode($paperContent)));
        $embedNode->setAttribute('encoding', 'base64');

        if ($ownerGalley instanceof SuppFile) {
            $genre = isset($genreMap[$ownerGalley->getType()]) ? $genreMap[$ownerGalley->getType()] : $genreMap['OTHER'];
            $revisionNode->setAttribute('genre', $genre);
            $this->createLocalizedNodes($submissionFileNode, 'creator', $ownerGalley->getCreator(null));
            $this->createLocalizedNodes($submissionFileNode, 'subject', $ownerGalley->getSubject(null));
            $this->createLocalizedNodes($submissionFileNode, 'description', $ownerGalley->getDescription(null));
            $this->createLocalizedNodes($submissionFileNode, 'publisher', $ownerGalley->getPublisher(null));
            $this->createLocalizedNodes($submissionFileNode, 'sponsor', $ownerGalley->getSponsor(null));
            if ($dateCreated = $ownerGalley->getDateCreated()) {
                $submissionFileNode->appendChild($this->document->createElement('date_created', $dateCreated));
            }
            $this->createLocalizedNodes($submissionFileNode, 'source', $ownerGalley->getSource(null));
            if ($language = $ownerGalley->getLanguage()) {
                $locale = strlen($language) === 5
                    ? $language
                    : $this->getLocaleFromIso3(strlen($language) === 2 ? $this->getIso3FromIso1($language) : $language);
                $locale = $this->getTargetLocale($locale);
                $submissionFileNode->appendChild($this->document->createElement('language', $locale));
            }
        } elseif ($ownerGalley instanceof PaperHTMLGalley) {
            foreach (array_merge([$ownerGalley->getStyleFile()], $ownerGalley->getImageFiles()) as $dependentPaperFile) {
                $submissionFileNode = $documentFragment->appendChild($this->document->createElement('submission_file'));
                $this->setAttributes($submissionFileNode, ['stage' => 'dependent', 'id' => $dependentPaperFile->getFileId()]);

                $paperContent = $paperFileManager->readFile($dependentPaperFile->getFileId());
                $contentSize = strlen($paperContent);
                if ((int) $dependentPaperFile->getFileSize() !== $contentSize) {
                    echo "File ID {$dependentPaperFile->getFileId()} has a size mismatch, expected {$dependentPaperFile->getFileSize()}, but read {$contentSize}\n";
                }
                if (!$contentSize) {
                    $contentSize = strlen($paperContent = 'Empty');
                    echo "File ID {$dependentPaperFile->getFileId()} is empty, it will be imported with the content \"{$paperContent}\" for the sake of data completeness\n";
                }
                $revisionNode = $submissionFileNode->appendChild($this->document->createElement('revision'));
                $this->setAttributes($revisionNode, [
                    'number' => $dependentPaperFile->getRevision(),
                    'genre' => $genreMap[$ownerGalley->getStyleFile() === $dependentPaperFile ? 'STYLE' : 'IMAGE'],
                    'filename' => $dependentPaperFile->getOriginalFileName(),
                    'viewable' => $dependentPaperFile->getViewable() ? 'true' : 'false',
                    'date_uploaded' => $this->formatDate($dependentPaperFile->getDateUploaded()),
                    'date_modified' => $this->formatDate($dependentPaperFile->getDateModified()),
                    'filesize' => $contentSize,
                    'filetype' => $dependentPaperFile->getFileType()
                ]);

                $this->createLocalizedNodes($revisionNode, 'name', [$this->locale => $dependentPaperFile->getOriginalFileName()]);

                $fileRefNode = $revisionNode->appendChild($this->document->createElement('submission_file_ref'));
                $fileRefNode->setAttribute('id', $paperFile->getFileId());
                $fileRefNode->setAttribute('revision', $paperFile->getRevision());

                $embedNode = $revisionNode->appendChild($this->document->createElement('embed', base64_encode($paperContent)));
                $embedNode->setAttribute('encoding', 'base64');
            }
        }
        return $documentFragment;
    }

    /**
     * Process the issue_identification
     * @param DOMElement $publicationNode
     * @return DOMElement
     */
    protected function processIssue($publicationNode)
    {
        // Issue
        $issueNode = $publicationNode->appendChild($this->document->createElement('issue_identification'));
        $this->createChildWithText($issueNode, 'volume', '{[#ISSUE_VOLUME#]}', false);
        $this->createChildWithText($issueNode, 'number', '{[#ISSUE_NUMBER#]}', false);
        $this->createChildWithText($issueNode, 'year', '{[#ISSUE_YEAR#]}', false);
        return $issueNode;
    }

    /**
     * Retrieves the tag name to be used for the galley
     * @param PaperGalley|SuppFile $galley
     * @return string
     */
    protected function getGalleyTagName($galley)
    {
        return $galley instanceof PaperGalley ? 'article_galley' : 'supplementary_file';
    }

    /**
     * Process the article_galley and supplementary_file
     * @return DOMDocumentFragment
     */
    protected function processGalleys()
    {
        $documentFragment = $this->document->createDocumentFragment();
        /** @var PaperFileDAO */
        $paperFileDao = DAORegistry::getDAO('PaperFileDAO');

        // Representation - Galleys/Supplementary Files
        /** @var PaperGalley|SuppFile */
        foreach (array_merge($this->paper->getGalleys(), $this->paper->getSuppFiles())  as $galley) {
            $paperFile = $paperFileDao->getPaperFile($galley->getFileId());
            if (!$paperFile) {
                continue;
            }

            if ($galley instanceof PaperGalley) {
                $locale = $this->getTargetLocale($galley->getLocale()) ?: $this->locale;
                $names = [$locale => $galley->getLabel() ?: strtoupper(pathinfo($paperFile->getFileName(), PATHINFO_EXTENSION))];
            } else {
                if (!$this->supplementaryFileAsGalley) {
                    continue;
                }
                $locale = $this->locale;
                $names = $galley->getTitle();
                if (!count($names)) {
                    $names = [$locale => strtoupper(pathinfo($paperFile->getFileName(), PATHINFO_EXTENSION))];
                }
            }

            $articleGalleyNode = $documentFragment->appendChild($this->document->createElement($this->getGalleyTagName($galley)));
            $this->setAttributes($articleGalleyNode, ['locale' => $locale, 'approved' => 'true']);
            $idNode = $this->createChildWithText($articleGalleyNode, 'id', $galley->getId(), false);
            $this->setAttributes($idNode, ['type' => 'internal', 'advice' => 'ignore']);
            $this->createLocalizedNodes($articleGalleyNode, 'name', $names);
            $this->createChildWithText($articleGalleyNode, 'seq', $galley->getSequence());
            $fileRefNode = $articleGalleyNode->appendChild($this->document->createElement('submission_file_ref'));
            $this->setAttributes($fileRefNode, ['id' => $paperFile->getFileId(), 'revision' => $paperFile->getRevision()]);
        }
        return $documentFragment;
    }

    /**
     * Process the keywords
     * @param DOMElement $publicationNode
     * @param array $keywords
     * @param string $keywordsNodeName
     * @param string $keywordNodeName
     * @return ?DOMElement
     */
    protected function processKeywords($publicationNode, $keywords, $keywordsNodeName, $keywordNodeName)
    {
        foreach (is_array($keywords) ? $keywords : [] as $locale => $keyword) {
            $locale = $this->getTargetLocale($locale);
            $keywords = array_filter(array_map('trim', preg_split('/[,;]/', $keyword)), 'strlen');
            if (count($keywords)) {
                $keywordsNode = $publicationNode->appendChild($this->document->createElement($keywordsNodeName));
                $keywordsNode->setAttribute('locale', $locale);
                foreach ($keywords as $keyword) {
                    $this->createChildWithText($keywordsNode, $keywordNodeName, $keyword, false);
                }
                return $keywordsNode;
            }
        }
    }

    /**
     * Process the authors node
     * @param DOMElement $publicationNode
     * @return ?DOMElement
     */
    protected function processAuthors($publicationNode)
    {
        if (!count($this->paper->getAuthors())) {
            return;
        }
        $authorsNode = $publicationNode->appendChild($this->document->createElement('authors'));
        foreach ($this->paper->getAuthors() as $i => $author) {
            $authorNode = $this->processAuthor($author, $i + 1);
            $authorsNode->appendChild($authorNode);
        }
        return $authorsNode;
    }


    /**
     * Process the publication node
     * @param DOMElement $articleNode
     * @return DOMElement
     */
    protected function processPublication($articleNode)
    {
        // Publication
        /** @var DOMElement */
        $publicationNode = $articleNode->appendChild($this->document->createElement('publication'));
        $this->setAttributes($publicationNode, [
            'locale' => $this->locale,
            'version' => '1',
            'status' => '3',
            'seq' => '1',
            'date_published' => $this->formatDate($this->paper->getDatePublished()),
            'access_status' => '0'
        ]);

        /** @var Author */
        foreach ($this->paper->getAuthors() as $author) {
            if ($author->getPrimaryContact()) {
                $publicationNode->setAttribute('primary_contact_id', $author->getId());
            }
        }

        if ($this->track) {
            $publicationNode->setAttribute('section_ref', "{[#SECTION_ABBREVIATION#]}");
        }

        $this->setAttributes(
            $this->createChildWithText($publicationNode, 'id', $this->paper->getId()),
            [
                'type' => 'internal',
                'advice' => 'ignore'
            ]
        );

        $mergedCoverage = [];
        foreach ([$this->paper->getCoverageGeo(null), $this->paper->getCoverageChron(null), $this->paper->getCoverageSample(null)] as $coverage) {
            foreach (is_array($coverage) ? $coverage : [] as $locale => $value) {
                $locale = $this->getTargetLocale($locale);
                $mergedCoverage[$locale][] = $value;
            }
        }
        foreach ($mergedCoverage as $locale => $values) {
            $mergedCoverage[$locale] = implode(',', array_filter(array_map('trim', $values), 'strlen'));
        }

        $this->createLocalizedNodes($publicationNode, 'title', $this->paper->getTitle(null));
        $this->createLocalizedNodes($publicationNode, 'abstract', $this->paper->getAbstract(null));
        $this->createLocalizedNodes($publicationNode, 'coverage', $mergedCoverage);
        $this->createLocalizedNodes($publicationNode, 'type', $this->paper->getType(null));

        $this->processKeywords($publicationNode, $this->paper->getDiscipline(null), 'disciplines', 'discipline');
        $this->processKeywords($publicationNode, $this->paper->getSubject(null), 'subjects', 'subject');

        $this->processAuthors($publicationNode);

        $galleysFragment = $this->processGalleys();
        if ($galleysFragment->hasChildNodes()) {
            $publicationNode->appendChild($galleysFragment);
        }
        $this->processIssue($publicationNode);
        return $publicationNode;
    }

    /**
     * Process the article node
     * @return DOMElement
     */
    protected function processArticle()
    {
        // Article
        $articleNode = $this->document->appendChild($this->document->createElementNS('http://pkp.sfu.ca', 'article'));
        $this->setAttributes($articleNode, [
            'xsi:schemaLocation' => 'http://pkp.sfu.ca native.xsd',
            'date_submitted' => $this->formatDate($this->paper->getDateSubmitted()),
            'status' => 3,
            'submission_progress' => 0,
            'current_publication_id' => $this->paper->getId(),
            'stage' => 'production'
        ]);
        $articleNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        /** @var PaperFileDAO */
        $paperFileDao = DAORegistry::getDAO('PaperFileDAO');

        // Submission Files
        /** @var PaperFile */
        foreach ($paperFileDao->getPaperFilesByPaper($this->paper->getId()) as $paperFile) {
            $submissionFileFragment = $this->processSubmissionFile($paperFile);
            if ($submissionFileFragment->hasChildNodes()) {
                $articleNode->appendChild($submissionFileFragment);
            }
        }

        $this->processPublication($articleNode);
        $this->createChildWithText($articleNode, 'pages', $this->paper->getPages(), false);

        return $articleNode;
    }

    /**
     * Builds the genre map
     */
    protected function getGenreMap()
    {
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

        return $genreMap;
    }
}
