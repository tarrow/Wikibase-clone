<?php

namespace Wikibase\Client\Tests\Integration\Hooks;

use HashSiteStore;
use Language;
use MediaWikiSite;
use SiteLookup;
use TestSites;
use Title;
use Wikibase\Client\Hooks\OtherProjectsSidebarGenerator;
use Wikibase\Client\Hooks\SidebarLinkBadgeDisplay;
use Wikibase\Client\Hooks\SiteLinksForDisplayLookup;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookup;
use Wikibase\DataModel\SiteLink;
use Wikibase\DataModel\Term\Term;

/**
 * @covers \Wikibase\Client\Hooks\OtherProjectsSidebarGenerator
 *
 * @group WikibaseClient
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 * @author Thomas Pellissier Tanon
 * @author Marius Hoch < hoo@online.de >
 */
class OtherProjectsSidebarGeneratorTest extends \MediaWikiTestCase {

	const TEST_ITEM_ID = 'Q123';
	const BADGE_ITEM_ID = 'Q4242';
	const BADGE_ITEM_LABEL = 'Badge Label';
	const BADGE_CSS_CLASS = 'badge-class';

	/**
	 * @dataProvider projectLinkSidebarProvider
	 */
	public function testBuildProjectLinkSidebar(
		array $siteIdsToOutput,
		array $result,
		SidebarLinkBadgeDisplay $sidebarLinkBadgeDisplay
	) {
		$otherProjectSidebarGenerator = new OtherProjectsSidebarGenerator(
			'enwiki',
			$this->getSiteLinkForDisplayLookup(),
			$this->getSiteLookup(),
			$sidebarLinkBadgeDisplay,
			$siteIdsToOutput
		);

		$this->assertEquals(
			$result,
			$otherProjectSidebarGenerator->buildProjectLinkSidebar( Title::makeTitle( NS_MAIN, 'Nyan Cat' ) )
		);
	}

	public function projectLinkSidebarProvider() {
		$wiktionaryLink = [
			'msg' => 'wikibase-otherprojects-wiktionary',
			'class' => 'wb-otherproject-link wb-otherproject-wiktionary',
			'href' => 'https://en.wiktionary.org/wiki/Nyan_Cat',
			'hreflang' => 'en'
		];
		$wikiquoteLink = [
			'msg' => 'wikibase-otherprojects-wikiquote',
			'class' => 'wb-otherproject-link wb-otherproject-wikiquote',
			'href' => 'https://en.wikiquote.org/wiki/Nyan_Cat',
			'hreflang' => 'en'
		];
		$wikipediaLink = [
			'msg' => 'wikibase-otherprojects-wikipedia',
			'class' => 'wb-otherproject-link wb-otherproject-wikipedia ' .
				'badge-' . self::BADGE_ITEM_ID . ' ' . self::BADGE_CSS_CLASS,
			'href' => 'https://en.wikipedia.org/wiki/Nyan_Cat',
			'hreflang' => 'en',
			'itemtitle' => self::BADGE_ITEM_LABEL,
		];

		return [
			[
				[],
				[],
				$this->getSidebarLinkBadgeDisplay()
			],
			[
				[ 'spam', 'spam2' ],
				[],
				$this->getSidebarLinkBadgeDisplay()
			],
			[
				[ 'enwiktionary' ],
				[ $wiktionaryLink ],
				$this->getSidebarLinkBadgeDisplay()
			],
			[
				[ 'enwiki' ],
				[ $wikipediaLink ],
				$this->getSidebarLinkBadgeDisplay()
			],
			[
				// Make sure results are sorted alphabetically by their group names
				[ 'enwiktionary', 'enwiki', 'enwikiquote' ],
				[ $wikipediaLink, $wikiquoteLink, $wiktionaryLink ],
				$this->getSidebarLinkBadgeDisplay()
			],
		];
	}

	/**
	 * @return SiteLookup
	 */
	private function getSiteLookup() {
		$siteStore = new HashSiteStore( TestSites::getSites() );

		$site = new MediaWikiSite();
		$site->setGlobalId( 'enwikiquote' );
		$site->setGroup( 'wikiquote' );
		$site->setLanguageCode( 'en' );
		$site->setPath( MediaWikiSite::PATH_PAGE, 'https://en.wikiquote.org/wiki/$1' );
		$siteStore->saveSite( $site );

		return $siteStore;
	}

	/**
	 * @return SiteLinksForDisplayLookup
	 */
	private function getSiteLinkForDisplayLookup() {
		$lookup = $this->createMock( SiteLinksForDisplayLookup::class );
		$lookup->expects( $this->any() )
			->method( 'getSiteLinksForPageTitle' )
			->with( Title::makeTitle( NS_MAIN, 'Nyan Cat' ) )
			->will( $this->returnValue( [
				'enwikiquote' => new SiteLink( 'enwikiquote', 'Nyan Cat' ),
				'enwiki' => new SiteLink( 'enwiki', 'Nyan Cat', [ new ItemId( self::BADGE_ITEM_ID ) ] ),
				'enwiktionary' => new SiteLink( 'enwiktionary', 'Nyan Cat' )
			] ) );
		return $lookup;
	}

	/**
	 * @return SidebarLinkBadgeDisplay
	 */
	private function getSidebarLinkBadgeDisplay() {
		$labelDescriptionLookup = $this->createMock( LabelDescriptionLookup::class );
		$labelDescriptionLookup->method( 'getLabel' )
			->with( new ItemId( self::BADGE_ITEM_ID ) )
			->will( $this->returnValue( new Term( 'en', self::BADGE_ITEM_LABEL ) ) );

		return new SidebarLinkBadgeDisplay(
			$labelDescriptionLookup,
			[ self::BADGE_ITEM_ID => self::BADGE_CSS_CLASS ],
			Language::factory( 'en' )
		);
	}

}
