const assert = require( 'assert' ),
	Api = require( 'wdio-mediawiki/Api' ),
	DataBridgePage = require( '../pageobjects/dataBridge.page' ),
	WikibaseApi = require( 'wdio-wikibase/wikibase.api' );

function createTag( tagname, reason ) {
	let bot;
	return Api.bot( browser.config.username, browser.config.password )
		.then( ( mwBot ) => {
			bot = mwBot;
			return bot.getEditToken();
		} ).then( ( botState ) => {
			return bot.request( {
				action: 'managetags',
				operation: 'create',
				tag: tagname,
				reason,
				token: botState.csrftoken,
			} );
		} );
}

function getLatestRevisionTags( title ) {
	return Api.bot( browser.config.username, browser.config.password ).then( ( bot ) => {
		return bot.request( {
			titles: title,
			action: 'query',
			prop: 'revisions',
			rvprop: 'tags',
			formatversion: '2',
		} ).then( ( response ) => {
			return response.query.pages[ 0 ].revisions[ 0 ].tags;
		} );
	} );
}

function ensureTagExists( bridgeEditTag ) {
	browser.call(
		() => createTag( bridgeEditTag, 'created by selenium browser test' )
			.catch( ( reason ) => {
				if ( reason.code === 'tags-create-already-exists' ) {
					return;
				}
				throw new Error( reason );
			} )
	);
}

function assertSaveButtonDisabled( untilMessage = '' ) {
	let isSaveButtonClickable = true;
	try {
		DataBridgePage.saveButton.click(); // This should do exactly nothing because the button is disabled
	} catch ( e ) { // TODO though we catch this error, it still logs a scary message to the console :/
		isSaveButtonClickable = false;
	}
	assert.ok( !isSaveButtonClickable, 'Save button should not be clickable ' + untilMessage );
	assert.ok( !DataBridgePage.saveButton.isFocused(), 'disabled button should not be focusable' );
	assert.ok( !DataBridgePage.saveButton.isSelected(), 'disabled button should not be selectable' );
}

describe( 'App', () => {
	it( 'can save with tag', () => {
		const bridgeEditTag = 'Data Bridge';
		ensureTagExists( bridgeEditTag );

		const title = DataBridgePage.getDummyTitle();
		const propertyId = browser.call( () => WikibaseApi.getProperty( 'string' ) );
		const stringPropertyExampleValue = 'initialValue';
		const entityId = browser.call( () => WikibaseApi.createItem( 'data bridge browser test item', {
			'claims': [ {
				'mainsnak': {
					'snaktype': 'value',
					'property': propertyId,
					'datavalue': { 'value': stringPropertyExampleValue, 'type': 'string' },
				},
				'type': 'statement',
				'rank': 'normal',
			} ],
		} ) );
		const editFlow = 'overwrite';
		const content = `{|class="wikitable"
|-
| official website
| {{#statements:${propertyId}|from=${entityId}}}&nbsp;<span data-bridge-edit-flow="${editFlow}">[https://example.org/wiki/Item:${entityId}?uselang=en#${propertyId} Edit this on Wikidata]</span>
|}`;
		browser.call( () => Api.edit( title, content, browser.config.username, browser.config.password ) );

		DataBridgePage.openBridgeOnPage( title );

		DataBridgePage.bridge.waitForDisplayed( 5000 );
		assert.ok( DataBridgePage.bridge.isDisplayed() );
		assert.strictEqual( DataBridgePage.value.getValue(), stringPropertyExampleValue );

		assertSaveButtonDisabled( 'until value was changed' );

		const newValue = 'newValue';
		browser.waitUntil(
			() => {
				DataBridgePage.value.setValue( newValue );
				return DataBridgePage.value.getValue() === newValue;
			}
		);

		assertSaveButtonDisabled( 'until edit decision was made' );

		DataBridgePage.editDecision( 'replace' ).click();

		DataBridgePage.saveButton.click();

		DataBridgePage.app.waitForDisplayed(
			5000,
			true,
			'App is still being displayed after clicking the save button'
		);
		DataBridgePage.app.waitForExist( 5000, true, 'App still exists in the DOM after clicking the save button' );

		const entity = browser.call( () => WikibaseApi.getEntity( entityId ) );
		const actualValueAtServer = entity.claims[ propertyId ][ 0 ].mainsnak.datavalue.value;

		assert.strictEqual( actualValueAtServer, newValue );

		const actualTags = browser.call(
			() => getLatestRevisionTags( entity.title )
				.catch( assert.fail )
		);
		assert.ok( actualTags.includes( bridgeEditTag ), `${JSON.stringify( actualTags )} doesn't include tag "${bridgeEditTag}"!` );
	} );
} );