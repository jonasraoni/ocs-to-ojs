<?php

/**
 * @file writers/BaseXmlWriter.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class BaseXmlWriter
 *
 * @brief Base class to generate XML from an OCS paper
 */

abstract class BaseXmlWriter {
    /** @var Conference */
    protected $conference;
    /** @var SchedConf */
    protected $schedConf;
    /** @var Track */
    protected $track;
    /** @var PublishedPaper */
    protected $paper;
    /** @var string */
    protected $locale;
    /** @var DOMDocument */
    protected $document;

    /**
     * @param Conference $conference
     * @param SchedConf $schedConf
     * @param Track $track
     * @param PublishedPaper $paper
     */
    public function __construct($conference, $schedConf, $track, $paper)
    {
        $this->conference = $conference;
        $this->schedConf = $schedConf;
        $this->track = $track;
        $this->paper = $paper;

        $language = $paper->getLanguage();
        $locale = strlen($language) === 5
            ? $language
            : $this->getLocaleFromIso3(strlen($language) === 2 ? $this->getIso3FromIso1($language) : $language);

        $this->locale = $locale ?: $conference->getPrimaryLocale();
        $this->document = new DOMDocument('1.0', 'utf-8');
    }

    /**
     * Generates the paper XML
     * @return string
     */
    abstract public function process();

    /**
     * Formats a date string to the format Y-m-d
     */
    protected function formatDate($date) {
        return $date ? date('Y-m-d', strtotime($date)) : null;
    }

    /**
     * Set attributes for an element
     */
    protected function setAttributes($element, $attributes) {
        foreach($attributes as $attr => $value) {
            $element->setAttribute($attr, $value);
        }
        return $element;
    }

    /**
     * Creates localizes nodes
     * @param DOMElement $parentNode
     * @param string $name
     * @param array $values
     */
    protected function createLocalizedNodes($parentNode, $name, $values) {
        if (is_array($values)) {
            foreach ($values as $locale => $value) {
                if ($value === '') {
                    continue;
                }
                $node = $parentNode->appendChild($this->document->createElement($name));
                $node->appendChild($this->document->createTextNode($value));
                $node->setAttribute('locale', $locale);
            }
        }
    }

    /**
     * Creates a child node with text
     * @param DOMElement $node
     * @param string $name
     * @param ?string $value
     * @param bool $appendIfEmpty
     */
    protected function createChildWithText($node, $name, $value, $appendIfEmpty = true) {
        $childNode = null;
        if ($appendIfEmpty || $value != '') {
            $childNode = $node->appendChild($this->document->createElement($name));
            $childNode->appendChild($this->document->createTextNode($value));
        }
        return $childNode;
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
    protected function getLocaleFromIso3($iso3) {
        assert(strlen($iso3) == 3);
        $primaryLocale = AppLocale::getPrimaryLocale();

        $localeCandidates = array();
        $locales = $this->getLocales();
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
    protected function getIso3FromIso1($iso1) {
        assert(strlen($iso1) == 2);
        $locales = $this->getLocales();
        foreach($locales as $locale => $localeData) {
            if (substr($locale, 0, 2) == $iso1) {
                assert(isset($localeData['iso639-3']));
                return $localeData['iso639-3'];
            }
        }
        return null;
    }

    /**
     * Retrieves locale metadata
     */
    protected function getLocales() {
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
