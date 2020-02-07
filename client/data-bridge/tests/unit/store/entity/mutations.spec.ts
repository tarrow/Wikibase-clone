import {
	ENTITY_UPDATE,
	ENTITY_REVISION_UPDATE,
} from '@/store/entity/mutationTypes';
import { EntityState } from '@/store/entity';
import EntityRevision from '@/datamodel/EntityRevision';
import newMockableEntityRevision from '../newMockableEntityRevision';
import newEntityState from './newEntityState';
import { inject } from 'vuex-smart-module';
import { EntityMutations } from '@/store/entity/mutations';

describe( 'entity/mutations', () => {
	describe( ENTITY_UPDATE, () => {
		it( 'contains entity data incl baseRevisionFingerprint after initialization', () => {
			const id = 'Q23';
			const state: EntityState = newEntityState();
			const entityRevision: EntityRevision = newMockableEntityRevision( { id, statements: {}, revisionId: 0 } );

			const mutations = inject( EntityMutations, { state } );

			mutations[ ENTITY_UPDATE ]( entityRevision.entity );
			expect( state.id ).toBe( entityRevision.entity.id );
		} );
	} );

	it( ENTITY_REVISION_UPDATE, () => {
		const state = newEntityState( { baseRevision: 0 } );
		const revision = 4711;

		const mutations = inject( EntityMutations, { state } );

		mutations[ ENTITY_REVISION_UPDATE ]( revision );
		expect( state.baseRevision ).toBe( revision );
	} );
} );