<?php

/**
 * @file writers/stable-3_3_0.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Stable330Writer
 *
 * @brief Generates a XML file compatible with OJS 3.3.0 from an OCS paper
 */

require_once __DIR__ . '/stable-3_2_1.php';

class Stable330Writer extends Stable321Writer
{
    /**
     * Retrieves the tag name to be used for the galley
     * @param PaperGalley|SuppFile $galley
     * @return string
     */
    protected function getGalleyTagName($galley)
    {
        return 'article_galley';
    }

    /**
     * Process the article node
     * @return DOMElement
     */
    protected function processArticle()
    {
        $articleNode = parent::processArticle();
        $articleNode->setAttribute('locale', $this->locale);
        return $articleNode;
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
        $this->setAttributes(
            $submissionFileNode,
            [
                'stage' => 'submission',
                'id' => $paperFile->getFileId(),
                'created_at' => $this->formatDate($paperFile->getDateUploaded()),
                'updated_at' => $this->formatDate($paperFile->getDateModified()),
                'file_id' => $paperFile->getFileId(),
                'viewable' => $paperFile->getViewable() ? 'true' : 'false',
                'genre' => $genreMap['SUBMISSION']
            ]
        );

        if ($ownerGalley instanceof SuppFile) {
            if ($dateCreated = $ownerGalley->getDateCreated()) {
                $submissionFileNode->setAttribute('date_created', $dateCreated);
            }
            if ($language = $ownerGalley->getLanguage()) {
                $submissionFileNode->setAttribute('language', $language);
            }
            $genre = isset($genreMap[$ownerGalley->getType()]) ? $genreMap[$ownerGalley->getType()] : $genreMap['OTHER'];
            $submissionFileNode->setAttribute('genre', $genre);
            $this->createLocalizedNodes($submissionFileNode, 'creator', $ownerGalley->getCreator(null));
            $this->createLocalizedNodes($submissionFileNode, 'description', $ownerGalley->getDescription(null));
        }

        $this->createLocalizedNodes($submissionFileNode, 'name', [$this->locale => $paperFile->getOriginalFileName()]);

        if ($ownerGalley instanceof SuppFile) {
            $this->createLocalizedNodes($submissionFileNode, 'publisher', $ownerGalley->getPublisher(null));
            $this->createLocalizedNodes($submissionFileNode, 'source', $ownerGalley->getSource(null));
            $this->createLocalizedNodes($submissionFileNode, 'sponsor', $ownerGalley->getSponsor(null));
            $this->createLocalizedNodes($submissionFileNode, 'subject', $ownerGalley->getSubject(null));
        }

        import('file.PaperFileManager');
        $paperFileManager = new PaperFileManager($this->paper->getPaperId());
        $paperContent = $paperFileManager->readFile($paperFile->getFileId());
        $contentSize = strlen($paperContent);
        if ((int) $paperFile->getFileSize() !== $contentSize) {
            echo "File ID {$paperFile->getFileId()} has a size mismatch, expected {$paperFile->getFileSize()}, but read {$contentSize}\n";
        }
        $fileNode = $submissionFileNode->appendChild($this->setAttributes(
            $this->document->createElement('file'),
            [
                'id' => $paperFile->getFileId(),
                'filesize' => $contentSize,
                'extension' => pathinfo($paperFile->getOriginalFileName(), PATHINFO_EXTENSION)
            ]
        ));

        $embedNode = $fileNode->appendChild($this->document->createElement('embed', base64_encode($paperContent)));
        $embedNode->setAttribute('encoding', 'base64');

        if ($ownerGalley instanceof PaperHTMLGalley) {
            foreach (array_merge([$ownerGalley->getStyleFile()], $ownerGalley->getImageFiles()) as $dependentPaperFile) {
                $submissionFileNode = $documentFragment->appendChild($this->document->createElement('submission_file'));
                $this->setAttributes(
                    $submissionFileNode,
                    [
                        'stage' => 'dependent',
                        'id' => $dependentPaperFile->getFileId(),
                        'created_at' => $this->formatDate($dependentPaperFile->getDateUploaded()),
                        'date_created' => $this->formatDate($dependentPaperFile->getDateUploaded()),
                        'updated_at' => $this->formatDate($dependentPaperFile->getDateModified()),
                        'file_id' => $dependentPaperFile->getFileId(),
                        'viewable' => $dependentPaperFile->getViewable() ? 'true' : 'false',
                        'genre' => $genreMap[$ownerGalley->getStyleFile() === $dependentPaperFile ? 'STYLE' : 'IMAGE']
                    ]
                );

                $this->createLocalizedNodes($submissionFileNode, 'name', [$this->locale => $dependentPaperFile->getOriginalFileName()]);

                $fileRefNode = $submissionFileNode->appendChild($this->document->createElement('submission_file_ref'));
                $fileRefNode->setAttribute('id', $paperFile->getFileId());

                $paperContent = $paperFileManager->readFile($dependentPaperFile->getFileId());
                $contentSize = strlen($paperContent);
                if ((int) $dependentPaperFile->getFileSize() !== $contentSize) {
                    echo "File ID {$dependentPaperFile->getFileId()} has a size mismatch, expected {$dependentPaperFile->getFileSize()}, but read {$contentSize}\n";
                }
                $fileNode = $submissionFileNode->appendChild($this->setAttributes(
                    $this->document->createElement('file'),
                    [
                        'id' => $dependentPaperFile->getFileId(),
                        'filesize' => $contentSize,
                        'extension' => pathinfo($dependentPaperFile->getOriginalFileName(), PATHINFO_EXTENSION)
                    ]
                ));
                $embedNode = $fileNode->appendChild($this->document->createElement('embed', base64_encode($paperContent)));
                $embedNode->setAttribute('encoding', 'base64');
            }
        }
        return $documentFragment;
    }

    /**
     * Process the article_galley and supplementary_file
     * @return DOMDocumentFragment
     */
    protected function processGalleys()
    {
        $documentFragment = parent::processGalleys();
        /** @var DOMElement */
        foreach ($documentFragment->childNodes as $galleyNode) {
            /** @var DOMElement */
            foreach ($galleyNode->getElementsByTagName('submission_file_ref') as $submissionFileRefNode) {
                $submissionFileRefNode->removeAttribute('revision');
            }
        }
        return $documentFragment;
    }
}
