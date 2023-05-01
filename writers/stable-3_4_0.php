<?php

/**
 * @file writers/stable-3_4_0.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Stable330Writer
 *
 * @brief Generates a XML file compatible with OJS 3.4.0 from an OCS paper
 */

require_once __DIR__ . '/stable-3_3_0.php';

class Stable340Writer extends Stable330Writer
{
    /**
     * Process the publication node
     * @param DOMElement $articleNode
     * @return DOMElement
     */
    protected function processPublication($articleNode)
    {
        $publicationNode = parent::processPublication($articleNode);
        $publicationNode->removeAttribute('locale');
        $publicationNode->removeAttribute('language');
        return $publicationNode;
    }

    /**
     * Process the article node
     * @return DOMElement
     */
    protected function processArticle()
    {
        $articleNode = parent::processArticle();
        $articleNode->setAttribute('submission_progress', '');
        return $articleNode;
    }
}
