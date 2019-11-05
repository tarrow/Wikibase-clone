import Tracker from '@/definitions/Tracker';
import BridgeTracker from '@/definitions/data-access/BridgeTracker';

export default class DataBridgeTrackerService implements BridgeTracker {
	private readonly tracker: Tracker;

	public constructor( tracker: Tracker ) {
		this.tracker = tracker;
	}

	public trackPropertyDatatype( datatype: string ): void {
		this.tracker.increment( `counter.MediaWiki.wikibase.client.databridge.datatype.${datatype}` );
	}

}