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

    /**
     * Updates the given locale to match the one used by OJS (useful only for OJS 3.4+)
     */
    public static function getTargetLocale($locale)
    {
        $map = [
            'bs_BA' => 'bs',
            'ca_ES' => 'ca',
            'cs_CZ' => 'cs',
            'da_DK' => 'da',
            'de_DE' => 'de',
            'el_GR' => 'el',
            'en_US' => 'en',
            'es_ES' => 'es',
            'eu_ES' => 'eu',
            'fi_FI' => 'fi',
            'fr_CA' => 'fr_CA',
            'fr_FR' => 'fr_FR',
            'gd_GB' => 'gd',
            'he_IL' => 'he',
            'hi_IN' => 'hi',
            'hr_HR' => 'hr',
            'hu_HU' => 'hu',
            'hy_AM' => 'hy',
            'id_ID' => 'id',
            'is_IS' => 'is',
            'it_IT' => 'it',
            'ja_JP' => 'ja',
            'ko_KR' => 'ko',
            'mk_MK' => 'mk',
            'nb_NO' => 'nb',
            'nl_NL' => 'nl',
            'pl_PL' => 'pl',
            'pt_BR' => 'pt_BR',
            'pt_PT' => 'pt_PT',
            'ro_RO' => 'ro',
            'ru_RU' => 'ru',
            'sk_SK' => 'sk',
            'sl_SI' => 'sl',
            'sr_RS@cyrillic' => 'sr@cyrillic',
            'sr_RS@latin' => 'sr@latin',
            'sv_SE' => 'sv',
            'tr_TR' => 'tr',
            'uk_UA' => 'uk',
            'vi_VN' => 'vi',
            'zh_CN' => 'zh_CN',
            'ar_IQ' => 'ar',
            'fa_IR' => 'fa',
            'ku_IQ' => 'ckb'
        ];
        return isset($map[$locale]) ? $map[$locale] : $locale;
    }
}
