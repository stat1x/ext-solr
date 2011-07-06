<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010-2011 Markus Goldbach <markus.goldbach@dkd.de>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Default facet renderer.
 *
 * @author	Markus Goldbach <markus.goldbach@dkd.de>
 */
class tx_solr_facet_SimpleFacetRenderer implements tx_solr_FacetRenderer {

	/**
	 * The facet's name as configured in TypoScript.
	 *
	 * @var	string
	 */
	protected $facetName;

	/**
	 * The facet's TypoScript configuration.
	 *
	 * @var	string
	 */
	protected $facetConfiguration;

	/**
	 * The facet options the user can select from.
	 *
	 * @var	array
	 */
	protected $facetOptions = array();

	/**
	 * A cObject used to render things like the facet options.
	 *
	 * @var	tslib_cObj
	 */
	protected $contentObject;

	/**
	 * Template engine to replace template markers with their values.
	 *
	 * @var	tx_solr_Template
	 */
	protected $template;

	/**
	 * The query which is going to be sent to Solr when a user selects a facet.
	 *
	 * @var	tx_solr_Query
	 */
	protected $query;

	/**
	 * Constructor
	 *
	 * @param	string	$facetName The facet's name
	 * @param	array	$facetOptions The facet's options.
	 * @param	array	$facetConfiguration The facet's TypoScript configuration.
	 * @param	tx_solr_Template	$template Template to use to render the facet
	 * @param	tx_solr_Query	$query Query instance used to build links.
	 */
	public function __construct($facetName, array $facetOptions, array $facetConfiguration, tx_solr_Template $template, tx_solr_Query $query) {
		$this->facetName          = $facetName;
		$this->facetOptions       = $facetOptions;
		$this->facetConfiguration = $facetConfiguration;

		$this->contentObject = t3lib_div::makeInstance('tslib_cObj');

		$this->query = $query;

		$this->template = clone $template;
	}

	/**
	 * Sets the link target page Id for links generated by the query linking
	 * methods.
	 *
	 * @param	integer	$pageId The link target page Id.
	 */
	public function setLinkTargetPageId($pageId) {
		$this->query->setLinkTargetPageId(intval($pageId));
	}

	/**
	 * Renders the complete facet.
	 *
	 * @see	tx_solr_FacetRenderer::render()
	 * @return	string	Rendered HTML representing the facet.
	 */
	public function render() {
		$facetOptionLinks  = array();
		$solrConfiguration = tx_solr_Util::getSolrConfiguration();
		$this->template->workOnSubpart('single_facet_option');

		$i = 0;
		foreach ($this->facetOptions as $facetOption => $facetOptionResultCount) {
			if ($facetOption == '_empty_') {
					// TODO - for now we don't handle facet missing.
				continue;
			}

			$facetText    = $this->renderOption($facetOption);

			$facetLink    = $this->buildAddFacetLink(
				$facetText,
				$this->facetName . ':' . $facetOption
			);
			$facetLinkUrl = $this->buildAddFacetUrl(
				$this->facetName . ':' . $facetOption
			);

			$facetHidden = '';
			if (++$i > $solrConfiguration['search.']['faceting.']['limit']) {
				$facetHidden = 'tx-solr-facet-hidden';
			}

			$facetSelected = $this->isSelectedFacetOption($facetOption);

				// negating the facet option links to remove a filter
			if ($this->facetConfiguration['selectingSelectedFacetOptionRemovesFilter']
			&& $facetSelected) {
				$facetLink    = $this->buildRemoveFacetLink(
					$facetText,
					$this->facetName . ':' . $facetOption
				);
				$facetLinkUrl = $this->buildRemoveFacetUrl(
					$this->facetName . ':' . $facetOption
				);
			}

			if ($this->facetConfiguration['singleOptionMode']) {
				$facetLink    = $this->buildReplaceFacetLink(
					$facetText,
					$this->facetName . ':' . $facetOption
				);
				$facetLinkUrl = $this->buildReplaceFacetUrl(
					$this->facetName . ':' . $facetOption
				);
			}

			$facetOptionLinks[] = array(
				'hidden'     => $facetHidden,
				'link'       => $facetLink,
				'url'        => $facetLinkUrl,
				'text'       => $facetText,
				'value'      => $facetOption,
				'count'      => $facetOptionResultCount,
				'selected'   => $facetSelected ? '1' : '0',
				'facet_name' => $this->facetName
			);
		}

		$this->template->addLoop('facet_links', 'facet_link', $facetOptionLinks);

		return $this->template->render();
	}

	/**
	 * Renders a single facet option link according to the rendering
	 * instructions that may have been configured.
	 *
	 * @param	string	$option The facet option's raw string value.
	 * @return	string	The facet option rendered according to rendering instructions if available
	 */
	protected function renderOption($option) {
		$renderedFacetOption = $option;

		if (isset($this->facetConfiguration['renderingInstruction'])) {

				// TODO provide a data field with information about whether a facet option is selected, and pssibly all information from the renderOptions method so that one can use that with TS
			$this->contentObject->start(array('optionValue' => $option));

			$renderedFacetOption = $this->contentObject->cObjGetSingle(
				$this->facetConfiguration['renderingInstruction'],
				$this->facetConfiguration['renderingInstruction.']
			);
		}

		return $renderedFacetOption;
	}

	/**
	 * Checks whether a given facet has been selected by the user by checking
	 * the GET values in the URL.
	 *
	 * @param	string	$facetOptionValue Concret facet filter value to check whether it's selected.
	 */
	protected function isSelectedFacetOption($facetOptionValue) {
		$isSelectedOption = FALSE;

		$resultParameters = t3lib_div::_GET('tx_solr');
		$filterParameters = array();
		if (isset($resultParameters['filter'])) {
			$filterParameters = (array) array_map('urldecode', $resultParameters['filter']);
		}

		$facetsInUse = array();
		foreach ($filterParameters as $filter) {
			list($filterName, $filterValue) = explode(':', $filter);

			if ($filterName == $this->facetName && $filterValue == $facetOptionValue) {
				$isSelectedOption = TRUE;
				break;
			}
		}

		return $isSelectedOption;
	}

	/**
	 * Creates a link tag to add a facet to a search result.
	 *
	 * @param	string	$linkText The link text
	 * @param	string	$facetToAdd A filter string to be used as a link parameter
	 * @return	string	Html link tag to add a facet to a search result
	 */
	protected function buildAddFacetLink($linkText, $facetToAdd) {
		$filterParameters = $this->addFacetAndEncodeFilterParameters($facetToAdd);
		return $this->query->getQueryLink($linkText, array('filter' => $filterParameters));
	}

	/**
	 * Create only the url to add a facet to a search result.
	 *
	 * @param	string	$facetToAdd A filter string to be used as a link parameter
	 * @return	string	Url to a a facet to a search result
	 */
	protected function buildAddFacetUrl($facetToAdd) {
		$filterParameters = $this->addFacetAndEncodeFilterParameters($facetToAdd);
		return $this->query->getQueryUrl(array('filter' => $filterParameters));
	}


	/**
	 * Returns a link tag with a link to remove a given facet from the search result array.
	 *
	 * @param	string	$linkText link text
	 * @param	string	$facetToRemove A filter string to be removed from the link parameters
	 * @return	string	Html tag with link to remove a facet
	 */
	protected function buildRemoveFacetLink($linkText, $facetToRemove) {
		$filterParameters = $this->removeFacetAndEncodeFilterParameters($facetToRemove);
		return $this->query->getQueryLink($linkText, array('filter' => $filterParameters));
	}

	/**
	 * Build the url to remove a facet from a search result.
	 *
	 * @param	string	$facetToRemove A filter string to be removed from the link parameters
	 * @return	string	Url to remove a facet
	 */
	protected function buildRemoveFacetUrl($facetToRemove) {
		$filterParameters = $this->removeFacetAndEncodeFilterParameters($facetToRemove);
		return $this->query->getQueryUrl(array('filter' => $filterParameters));
	}

	/**
	 * Returns a link tag with a link to a given facet from the search result array.
	 *
	 * @param	string	$linkText link text
	 * @param	string	$facetToReplace A filter string to use in the link parameters
	 * @return	string	Html tag with link to remove a facet
	 */
	protected function buildReplaceFacetLink($linkText, $facetToReplace) {
		$filterParameters = $this->replaceFacetAndEncodeFilterParameters($facetToReplace);
		return $this->query->getQueryLink($linkText, array('filter' => $filterParameters));
	}

	/**
	 * Builds the url to a facet from a search result.
	 *
	 * @param	string	$facetToReplace A filter string to use in the link parameters
	 * @return	string	Url to remooce a facet
	 */
	protected function buildReplaceFacetUrl($facetToReplace) {
		$filterParameters = $this->replaceFacetAndEncodeFilterParameters($facetToReplace);
		return $this->query->getQueryUrl(array('filter' => $filterParameters));
	}

	/**
	 * Retrieves the filter parmeters from the url and adds an additional facet
	 * to create a link to add additional facets to a search result.
	 *
	 * @param	string	$facetToAdd Facet filter to add to the filter parameters
	 * @return	array	An array of filter parameters
	 */
	protected function addFacetAndEncodeFilterParameters($facetToAdd) {
		$solrConfiguration = tx_solr_Util::getSolrConfiguration();
		$resultParameters = t3lib_div::_GPmerged('tx_solr');
		$filterParameters = array();

		if (isset($resultParameters['filter'])
		&& !$solrConfiguration['search.']['faceting.']['singleFacetMode']) {
			$filterParameters = array_map('urldecode', $resultParameters['filter']);
		}

		$filterParameters[] = $facetToAdd;
		$filterParameters   = array_unique($filterParameters);
		$filterParameters   = array_map('urlencode', $filterParameters);

		return $filterParameters;
	}

	/**
	 * Removes a facet from to filter query.
	 *
	 * @param	string	$facetToRemove Facet filter to remove from the filter parameters
	 * @return	array	An array of filter parameters
	 */
	protected function removeFacetAndEncodeFilterParameters($facetToRemove) {
		$resultParameters = t3lib_div::_GPmerged('tx_solr');

			// urlencode the array to get the original representation
		$filterParameters = array_values((array) array_map('urldecode', $resultParameters['filter']));
		$filterParameters = array_unique($filterParameters);
		$indexToRemove    = array_search($facetToRemove, $filterParameters);

		if ($indexToRemove !== FALSE) {
			unset($filterParameters[$indexToRemove]);
		}

		$filterParameters = array_map('urlencode', $filterParameters);

		return $filterParameters;
	}

	/**
	 * Replaces a facet in a filter query.
	 *
	 * @param	string	$facetToReplace Facet filter to replace in the filter parameters
	 * @return	array	Array of filter parameters
	 */
	protected function replaceFacetAndEncodeFilterParameters($facetToReplace) {
		$resultParameters = t3lib_div::_GPmerged('tx_solr');

			// urlencode the array to get the original representation
		$filterParameters = array_values((array) array_map('urldecode', $resultParameters['filter']));
		$filterParameters = array_unique($filterParameters);

			// find the currently used option for this facet
		$indexToReplace = FALSE;
		foreach ($filterParameters as $key => $filter) {
			list($filterName, $filterValue) = explode(':', $filter);

			if ($filterName == $this->facetName) {
				$indexToReplace = $key;
				break;
			}
		}

		if ($indexToReplace !== FALSE) {
				// facet found, replace facet
			$filterParameters[$indexToReplace] = $facetToReplace;
		} else {
				// facet not found, add facet
			$filterParameters[] = $facetToReplace;
		}

		$filterParameters = array_map('urlencode', $filterParameters);

		return $filterParameters;
	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/solr/classes/facet/class.tx_solr_facet_simplefacetrenderer.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/solr/classes/facet/class.tx_solr_facet_simplefacetrenderer.php']);
}

?>