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

class Stable330Writer extends Stable321Writer {
    /**
     * Process the article_galley
     * @param DOMElement $publicationNode
     */
    private function processGalleys($publicationNode)
    {
        /** @var PaperFileDAO */
        $paperFileDao = DAORegistry::getDAO('PaperFileDAO');

        /** @var PaperGalley|SuppFile */
        foreach (array_merge($this->paper->getGalleys(), $this->paper->getSuppFiles())  as $galley) {
            $paperFile = $paperFileDao->getPaperFile($galley->getFileId());
            if (!$paperFile) {
                continue;
            }

            if ($galley instanceof PaperGalley) {
                $locale = $galley->getLocale();
                $names = [$locale => $galley->getLabel()];
            } else {
                $locale = $this->locale;
                $names = $galley->getTitle();
            }

            $articleGalleyNode = $publicationNode->appendChild($this->document->createElement('article_galley'));
            $this->setAttributes($articleGalleyNode, ['locale' => $locale, 'approved' => 'true']);
            $idNode = $this->createChildWithText($articleGalleyNode, 'id', $galley->getId(), false);
            $this->setAttributes($idNode, ['type' => 'internal', 'advice' => 'ignore']);
            $this->createLocalizedNodes($articleGalleyNode, 'name', $names);
            $this->createChildWithText($articleGalleyNode, 'seq', $galley->getSequence());
            $fileRefNode = $articleGalleyNode->appendChild($this->document->createElement('submission_file_ref'));
            $this->setAttributes($fileRefNode, ['id' => $paperFile->getFileId(), 'revision' => $paperFile->getRevision()]);
        }
    }
}
