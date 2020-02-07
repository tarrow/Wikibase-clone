<?php

namespace Wikibase\Lib\Tests\Store\Sql\Terms;

use MediaWikiTestCase;
use Wikibase\DataAccess\EntitySource;
use Wikibase\DataAccess\Tests\DataAccessSettingsFactory;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Term\AliasGroup;
use Wikibase\DataModel\Term\AliasGroupList;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\DataModel\Term\Term;
use Wikibase\DataModel\Term\TermList;
use Wikibase\Lib\Store\Sql\Terms\DatabasePropertyTermStore;
use Wikibase\Lib\Store\Sql\Terms\InMemoryTermStore;
use Wikibase\Lib\Store\Sql\Terms\PrefetchingPropertyTermLookup;
use Wikibase\Lib\Tests\Store\Sql\Terms\Util\FakeLoadBalancer;
use Wikibase\StringNormalizer;
use Wikibase\WikibaseSettings;

/**
 * @covers \Wikibase\Lib\Store\Sql\Terms\PrefetchingPropertyTermLookup
 *
 * @group Wikibase
 * @group Database
 *
 * @license GPL-2.0-or-later
 */
class PrefetchingPropertyTermLookupTest extends MediaWikiTestCase {

	/** @var PrefetchingPropertyTermLookup */
	private $lookup;

	/** @var PropertyId */
	private $p1;

	/** @var PropertyId */
	private $p2;

	protected function setUp() : void {
		if ( !WikibaseSettings::isRepoEnabled() ) {
			$this->markTestSkipped( "Skipping because WikibaseClient doesn't have local term store tables." );
		}

		parent::setUp();
		$this->tablesUsed[] = 'wbt_property_terms';
		$loadBalancer = new FakeLoadBalancer( [ 'dbr' => $this->db ] );
		$termIdsStore = new InMemoryTermStore();
		$this->lookup = new PrefetchingPropertyTermLookup(
			$loadBalancer,
			$termIdsStore
		);

		$propertyTermStore = new DatabasePropertyTermStore(
			$loadBalancer,
			$termIdsStore,
			$termIdsStore,
			$termIdsStore,
			new StringNormalizer(),
			$this->getPropertySource(),
			DataAccessSettingsFactory::repositoryPrefixBasedFederation()
		);
		$this->p1 = new PropertyId( 'P1' );
		$propertyTermStore->storeTerms(
			$this->p1,
			new Fingerprint(
				new TermList( [ new Term( 'en', 'property one' ) ] ),
				new TermList( [ new Term( 'en', 'the first property' ) ] ),
				new AliasGroupList( [ new AliasGroup( 'en', [ 'P1' ] ) ] )
			)
		);
		$this->p2 = new PropertyId( 'P2' );
		$propertyTermStore->storeTerms(
			$this->p2,
			new Fingerprint(
				new TermList( [ new Term( 'en', 'property two' ) ] ),
				new TermList( [ new Term( 'en', 'the second property' ) ] ),
				new AliasGroupList( [ new AliasGroup( 'en', [ 'P2' ] ) ] )
			)
		);
	}

	private function getPropertySource() {
		return new EntitySource( 'test', false, [ 'property' => [ 'namespaceId' => 123, 'slot' => 'main' ] ], '', '', '', '' );
	}

	public function testGetLabel() {
		$label1 = $this->lookup->getLabel( $this->p1, 'en' );
		$label2 = $this->lookup->getLabel( $this->p2, 'en' );

		$this->assertSame( 'property one', $label1 );
		$this->assertSame( 'property two', $label2 );
	}

	public function testGetDescription() {
		$description1 = $this->lookup->getDescription( $this->p1, 'en' );
		$description2 = $this->lookup->getDescription( $this->p2, 'en' );

		$this->assertSame( 'the first property', $description1 );
		$this->assertSame( 'the second property', $description2 );
	}

	public function testPrefetchTermsAndGetPrefetchedTerm() {
		$this->lookup->prefetchTerms(
			[ $this->p1, $this->p2 ],
			[ 'label', 'description', 'alias' ],
			[ 'en' ]
		);

		$label1 = $this->lookup->getPrefetchedTerm( $this->p1, 'label', 'en' );
		$this->assertSame( 'property one', $label1 );
		$description2 = $this->lookup->getPrefetchedTerm( $this->p2, 'description', 'en' );
		$this->assertSame( 'the second property', $description2 );
		$alias1 = $this->lookup->getPrefetchedTerm( $this->p1, 'alias', 'en' );
		$this->assertSame( 'P1', $alias1 );
	}

	public function testGetPrefetchedTerm_notPrefetched() {
		$this->assertNull( $this->lookup->getPrefetchedTerm( $this->p1, 'label', 'en' ) );
	}

	public function testGetPrefetchedTerm_doesNotExist() {
		$this->lookup->prefetchTerms(
			[ $this->p1, $this->p2 ],
			[ 'label' ],
			[ 'en', 'de' ]
		);

		$this->assertFalse( $this->lookup->getPrefetchedTerm( $this->p1, 'label', 'de' ) );
	}

	public function testPrefetchTerms_Empty() {
		$this->lookup->prefetchTerms( [], [], [] );
		$this->assertTrue( true ); // no error
	}

	public function testPrefetchTerms_SameTermsTwice() {
		$this->lookup->prefetchTerms( [ $this->p1 ], [ 'label', 'description', 'alias' ], [ 'en' ] );
		$this->lookup->prefetchTerms( [ $this->p1 ], [ 'label', 'description', 'alias' ], [ 'en' ] );
		$this->assertTrue( true ); // no error
	}

}