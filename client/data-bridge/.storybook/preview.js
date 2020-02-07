import { addDecorator, addParameters } from '@storybook/vue';
import { withA11y } from '@storybook/addon-a11y';
import extendVueEnvironment from '@/presentation/extendVueEnvironment';
import './storybook-global.scss';

addDecorator( withA11y );

addDecorator( () => ( {
	template: '<div class="wb-db-app"><story/></div>',
} ) );

addParameters( {
	docs: {
		inlineStories: true,
	},
} );

extendVueEnvironment(
	{
		resolve( languageCode ) {
			switch ( languageCode ) {
				case 'en':
					return { code: 'en', directionality: 'ltr' };
				case 'he':
					return { code: 'he', directionality: 'rtl' };
				default:
					return { code: languageCode, directionality: 'auto' };
			}
		},
	},
	{
		get: ( key ) => `⧼${key}⧽`,
	},
	{
		usePublish: true,
	},
	{
		getPageUrl( title, params ) {
			let url = `http://repo/${title}`;
			if ( params ) {
				url += '?' + new URLSearchParams( params ).toString();
			}
			return url;
		},
	},
	{
		getPageUrl( title, params ) {
			let url = `https://client.wiki.example/wiki/${title.replace( / /g, '_' )}`;
			if ( params ) {
				url += '?' + new URLSearchParams( params ).toString();
			}
			return url;
		},
	},
);