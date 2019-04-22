<?php

use MediaWiki\MediaWikiServices;

/**
 * A class for static helper functions for Semantic Drilldown
 *
 * @author Yaron Koren
 */

class SDUtils {

	static function setGlobalJSVariables( &$vars ) {
		global $sdgScriptPath;

		$vars['sdgDownArrowImage'] = "$sdgScriptPath/skins/down-arrow.png";
		$vars['sdgRightArrowImage'] = "$sdgScriptPath/skins/right-arrow.png";
		return true;
	}

	/**
	 * Helper function to get the SMW data store for different versions
	 * of SMW.
	 */
	public static function getSMWStore() {
		if ( class_exists( '\SMW\StoreFactory' ) ) {
			// SMW 1.9+
			return \SMW\StoreFactory::getStore();
		} else {
			return smwfGetStore();
		}
	}

	/**
	 * Helper function to handle getPropertyValues().
	 *
	 * @param SMWStore $store
	 * @param string $pageName
	 * @param int $pageNamespace
	 * @param string $propID
	 * @param null|SMWRequestOptions $requestOptions
	 *
	 * @return array of SMWDataItem
	 */
	public static function getSMWPropertyValues( SMWStore $store, $pageName, $pageNamespace, $propID, $requestOptions = null ) {
		$pageName = str_replace( ' ', '_', $pageName );
		$page = new SMWDIWikiPage( $pageName, $pageNamespace, '' );
		$property = new SMWDIProperty( $propID );
		return $store->getPropertyValues( $page, $property, $requestOptions );
	}

	/**
	 * Gets a list of the names of all categories in the wiki that aren't
	 * children of some other category - this list additionally includes,
	 * and excludes, categories that are manually set with
	 * 'SHOWINDRILLDOWN' and 'HIDEFROMDRILLDOWN', respectively.
	 */
	static function getTopLevelCategories() {
		$categories = [];
		$dbr = wfGetDB( DB_REPLICA );
		extract( $dbr->tableNames( 'page', 'categorylinks', 'page_props' ) );
		$cat_ns = NS_CATEGORY;
		$sql = "SELECT page_title FROM $page p LEFT OUTER JOIN $categorylinks cl ON p.page_id = cl.cl_from WHERE p.page_namespace = $cat_ns AND cl.cl_to IS NULL";
		$res = $dbr->query( $sql );
		if ( $dbr->numRows( $res ) > 0 ) {
			while ( $row = $dbr->fetchRow( $res ) ) {
				$categories[] = str_replace( '_', ' ', $row[0] );
			}
		}
		$dbr->freeResult( $res );

		// get 'hide' and 'show' categories
		$hidden_cats = $shown_cats = [];
		$sql2 = "SELECT p.page_title, pp.pp_propname FROM $page p JOIN $page_props pp ON p.page_id = pp.pp_page WHERE p.page_namespace = $cat_ns AND (pp.pp_propname = 'hidefromdrilldown' OR pp.pp_propname = 'showindrilldown') AND pp.pp_value = 'y'";
		$res2 = $dbr->query( $sql2 );
		if ( $dbr->numRows( $res2 ) > 0 ) {
			while ( $row = $dbr->fetchRow( $res2 ) ) {
				if ( $row[1] == 'hidefromdrilldown' ) {
					$hidden_cats[] = str_replace( '_', ' ', $row[0] );
				} else {
					$shown_cats[] = str_replace( '_', ' ', $row[0] );
				}
			}
		}
		$dbr->freeResult( $res2 );
		$categories = array_merge( $categories, $shown_cats );
		foreach ( $hidden_cats as $hidden_cat ) {
			foreach ( $categories as $i => $cat ) {
				if ( $cat == $hidden_cat ) {
					unset( $categories[$i] );
				}
			}
		}
		sort( $categories );
		// This shouldn't be necessary, but sometimes it is, due
		// to faulty storage in either MW or SMW.
		$categories = array_unique( $categories );
		return $categories;
	}

	/**
	 * Gets the list of names of only those categories in the wiki
	 * that have a __SHOWINDRILLDOWN__ declaration on their page.
	 */
	static function getOnlyExplicitlyShownCategories() {
		$shown_cats = [];

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			[ 'p' => 'page', 'pp' => 'page_props' ],
			'p.page_title',
			[
				'p.page_namespace' => NS_CATEGORY,
				'pp.pp_propname' => 'showindrilldown',
				'pp.pp_value' => 'y'
			],
			'SDUtils::getOnlyExplicitlyShownCategories',
			[ 'ORDER BY' => 'p.page_title' ],
			[ 'pp' => [ 'JOIN', 'p.page_id = pp.pp_page' ] ]
		);

		while ( $row = $dbr->fetchRow( $res ) ) {
			$shown_cats[] = str_replace( '_', ' ', $row[0] );
		}
		$dbr->freeResult( $res );

		return $shown_cats;
	}

	/**
	 * Returns the list of categories that will show up in the
	 * header/sidebar of the 'BrowseData' special page.
	 */
	public static function getCategoriesForBrowsing() {
		global $sdgHideCategoriesByDefault;

		if ( $sdgHideCategoriesByDefault ) {
			return self::getOnlyExplicitlyShownCategories();
		} else {
			return self::getTopLevelCategories();
		}
	}

	/**
	 * Gets all the filters specified for a category.
	 */
	static function loadFiltersForCategory( $category ) {
		$filters = [];

		$title = Title::newFromText( $category, NS_CATEGORY );

		// Return an empty array if the title object couldn't be created.
		// This mainly happens if people change the $_cat parameter in the url.
		if ( $title === null ) {
			return $filters;
		}

		$pageId = $title->getArticleID();
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'page_props',
			[
				'pp_value'
			],
			[
				'pp_page' => $pageId,
				'pp_propname' => 'SDFilters'
			]
		);

		while ( $row = $dbr->fetchRow( $res ) ) {
			// There should only be one row.
			$filtersStr = $row['pp_value'];
			$filtersInfo = unserialize( $filtersStr );
			foreach ( $filtersInfo as $filterName => $filterValues ) {
				$curFilter = new SDFilter();
				$curFilter->setName( $filterName );
				foreach ( $filterValues as $key => $value ) {
					if ( $key == 'property' ) {
						$curFilter->setProperty( $value );
						$curFilter->loadPropertyTypeFromProperty();
					} elseif ( $key == 'category' ) {
						$curFilter->setCategory( $value );
					} elseif ( $key == 'requires' ) {
						$curFilter->addRequiredFilter( $value );
					}
				}
				$filters[] = $curFilter;
			}
		}

		// Read from the Page Schemas schema for this category, if
		// it exists, and add any filters defined there.
		if ( class_exists( 'PSSchema' ) ) {
			$pageSchemaObj = new PSSchema( $category );
			if ( $pageSchemaObj->isPSDefined() ) {
				$filters_ps = SDFilter::loadAllFromPageSchema( $pageSchemaObj );
				$result_filters = array_merge( $filters, $filters_ps );
				return $result_filters;
			}
		}
		return $filters;
	}

	/**
	 * Gets the custom drilldown title for a category, if there is one.
	 */
	static function getDrilldownTitleForCategory( $category ) {
		$title = Title::newFromText( $category, NS_CATEGORY );

		// Return false if the title object couldn't be created.
		// This mainly happens if people change the $_cat in the url.
		if ( $title === null ) {
			return false;
		}

		$pageID = $title->getArticleID();
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'page_props',
			[
				'pp_value'
			],
			[
				'pp_page' => $pageID,
				'pp_propname' => 'SDTitle'
			]
		);

		if ( $row = $dbr->fetchRow( $res ) ) {
			return $row['pp_value'];
		}
	}

	/**
	 * Gets all the display parameters defined for a category
	 */
	static function getDisplayParamsForCategory( $category ) {
		$return_display_params = [];

		$title = Title::newFromText( $category, NS_CATEGORY );
		$pageID = $title->getArticleID();
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( 'page_props',
			[
				'pp_value'
			],
			[
				'pp_page' => $pageID,
				'pp_propname' => 'SDDisplayParams'
			]
		);

		while ( $row = $dbr->fetchRow( $res ) ) {
			// There should only be one row.
			$displayParamsStr = $row['pp_value'];
			$return_display_params[] = explode( ';', $displayParamsStr );
		}

		return $return_display_params;
	}

	static function getCategoryChildren( $category_name, $get_categories, $levels ) {
		if ( $levels == 0 ) {
			return [];
		}
		$pages = [];
		$subcategories = [];
		$dbr = wfGetDB( DB_REPLICA );
		extract( $dbr->tableNames( 'page', 'categorylinks' ) );
		$cat_ns = NS_CATEGORY;
		$query_category = str_replace( ' ', '_', $category_name );
		$query_category = str_replace( "'", "\'", $query_category );
		$sql = "SELECT p.page_title, p.page_namespace FROM $categorylinks cl
	JOIN $page p on cl.cl_from = p.page_id
	WHERE cl.cl_to = '$query_category'\n";
		if ( $get_categories ) {
			$sql .= "AND p.page_namespace = $cat_ns\n";
		}
		$sql .= "ORDER BY cl.cl_sortkey";
		$res = $dbr->query( $sql );
		while ( $row = $dbr->fetchRow( $res ) ) {
			if ( $get_categories ) {
				$subcategories[] = $row[0];
				$pages[] = $row[0];
			} else {
				if ( $row[1] == $cat_ns ) {
					$subcategories[] = $row[0];
				} else {
					$pages[] = $row[0];
				}
			}
		}
		$dbr->freeResult( $res );
		foreach ( $subcategories as $subcategory ) {
			$pages = array_merge( $pages, self::getCategoryChildren( $subcategory, $get_categories, $levels - 1 ) );
		}
		return $pages;
	}

	static function getDateFunctions( $dateDBField ) {
		global $wgDBtype;

		// Unfortunately, date handling in general - and date extraction
		// specifically - is done differently in almost every DB
		// system. If support were ever added for SQLite or Oracle,
		// those would require special handling as well.
		if ( $wgDBtype == 'postgres' ) {
			$yearValue = "EXTRACT(YEAR FROM TIMESTAMP $dateDBField)";
			$monthValue = "EXTRACT(MONTH FROM TIMESTAMP $dateDBField)";
			$dayValue = "EXTRACT(DAY FROM TIMESTAMP $dateDBField)";
		} else { // MySQL, MS SQL Server
			$yearValue = "YEAR($dateDBField)";
			$monthValue = "MONTH($dateDBField)";
			// SQL Server only supports DAY(), not DAYOFMONTH().
			$dayValue = "DAY($dateDBField)";
		}
		return [ $yearValue, $monthValue, $dayValue ];
	}

	static function monthToString( $month ) {
		if ( $month == 1 ) {
			return wfMessage( 'january' )->text();
		} elseif ( $month == 2 ) {
			return wfMessage( 'february' )->text();
		} elseif ( $month == 3 ) {
			return wfMessage( 'march' )->text();
		} elseif ( $month == 4 ) {
			return wfMessage( 'april' )->text();
		} elseif ( $month == 5 ) {
			// Needed to avoid using 3-letter abbreviation
			return wfMessage( 'may_long' )->text();
		} elseif ( $month == 6 ) {
			return wfMessage( 'june' )->text();
		} elseif ( $month == 7 ) {
			return wfMessage( 'july' )->text();
		} elseif ( $month == 8 ) {
			return wfMessage( 'august' )->text();
		} elseif ( $month == 9 ) {
			return wfMessage( 'september' )->text();
		} elseif ( $month == 10 ) {
			return wfMessage( 'october' )->text();
		} elseif ( $month == 11 ) {
			return wfMessage( 'november' )->text();
		} else { // if ($month == 12) {
			return wfMessage( 'december' )->text();
		}
	}

	static function stringToMonth( $str ) {
		if ( $str == wfMessage( 'january' )->text() ) {
			return 1;
		} elseif ( $str == wfMessage( 'february' )->text() ) {
			return 2;
		} elseif ( $str == wfMessage( 'march' )->text() ) {
			return 3;
		} elseif ( $str == wfMessage( 'april' )->text() ) {
			return 4;
		} elseif ( $str == wfMessage( 'may_long' )->text() ) {
			return 5;
		} elseif ( $str == wfMessage( 'june' )->text() ) {
			return 6;
		} elseif ( $str == wfMessage( 'july' )->text() ) {
			return 7;
		} elseif ( $str == wfMessage( 'august' )->text() ) {
			return 8;
		} elseif ( $str == wfMessage( 'september' )->text() ) {
			return 9;
		} elseif ( $str == wfMessage( 'october' )->text() ) {
			return 10;
		} elseif ( $str == wfMessage( 'november' )->text() ) {
			return 11;
		} else { // if ($strmonth == wfMessage('december')->text()) {
			return 12;
		}
	}

	static function booleanToString( $bool_value ) {
		$words_field_name = ( $bool_value == true ) ? 'smw_true_words' : 'smw_false_words';
		$words_array = explode( ',', wfMessage( $words_field_name )->inContentLanguage()->text() );
		// go with the value in the array that tends to be "yes" or
		// "no", which is the 3rd
		$index_of_word = 2;
		// capitalize first letter of word
		if ( count( $words_array ) > $index_of_word ) {
			$string_value = ucwords( $words_array[$index_of_word] );
		} elseif ( count( $words_array ) == 0 ) {
			$string_value = $bool_value; // a safe value if no words are found
		} else {
			$string_value = ucwords( $words_array[0] );
		}
		return $string_value;
	}

	/**
	 * Register magic-word variable IDs
	 */
	static function addMagicWordVariableIDs( &$magicWordVariableIDs ) {
		$magicWordVariableIDs[] = 'MAG_HIDEFROMDRILLDOWN';
		$magicWordVariableIDs[] = 'MAG_SHOWINDRILLDOWN';
		return true;
	}

	/**
	 * Set the actual value of the magic words
	 */
	static function addMagicWordLanguage( &$magicWords, $langCode ) {
		switch ( $langCode ) {
		default:
			$magicWords['MAG_HIDEFROMDRILLDOWN'] = [ 0, '__HIDEFROMDRILLDOWN__' ];
			$magicWords['MAG_SHOWINDRILLDOWN'] = [ 0, '__SHOWINDRILLDOWN__' ];
		}
		return true;
	}

	/**
	 * Set values in the page_props table based on the presence of the
	 * 'HIDEFROMDRILLDOWN' and 'SHOWINDRILLDOWN' magic words in a page
	 */
	static function handleShowAndHide( &$parser, &$text ) {
		if ( class_exists( MagicWordFactory::class ) ) {
			// MW 1.32+
			$factory = MediaWikiServices::getInstance()->getMagicWordFactory();
			$mw_hide = $factory->get( 'MAG_HIDEFROMDRILLDOWN' );
			$mw_show = $factory->get( 'MAG_SHOWINDRILLDOWN' );
		} else {
			$mw_hide = MagicWord::get( 'MAG_HIDEFROMDRILLDOWN' );
			$mw_show = MagicWord::get( 'MAG_SHOWINDRILLDOWN' );
		}
		if ( $mw_hide->matchAndRemove( $text ) ) {
			$parser->mOutput->setProperty( 'hidefromdrilldown', 'y' );
		}
		if ( $mw_show->matchAndRemove( $text ) ) {
			$parser->mOutput->setProperty( 'showindrilldown', 'y' );
		}
		return true;
	}

	public static function getIDsTableName() {
		global $smwgDefaultStore;

		if ( $smwgDefaultStore === 'SMWSQLStore3' || $smwgDefaultStore === 'SMWSparqlStore' ) {
			return 'smw_object_ids';
		} else {
			return 'smw_ids';
		}
	}

	public static function getCategoryInstancesTableName() {
		global $smwgDefaultStore;

		if ( $smwgDefaultStore === 'SMWSQLStore3' || $smwgDefaultStore === 'SMWSparqlStore' ) {
			return 'smw_fpt_inst';
		} else {
			return 'smw_inst2';
		}
	}

	public static function addToAdminLinks( &$admin_links_tree ) {
		$browse_search_section = $admin_links_tree->getSection( wfMessage( 'adminlinks_browsesearch' )->text() );
		$sd_row = new ALRow( 'sd' );
		$sd_row->addItem( ALItem::newFromSpecialPage( 'BrowseData' ) );
		$sd_name = wfMessage( 'specialpages-group-sd_group' )->text();
		$sd_docu_label = wfMessage( 'adminlinks_documentation', $sd_name )->text();
		$sd_row->addItem( AlItem::newFromExternalLink( "https://www.mediawiki.org/wiki/Extension:Semantic_Drilldown", $sd_docu_label ) );

		$browse_search_section->addRow( $sd_row );

		return true;
	}

	/**
	 * Register extension unit tests with old versions of MediaWiki
	 *
	 * @param string[] $paths
	 * @return bool
	 */
	public static function onUnitTestsList( &$paths ) {
		$paths[] = __DIR__ . '/../tests/phpunit';
		return true;
	}

	/**
	 * Escapes the given string
	 *
	 * @param string $val
	 * @return string
	 */
	public static function escapeString( $val ) {
		return htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' );
	}
}
